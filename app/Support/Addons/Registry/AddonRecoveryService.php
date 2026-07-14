<?php

namespace App\Support\Addons\Registry;

use App\Models\SystemAddon;
use App\Support\Addons\AddonDiscovery;
use App\Support\Addons\AddonLifecycle;
use App\Support\Addons\AddonRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

final class AddonRecoveryService
{
    public function __construct(
        private readonly ArtifactPromotionManager $promotion,
        private readonly AddonDiscovery $discovery,
        private readonly AddonRegistry $registry,
        private readonly AddonLifecycle $lifecycle,
        private readonly BackupIntegrityService $backups,
        private readonly ManagedTreeEvidenceBuilder $trees,
        private readonly AddonLivePathResolver $livePaths,
    ) {}

    /** @return list<AddonRecoveryAssessment> */
    public function scan(): array
    {
        $assessments = [];
        foreach (Storage::disk('addons')->allFiles('addons/install-journal') as $path) {
            $journal = json_decode((string) Storage::disk('addons')->get($path), true);
            if (! is_array($journal) || ! is_string($journal['operation_id'] ?? null) || ! is_string($journal['code'] ?? null)) {
                continue;
            }
            if (in_array($journal['state'] ?? null, ['completed', 'rolled_back', 'reconciled_failed'], true)) {
                continue;
            }
            $assessments[] = $this->inspectJournal($journal);
        }

        return $assessments;
    }

    public function inspect(string $operationId): ?AddonRecoveryAssessment
    {
        foreach (Storage::disk('addons')->allFiles('addons/install-journal') as $path) {
            $journal = json_decode((string) Storage::disk('addons')->get($path), true);
            if (is_array($journal) && ($journal['operation_id'] ?? null) === $operationId) {
                return $this->inspectJournal($journal);
            }
        }

        return null;
    }

    public function recover(string $operationId, string $expectedFingerprint, ArtifactReviewActor $actor): array
    {
        $assessment = $this->inspect($operationId);
        if ($assessment === null) {
            return $this->result(false, 'journal_invalid', 'Recovery journal was not found.');
        }
        if (! $assessment->automaticEligible) {
            return $this->result(false, 'automatic_recovery_not_allowed', 'Recovery requires manual intervention.');
        }

        return $this->result(false, 'automatic_recovery_not_implemented', 'Operational recovery is reserved for I6A2.');

        /* I6A2 recovery implementation intentionally remains unreachable until its policy is implemented. */
        $lock = Cache::lock('addon-install-operation:'.$assessment->code, 60);
        if (! $lock->get()) {
            return $this->result(false, 'recovery_lock_unavailable', 'Addon operation lock is active.');
        }
        try {
            $current = $this->inspect($operationId);
            if ($current === null || ! hash_equals($expectedFingerprint, $current->fingerprint)) {
                return $this->result(false, 'recovery_state_changed', 'Recovery evidence changed after inspection.');
            }
            $journal = $this->journal($operationId);
            if ($journal === null) {
                return $this->result(false, 'journal_invalid', 'Recovery journal is invalid.');
            }
            $journal['state'] = 'recovering';
            $this->persist($journal);

            if (in_array($current->classification, ['prepared_no_mutation', 'staged_only'], true)) {
                $journal['state'] = 'reconciled_failed';
                $journal['failure_code'] = 'operation_interrupted';
                $journal['recovered_at'] = now()->toIso8601String();
                $this->persist($journal);

                return $this->result(true, 'recovery_completed', 'Interrupted operation reconciled without filesystem mutation.');
            }
            if (in_array($current->classification, ['new_live_promoted_db_old', 'first_install_live_present_db_absent'], true)) {
                $transaction = (string) ($journal['promotion_transaction_id'] ?? '');
                $rollback = $this->promotion->rollback($current->code, $transaction, 'Crash recovery', $actor);
                if (! $rollback->success) {
                    return $this->manual($journal, 'recovery_restore_failed');
                }
                $this->reconcilePreviousDb($journal, $rollback->livePath);
                $journal['state'] = 'rolled_back';
                $journal['recovered_at'] = now()->toIso8601String();
                $this->persist($journal);

                return $this->result(true, 'recovery_completed', 'Previous local state restored.');
            }
            if ($current->classification === 'new_live_promoted_db_new') {
                $journal['state'] = 'completed';
                $journal['completed_at'] = now()->toIso8601String();
                $this->persist($journal);

                return $this->result(true, 'recovery_completed', 'Consistent target state marked completed without boot.');
            }

            return $this->manual($journal, 'manual_intervention_required');
        } finally {
            $lock->release();
        }
    }

