<?php

namespace App\Support\Addons\Registry;

use App\Models\SystemAddon;
use App\Support\Addons\AddonDiscovery;
use App\Support\Addons\AddonEventLogger;
use App\Support\Addons\AddonLifecycle;
use App\Support\Addons\AddonRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        private readonly AddonEventLogger $events,
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
        if ($this->completedRecoveryExists($operationId)) {
            return $this->result(false, 'recovery_not_required', 'The addon operation was already recovered.');
        }
        if (in_array($assessment->classification, ['completed_consistent', 'rolled_back_consistent'], true)) {
            return $this->result(false, 'recovery_not_required', 'The addon operation is already consistent.');
        }
        if (! $assessment->automaticEligible) {
            return $this->result(false, 'automatic_recovery_not_allowed', 'Recovery requires manual intervention.');
        }
        $lock = Cache::lock('addon-install-operation:'.$assessment->code, 60);
        if (! $lock->get()) {
            return $this->result(false, 'recovery_operation_active', 'Another addon mutation is active.');
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
            $execution = $this->startRecoveryJournal($journal, $current, $actor);
            $this->events->warning($this->eventAddonCode($current->code), 'recovery_started', 'Interrupted addon recovery started.', $this->audit($execution));
            try {
                $this->executeRecovery($journal, $current, $execution);
                $this->transitionRecovery($execution, 'completed');
                $this->events->info($this->eventAddonCode($current->code), 'recovery_completed', 'Interrupted addon recovery completed.', $this->audit($execution));

                return $this->result(true, 'recovery_completed', 'Interrupted addon operation recovered safely.');
            } catch (\Throwable $exception) {
                $code = in_array($exception->getMessage(), $this->failureCodes(), true) ? $exception->getMessage() : 'manual_intervention_required';
                $execution['failure_code'] = $code;
                $this->transitionRecovery($execution, 'manual_intervention_required');
                $this->events->error($this->eventAddonCode($current->code), 'recovery_failed', 'Interrupted addon recovery requires manual intervention.', [...$this->audit($execution), 'code' => $code]);

                return $this->result(false, $code, 'Recovery evidence was preserved for manual intervention.');
            }
        } finally {
            $lock->release();
        }
    }

    public function plan(string $operationId): array
    {
        $assessment = $this->inspect($operationId);
        if ($assessment === null) {
            return $this->result(false, 'journal_invalid', 'Recovery journal was not found.');
        }

        return [
            'success' => $assessment->automaticEligible,
            'code' => $assessment->automaticEligible ? 'recovery_plan_ready' : 'automatic_recovery_not_allowed',
            'classification' => $assessment->classification,
            'action' => $assessment->proposedAction,
            'destructive' => $assessment->destructiveActionExpected,
            'fingerprint' => $assessment->fingerprint,
        ];
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
            return ['first_install_db_present_live_missing', 'remove_partial_record', true, []];
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
        $promotion = array_replace($this->promotionMetadataFromArtifact($journal), $this->promotionMetadataFromStaging($promotion), array_filter($promotion, static fn ($value): bool => $value !== null));
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

    private function executeRecovery(array $journal, AddonRecoveryAssessment $assessment, array &$execution): void
    {
        $paths = $this->operationPaths($journal);
        $classification = $assessment->classification;
        if (! in_array($classification, [
            'prepared_no_mutation', 'staged_only', 'candidate_only', 'backup_created_live_missing',
            'new_live_promoted_db_old', 'new_live_promoted_db_new', 'old_live_restored_db_new',
            'first_install_live_present_db_absent', 'first_install_db_present_live_missing', 'rollback_incomplete',
        ], true)) {
            throw new \RuntimeException('recovery_plan_invalid');
        }

        if (in_array($classification, ['prepared_no_mutation', 'staged_only', 'candidate_only'], true)) {
            $this->transitionRecovery($execution, 'cleaning_transient');
            if ($assessment->candidateEvidence->existence === 'present') {
                $this->deleteExactDirectory($paths['candidate']);
            }
            if ($assessment->stagingEvidence->existence === 'present') {
                $this->deleteExactDirectory($paths['staging']);
            }
            $this->transitionRecovery($execution, 'reconciled_interrupted');

            return;
        }

        if ($classification === 'new_live_promoted_db_new') {
            $this->transitionRecovery($execution, 'verifying');
            $this->assertLiveAndDb($journal, $paths['live'], $journal['target_version'] ?? null);
            $this->transitionRecovery($execution, 'reconciled_completed');

            return;
        }

        if ($classification === 'first_install_db_present_live_missing') {
            $this->transitionRecovery($execution, 'removing_partial_record');
            SystemAddon::query()->where('code', $journal['code'])->delete();
            if (SystemAddon::query()->where('code', $journal['code'])->exists()) {
                throw new \RuntimeException('recovery_registration_failed');
            }
            $this->transitionRecovery($execution, 'rolled_back');

            return;
        }

        if ($classification === 'first_install_live_present_db_absent') {
            $this->transitionRecovery($execution, 'preserving_partial_live');
            $safety = $this->safetyPath((string) $paths['live'], (string) $execution['recovery_id'], 'first-install');
            if (! is_dir((string) $paths['live']) || ! rename((string) $paths['live'], $safety)) {
                throw new \RuntimeException('recovery_cleanup_failed');
            }
            if (is_dir((string) $paths['live']) || SystemAddon::query()->where('code', $journal['code'])->exists()) {
                throw new \RuntimeException('recovery_verification_failed');
            }
            $this->transitionRecovery($execution, 'rolled_back');

            return;
        }

        if ($classification === 'old_live_restored_db_new') {
            $this->transitionRecovery($execution, 'registering_previous');
            $this->reconcilePreviousDb($journal, $paths['live']);
            $this->assertLiveAndDb($journal, $paths['live'], $journal['previous_version'] ?? null);
            $this->transitionRecovery($execution, 'rolled_back');

            return;
        }

        if ($classification === 'rollback_incomplete') {
            if ($assessment->liveEvidence->version === $journal['previous_version']) {
                $this->reconcilePreviousDb($journal, $paths['live']);
                $this->assertLiveAndDb($journal, $paths['live'], $journal['previous_version'] ?? null);
                $this->transitionRecovery($execution, 'rolled_back');

                return;
            }
            if ($assessment->liveEvidence->version === $journal['target_version'] && ($assessment->evidence['db_version'] ?? null) === $journal['target_version']) {
                $this->assertLiveAndDb($journal, $paths['live'], $journal['target_version'] ?? null);
                $this->transitionRecovery($execution, 'reconciled_completed');

                return;
            }
            if ($assessment->backupEvidence->integrity !== 'verified') {
                throw new \RuntimeException('recovery_plan_invalid');
            }
        }

        $this->restorePreviousTree($journal, $assessment, $paths, $execution);
    }

    private function restorePreviousTree(array $journal, AddonRecoveryAssessment $assessment, array $paths, array &$execution): void
    {
        $live = $paths['live'];
        $backup = $paths['backup'];
        if (! is_string($live) || ! is_string($backup) || $assessment->backupEvidence->integrity !== 'verified') {
            throw new \RuntimeException('recovery_plan_invalid');
        }
        $payload = $backup.'/payload';
        if (! is_dir($payload)) {
            throw new \RuntimeException('recovery_restore_failed');
        }
        $safety = null;
        if (is_dir($live)) {
            $this->transitionRecovery($execution, 'preserving_target');
            $safety = $this->safetyPath($live, (string) $execution['recovery_id'], 'target');
            if (! rename($live, $safety)) {
                throw new \RuntimeException('recovery_restore_failed');
            }
        }
        $this->transitionRecovery($execution, 'restoring_previous');
        if (! rename($payload, $live)) {
            if ($safety !== null && is_dir($safety) && ! rename($safety, $live)) {
                throw new \RuntimeException('recovery_compensation_failed');
            }
            throw new \RuntimeException('recovery_restore_failed');
        }
        try {
            $this->transitionRecovery($execution, 'discovering');
            $this->reconcilePreviousDb($journal, $live);
            $this->transitionRecovery($execution, 'verifying');
            $this->assertLiveAndDb($journal, $live, $journal['previous_version'] ?? null);
            $this->transitionRecovery($execution, 'rolled_back');
        } catch (\Throwable) {
            $failedPrevious = $this->safetyPath($live, (string) $execution['recovery_id'], 'failed-previous');
            $preserved = rename($live, $failedPrevious);
            $restored = $safety !== null && is_dir($safety) && rename($safety, $live);
            if (! $preserved || ! $restored) {
                throw new \RuntimeException('recovery_compensation_failed');
            }
            throw new \RuntimeException('recovery_registration_failed');
        }
    }

    private function operationPaths(array $journal): array
    {
        $promotion = $this->promotionJournal((string) ($journal['promotion_transaction_id'] ?? ''));
        $promotion = array_replace($this->promotionMetadataFromArtifact($journal), $this->promotionMetadataFromStaging($promotion), array_filter($promotion, static fn ($value): bool => $value !== null));
        $live = is_string($promotion['live_path'] ?? null) ? $promotion['live_path'] : $this->resolvedLivePath($this->registry->find((string) $journal['code']));
        $transaction = is_string($promotion['transaction_id'] ?? null) ? $promotion['transaction_id'] : null;
        $stagingDisk = Storage::disk((string) Config::get('addons-registry.staging.disk', 'addons'));

        return [
            'live' => $live,
            'backup' => is_string($promotion['backup_path'] ?? null) ? $promotion['backup_path'] : null,
            'candidate' => $live !== null && $transaction !== null ? dirname($live).'/.'.basename($live).'.promote-'.$transaction : null,
            'staging' => is_string($promotion['staging_path'] ?? null) ? $stagingDisk->path($promotion['staging_path']) : null,
        ];
    }

    private function startRecoveryJournal(array $source, AddonRecoveryAssessment $assessment, ArtifactReviewActor $actor): array
    {
        $journal = [
            'schema_version' => 1,
            'recovery_id' => (string) Str::uuid(),
            'source_operation_id' => $source['operation_id'],
            'code' => $source['code'],
            'classification' => $assessment->classification,
            'proposed_action' => $assessment->proposedAction,
            'evidence_fingerprint' => $assessment->fingerprint,
            'actor' => $actor->toArray(),
            'state' => 'recovering',
            'started_at' => now()->toIso8601String(),
            'steps' => [['state' => 'recovering', 'at' => now()->toIso8601String()]],
        ];
        $this->persistRecovery($journal);

        return $journal;
    }

    private function transitionRecovery(array &$journal, string $state): void
    {
        $journal['state'] = $state;
        $journal['steps'][] = ['state' => $state, 'at' => now()->toIso8601String()];
        if (in_array($state, ['completed', 'manual_intervention_required'], true)) {
            $journal['finished_at'] = now()->toIso8601String();
        }
        $this->persistRecovery($journal);
    }

    private function persistRecovery(array $journal): void
    {
        Storage::disk('addons')->put('addons/recovery-journal/'.$journal['code'].'/'.$journal['recovery_id'].'.json', json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function completedRecoveryExists(string $operationId): bool
    {
        foreach (Storage::disk('addons')->allFiles('addons/recovery-journal') as $path) {
            $journal = json_decode((string) Storage::disk('addons')->get($path), true);
            if (is_array($journal) && ($journal['source_operation_id'] ?? null) === $operationId && ($journal['state'] ?? null) === 'completed') {
                return true;
            }
        }

        return false;
    }

    private function assertLiveAndDb(array $journal, ?string $live, ?string $version): void
    {
        $db = $this->registry->find((string) $journal['code']);
        if ($version === null || $this->manifestVersion($live) !== $version || $db?->version !== $version) {
            throw new \RuntimeException('recovery_verification_failed');
        }
    }

    private function safetyPath(string $live, string $id, string $kind): string
    {
        return dirname($live).'/.'.basename($live).'.recovery-'.$kind.'-'.$id;
    }

    private function deleteExactDirectory(?string $path): void
    {
        if ($path === null || ! is_dir($path) || is_link($path)) {
            throw new \RuntimeException('recovery_cleanup_failed');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $ok = $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            if (! $ok) {
                throw new \RuntimeException('recovery_cleanup_failed');
            }
        }
        if (! rmdir($path)) {
            throw new \RuntimeException('recovery_cleanup_failed');
        }
    }

    private function failureCodes(): array
    {
        return ['recovery_plan_invalid', 'recovery_cleanup_failed', 'recovery_restore_failed', 'recovery_discovery_failed',
            'recovery_registration_failed', 'recovery_verification_failed', 'recovery_compensation_failed', 'manual_intervention_required'];
    }

    private function audit(array $journal): array
    {
        return array_intersect_key($journal, array_flip(['recovery_id', 'source_operation_id', 'code', 'classification', 'evidence_fingerprint', 'state']));
    }

    private function eventAddonCode(string $code): ?string
    {
        return SystemAddon::query()->where('code', $code)->exists() ? $code : null;
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

    private function promotionMetadataFromArtifact(array $journal): array
    {
        $code = $journal['code'] ?? null;
        $version = $journal['target_version'] ?? null;
        if (! is_string($code) || ! is_string($version)) {
            return [];
        }
        $disk = Storage::disk((string) Config::get('addons-registry.downloads.disk', 'addons'));
        $root = trim((string) Config::get('addons-registry.downloads.quarantine_path', 'addons/quarantine'), '/');
        $path = $root.'/'.$code.'/'.$version.'/metadata.json';
        $metadata = json_decode((string) ($disk->exists($path) ? $disk->get($path) : ''), true);
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
                if ($this->discovery->syncManifest($manifest, $type) === null) {
                    throw new \RuntimeException('recovery_discovery_failed');
                }
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
