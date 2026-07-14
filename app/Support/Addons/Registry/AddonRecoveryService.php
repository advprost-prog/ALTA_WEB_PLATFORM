<?php

namespace App\Support\Addons\Registry;

use App\Models\SystemAddon;
use App\Support\Addons\AddonDiscovery;
use App\Support\Addons\AddonLifecycle;
use App\Support\Addons\AddonRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

final class AddonRecoveryService
{
    public function __construct(
        private readonly ArtifactPromotionManager $promotion,
        private readonly AddonDiscovery $discovery,
        private readonly AddonRegistry $registry,
        private readonly AddonLifecycle $lifecycle,
        private readonly BackupIntegrityService $backups,
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

        if (in_array($state, ['prepared', 'preflight_validated'], true) && $live === $previous && $db === $previous) {
            return ['prepared_no_mutation', 'mark_interrupted', true, []];
        }
        if ($state === 'staged' && $live === $previous && $db === $previous) {
            return ['staged_only', 'retain_for_retry', true, []];
        }
        if ($live === $target && $db === $previous && ($previous === null || $backupValid)) {
            return [$previous === null ? 'first_install_live_present_db_absent' : 'new_live_promoted_db_old', 'rollback_previous', true, []];
        }
        if ($live === $target && $db === $target) {
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
        $promotion = $this->promotion->getPromotionReport($code);
        $livePath = is_string($promotion['live_path'] ?? null) ? $promotion['live_path'] : null;
        $liveVersion = $this->manifestVersion($livePath);
        $db = $this->registry->find($code);
        $backupPath = is_string($promotion['backup_path'] ?? null) ? $promotion['backup_path'] : null;
        $backupVersion = $this->manifestVersion($backupPath);
        $backupIntegrity = $backupPath === null ? ['valid' => false, 'status' => 'missing', 'diagnostics' => ['backup_record_missing']] : $this->backups->verify($backupPath);
        $evidence = [
            'journal_state' => $journal['state'] ?? 'invalid', 'previous_version' => $journal['previous_version'] ?? null,
            'target_version' => $journal['target_version'] ?? null, 'live_version' => $liveVersion,
            'db_version' => $db?->version, 'db_enabled' => (bool) $db?->is_enabled,
            'backup_valid' => ($backupIntegrity['valid'] ?? false) && $backupVersion !== null && $backupVersion === ($journal['previous_version'] ?? null),
            'backup_version' => $backupVersion, 'promotion_transaction_id' => $journal['promotion_transaction_id'] ?? null,
            'backup_integrity_status' => $backupIntegrity['status'] ?? 'missing',
        ];
        [$classification, $action, $automatic, $diagnostics] = $this->classifyEvidence($evidence);
        if (in_array($evidence['backup_integrity_status'], ['corrupt', 'invalid', 'unmanaged', 'symlink_conflict'], true)) {
            [$classification, $action, $automatic, $diagnostics] = ['integrity_failure', 'manual_intervention', false, $backupIntegrity['diagnostics']];
        }
        if ($evidence['backup_integrity_status'] === 'legacy_unverified' && in_array($classification, ['backup_created_live_missing', 'new_live_promoted_db_old'], true)) {
            [$classification, $action, $automatic, $diagnostics] = ['integrity_failure', 'manual_intervention', false, ['backup_legacy_unverified']];
        }
        $fingerprint = hash('sha256', json_encode($evidence, JSON_UNESCAPED_SLASHES));

        return new AddonRecoveryAssessment((string) $journal['operation_id'], $code, (string) ($journal['operation_type'] ?? 'install'),
            (string) ($journal['state'] ?? 'invalid'), $journal['previous_version'] ?? null, $journal['target_version'] ?? null,
            $classification, $action, $automatic, $diagnostics, $fingerprint, $evidence);
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