    public function classifyEvidence(array $evidence): array
    {
        $state = (string) ($evidence['journal_state'] ?? 'invalid');
        $live = $evidence['live_version'] ?? null;
        $db = $evidence['db_version'] ?? null;
        $previous = $evidence['previous_version'] ?? null;
        $target = $evidence['target_version'] ?? null;
        $backupValid = (bool) ($evidence['backup_valid'] ?? false);

        if ($state === 'invalid' || ! is_string($evidence['target_version'] ?? null)) {
            return ['ambiguous_conflict', 'manual_intervention', false, ['journal_invalid']];
        }

        foreach (['live', 'backup', 'candidate', 'staging'] as $tree) {
            $status = $evidence[$tree.'_status'] ?? null;
            if (in_array($status, ['unmanaged', 'symlink_conflict'], true)) {
                return ['unmanaged_path_conflict', 'manual_intervention', false, ['unmanaged_path_conflict']];
            }
            if ($status === 'integrity_failed') {
                return ['integrity_failure', 'manual_intervention', false, [$tree.'_integrity_failed']];
            }
            if ($status === 'ownership_mismatch') {
                return ['unmanaged_path_conflict', 'manual_intervention', false, ['ownership_mismatch']];
            }
        }

        if ($state === 'completed' && $live === $target && $db === $target && ($previous === null || $backupValid) && ! ($evidence['candidate_exists'] ?? false) && ! ($evidence['staging_exists'] ?? false)) {
            return ['completed_consistent', 'none', false, []];
        }
        if ($state === 'rolled_back' && $live === $previous && $db === $previous && ! ($evidence['candidate_verified'] ?? false)) {
            return ['rolled_back_consistent', 'none', false, []];
        }
        if ($state === 'promoting' && ($evidence['candidate_verified'] ?? false) && $live === $previous && $db === $previous && ! $backupValid) {
            return ['candidate_only', 'mark_interrupted', true, []];
        }
        if (in_array($state, ['rolling_back', 'recovering'], true)) {
            $targets = count(array_filter([$live === $previous, $live === $target, $backupValid]));

            return ['rollback_incomplete', $targets === 1 ? 'restore_deterministic_tree' : 'manual_intervention', $targets === 1, $targets === 1 ? [] : ['ambiguous_recovery_state']];
        }
        if ($live === $previous && $db === $target && $previous !== null) {
            return ['old_live_restored_db_new', 'restore_previous_database', true, []];
        }

        if (in_array($state, ['prepared', 'preflight_validated'], true) && $live === $previous && $db === $previous && ! ($evidence['candidate_verified'] ?? false) && ! $backupValid) {
            return ['prepared_no_mutation', 'mark_interrupted', true, []];
        }
        if ($state === 'staged' && ($evidence['staging_verified'] ?? false) && ! ($evidence['candidate_verified'] ?? false) && $live === $previous && $db === $previous) {
            return ['staged_only', 'retain_for_retry', true, []];
        }
        if ($live === $target && $db === $previous && ($previous === null || $backupValid)) {
            return [$previous === null ? 'first_install_live_present_db_absent' : 'new_live_promoted_db_old', 'rollback_previous', true, []];
        }
        if ($live === $target && $db === $target && ($previous === null || $backupValid)) {
            return ['new_live_promoted_db_new', 'complete_metadata_verification', true, []];
        }
        if ($live === null && $db === $target && $previous === null) {
            return ['first_install_db_present_live_missing', 'manual_intervention', false, ['live_missing']];
        }
        if ($live === null && $backupValid && $db === $previous) {
            return ['backup_created_live_missing', 'restore_backup', true, []];
        }

        return ['ambiguous_conflict', 'manual_intervention', false, ['ambiguous_recovery_state']];
    }

    private function inspectJournal(array $journal): AddonRecoveryAssessment
    {
        $code = (string) $journal['code'];
        $db = $this->registry->find($code);
        $promotion = $this->promotionJournal((string) ($journal['promotion_transaction_id'] ?? ''));
        $promotion = array_replace($this->promotionMetadataFromStaging($promotion), array_filter($promotion, static fn ($value): bool => $value !== null));
        $type = (string) ($promotion['addon_type'] ?? $db?->type ?? 'module');
        $liveRoot = rtrim((string) Config::get('addons-registry.live_roots.'.($type === 'extension' ? 'extensions_path' : 'modules_path'), base_path($type === 'extension' ? 'extensions' : 'modules')), '/');
        $backupRoot = Storage::disk((string) Config::get('addons-registry.promotion.backup_disk', 'addons'))->path(trim((string) Config::get('addons-registry.promotion.backup_path', 'addons/backups'), '/'));
        $stagingDisk = (string) Config::get('addons-registry.staging.disk', 'addons');
        $stagingRoot = Storage::disk($stagingDisk)->path(trim((string) Config::get('addons-registry.staging.path', 'addons/staging'), '/'));
        $livePath = is_string($promotion['live_path'] ?? null) ? $promotion['live_path'] : $this->resolvedLivePath($db);
        $transaction = is_string($promotion['transaction_id'] ?? null) ? $promotion['transaction_id'] : null;
        $candidatePath = $livePath !== null && $transaction !== null ? dirname($livePath).'/.'.basename($livePath).'.promote-'.$transaction : null;
        $expected = ['code' => $code, 'operation_id' => $transaction];
        $liveEvidence = $this->trees->inspect('live', $livePath, $liveRoot, ['code' => $code]);
        $backupEvidence = $this->trees->inspect('backup', is_string($promotion['backup_path'] ?? null) ? $promotion['backup_path'] : null, $backupRoot, $expected);
        $candidateEvidence = $this->trees->inspect('candidate', $candidatePath, $liveRoot, $expected);
        $stagingPath = is_string($promotion['staging_path'] ?? null) ? Storage::disk($stagingDisk)->path($promotion['staging_path']) : null;
        $stagingEvidence = $this->trees->inspect('staging', $stagingPath, $stagingRoot, $expected);
        $evidence = [
            'journal_state' => $journal['state'] ?? 'invalid', 'previous_version' => $journal['previous_version'] ?? null,
            'target_version' => $journal['target_version'] ?? null, 'live_version' => $liveEvidence->version,
            'db_version' => $db?->version, 'db_enabled' => (bool) $db?->is_enabled,
            'backup_valid' => $backupEvidence->integrity === 'verified' && $backupEvidence->version === ($journal['previous_version'] ?? null),
            'backup_version' => $backupEvidence->version, 'promotion_transaction_id' => $journal['promotion_transaction_id'] ?? null,
            'candidate_verified' => $candidateEvidence->integrity === 'verified' && $candidateEvidence->ownership === 'managed',
            'staging_verified' => $stagingEvidence->integrity === 'verified' && $stagingEvidence->ownership === 'managed',
            'candidate_exists' => $candidateEvidence->existence === 'present', 'staging_exists' => $stagingEvidence->existence === 'present',
        ];
        foreach (compact('liveEvidence', 'backupEvidence', 'candidateEvidence', 'stagingEvidence') as $name => $tree) {
            $prefix = substr($name, 0, -8);
            $evidence[$prefix.'_status'] = $tree->ownership !== 'managed' && $tree->ownership !== 'not_applicable' ? $tree->ownership : $tree->integrity;
        }
        [$classification, $action, $automatic, $diagnostics] = $this->classifyEvidence($evidence);
        $securityEvidence = ['journal' => array_intersect_key($evidence, array_flip(['journal_state', 'previous_version', 'target_version', 'db_version', 'db_enabled']))];
        foreach (compact('liveEvidence', 'backupEvidence', 'candidateEvidence', 'stagingEvidence') as $name => $tree) {
            $securityEvidence[$name] = array_diff_key($tree->toArray(), array_flip(['diagnosticCode', 'diagnosticMessage', 'fileCount', 'totalBytes']));
        }
        $fingerprint = hash('sha256', json_encode($securityEvidence, JSON_UNESCAPED_SLASHES));
        $manualReasons = $automatic ? [] : $diagnostics;

        return new AddonRecoveryAssessment((string) $journal['operation_id'], $code, (string) ($journal['operation_type'] ?? 'install'),
            (string) ($journal['state'] ?? 'invalid'), $journal['previous_version'] ?? null, $journal['target_version'] ?? null,
            $classification, $action, $automatic, in_array($action, ['rollback_previous', 'restore_backup', 'restore_deterministic_tree'], true),
            $manualReasons, $diagnostics, $fingerprint, $evidence, $liveEvidence, $backupEvidence, $candidateEvidence, $stagingEvidence);
    }

    private function promotionJournal(string $transactionId): array
    {
        if ($transactionId === '') {
            return [];
        }
        $disk = Storage::disk((string) Config::get('addons-registry.promotion.journal_disk', 'addons'));
        $root = trim((string) Config::get('addons-registry.promotion.journal_path', 'addons/promotion-journal'), '/');
        foreach ($disk->allFiles($root) as $path) {
            $journal = json_decode((string) $disk->get($path), true);
            if (is_array($journal) && ($journal['transaction_id'] ?? null) === $transactionId) {
                return $journal;
            }
        }

        return [];
    }

    private function promotionMetadataFromStaging(array $promotion): array
    {
        $stagingPath = $promotion['staging_path'] ?? null;
        if (! is_string($stagingPath)) {
            return [];
        }
        $disk = Storage::disk((string) Config::get('addons-registry.staging.disk', 'addons'));
        $staging = json_decode((string) ($disk->exists($stagingPath.'/staging.json') ? $disk->get($stagingPath.'/staging.json') : ''), true);
        $source = is_array($staging) ? ($staging['source']['quarantine_path'] ?? null) : null;
        if (! is_string($source)) {
            return [];
        }
        $metadataPath = dirname($source).'/metadata.json';
        $metadata = json_decode((string) ($disk->exists($metadataPath) ? $disk->get($metadataPath) : ''), true);
        if (! is_array($metadata)) {
            return [];
        }

        return [
            'live_path' => $metadata['promotion_live_path'] ?? null,
            'backup_path' => $metadata['promotion_backup_path'] ?? null,
            'transaction_id' => $metadata['promotion_transaction_id'] ?? null,
        ];
    }

    private function resolvedLivePath(?SystemAddon $addon): ?string
    {
        if ($addon === null) {
            return null;
        }
        try {
            return $this->livePaths->resolve(['code' => $addon->code, 'type' => $addon->type, 'vendor' => $addon->vendor])['live_path'];
        } catch (\Throwable) {
            return null;
        }
    }

    private function manifestVersion(?string $directory): ?string
    {
        if ($directory === null || ! is_dir($directory) || is_link($directory)) {
            return null;
        }
        foreach (['module.json', 'extension.json', 'manifest.json'] as $name) {
            $path = $directory.'/'.$name;
            $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (is_array($manifest) && is_string($manifest['version'] ?? null)) {
                return $manifest['version'];
            }
        }

        return null;
    }

    private function reconcilePreviousDb(array $journal, ?string $livePath): void
    {
        $code = (string) $journal['code'];
        if (($journal['previous_version'] ?? null) === null) {
            SystemAddon::query()->where('code', $code)->delete();

            return;
        }
        if (is_string($livePath)) {
            $manifest = collect(['module.json', 'extension.json', 'manifest.json'])->map(fn ($name) => $livePath.'/'.$name)->first(fn ($path) => is_file($path));
            if (is_string($manifest)) {
                $type = str_contains($manifest, 'module.json') ? 'module' : 'extension';
                $this->discovery->syncManifest($manifest, $type);
            }
        }
        $addon = $this->registry->find($code);
        if ($addon !== null) {
            $this->lifecycle->install($code);
            if (($journal['previous_enabled'] ?? false) === true) {
                try {
                    $this->lifecycle->enable($code);
                } catch (\Throwable) {
                    $this->lifecycle->disable($code);
                }
            }
        }
    }

    private function journal(string $id): ?array
    {
        foreach (Storage::disk('addons')->allFiles('addons/install-journal') as $path) {
            $journal = json_decode((string) Storage::disk('addons')->get($path), true);
            if (is_array($journal) && ($journal['operation_id'] ?? null) === $id) {
                return $journal;
            }
        }

        return null;
    }

    private function persist(array $journal): void
    {
        Storage::disk('addons')->put('addons/install-journal/'.$journal['code'].'/'.$journal['operation_id'].'.json', json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function manual(array $journal, string $code): array
    {
        $journal['state'] = 'manual_intervention_required';
        $journal['failure_code'] = $code;
        $this->persist($journal);

        return $this->result(false, $code, 'Recovery evidence was preserved for manual intervention.');
    }

    private function result(bool $success, string $code, string $message): array
    {
        return ['success' => $success, 'code' => $code, 'message' => $message];
    }
}
