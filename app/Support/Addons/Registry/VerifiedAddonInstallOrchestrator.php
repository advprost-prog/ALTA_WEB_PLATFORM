<?php

namespace App\Support\Addons\Registry;

use App\Models\SystemAddon;
use App\Support\Addons\AddonDiscovery;
use App\Support\Addons\AddonEventLogger;
use App\Support\Addons\AddonLifecycle;
use App\Support\Addons\AddonRegistry;
use App\Support\Addons\Marketplace\MarketplaceManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class VerifiedAddonInstallOrchestrator
{
    public function __construct(
        private readonly MarketplaceManager $marketplace,
        private readonly ArtifactPromotionManager $promotion,
        private readonly ArtifactStagingManager $staging,
        private readonly AddonDiscovery $discovery,
        private readonly AddonRegistry $registry,
        private readonly AddonLifecycle $lifecycle,
        private readonly AddonEventLogger $events,
    ) {}

    public function execute(string $code, ArtifactReviewActor $actor, bool $enable = false): VerifiedAddonInstallResult
    {
        $operationId = (string) Str::uuid();
        $before = $this->registry->find($code);
        $type = $before?->is_installed ? 'update' : 'install';
        if ($this->requiresManualIntervention($code)) {
            return $this->failure($code, null, $operationId, $type, 'manual_intervention_required', ['The addon has unresolved manual recovery evidence.']);
        }
        $lock = Cache::lock('addon-install-operation:'.$code, 60);
        if (! $lock->get()) {
            return $this->failure($code, null, $operationId, $type, 'operation_locked', ['Another addon operation is active.']);
        }

        $snapshot = $before?->getAttributes();
        $promotionResult = null;
        $journal = $this->initialJournal($operationId, $code, $type, $actor, $snapshot);

        try {
            $assessment = $this->marketplace->assessment($code);
            if (! is_array($assessment)) {
                return $this->failJournal($journal, $code, null, $type, 'assessment_changed', ['Marketplace assessment is unavailable.']);
            }
            $targetVersion = is_string($assessment['remoteVersion'] ?? null) ? $assessment['remoteVersion'] : null;
            $artifact = is_array($assessment['artifact'] ?? null) ? $assessment['artifact'] : [];
            $journal['target_version'] = $targetVersion;
            $journal['artifact_sha256'] = $artifact['sha256'] ?? null;
            $journal['publisher_public_id'] = $assessment['publisher']['public_id'] ?? null;
            $journal['signing_key_id'] = $assessment['signingKeyId'] ?? null;
            $journal['dependency_plan'] = $assessment['dependencies']['plan'] ?? [];
            $this->transition($journal, 'preflight_validated');

            if (($assessment['registryState'] ?? null) !== 'fresh') {
                return $this->failJournal($journal, $code, $targetVersion, $type, 'registry_not_fresh', ['Registry assessment is not fresh.']);
            }
            if (! ($assessment['identity']['consistent'] ?? false)) {
                return $this->failJournal($journal, $code, $targetVersion, $type, 'assessment_changed', ['Addon identity is conflicted.']);
            }
            if (($assessment['compatibility']['result'] ?? null) !== 'compatible') {
                return $this->failJournal($journal, $code, $targetVersion, $type, 'assessment_changed', ['Target is not platform compatible.']);
            }
            $dependencyBlocker = $this->dependencyBlocker((array) ($assessment['dependencies'] ?? []));
            if ($dependencyBlocker !== null) {
                return $this->failJournal($journal, $code, $targetVersion, $type, $dependencyBlocker, ['Required dependency operations are not completed.']);
            }
            if ($targetVersion === null) {
                return $this->failJournal($journal, $code, null, $type, 'assessment_changed', ['Remote target version is unavailable.']);
            }
            if ($type === 'update' && version_compare((string) $before->version, $targetVersion, '>=')) {
                return $this->failJournal($journal, $code, $targetVersion, $type, 'assessment_changed', ['Update target must be strictly newer.']);
            }
            $current = $this->registry->find($code);
            if ($type === 'update' && ($current === null || $current->version !== ($snapshot['version'] ?? null))) {
                return $this->failJournal($journal, $code, $targetVersion, $type, 'preflight_changed', ['Installed addon state changed after operation preparation.']);
            }
            if ($type === 'install' && $current?->is_installed) {
                return $this->failJournal($journal, $code, $targetVersion, $type, 'preflight_changed', ['Addon became installed after operation preparation.']);
            }

            $journal['previous_version'] = $before?->version;
            $journal['previous_enabled'] = (bool) $before?->is_enabled;
            $this->writeJournal($journal);

            if ($before?->is_enabled) {
                $this->lifecycle->disable($code);
            }

            $this->transition($journal, 'promoting');
            $promotionResult = $this->promotion->promote($code, $actor);
            if (! $promotionResult->success) {
                $this->restoreRecord($code, $snapshot);

                return $this->failJournal($journal, $code, $targetVersion, $type, 'promotion_failed', $promotionResult->diagnostics);
            }
            $journal['promotion_transaction_id'] = $promotionResult->transactionId;
            $journal['backup_available'] = $promotionResult->backupPath !== null;
            $this->transition($journal, 'promoted');

            $this->transition($journal, 'discovering');
            $manifestPath = collect(['module.json', 'extension.json', 'manifest.json'])
                ->map(fn (string $name): string => rtrim((string) $promotionResult->livePath, '/').'/'.$name)
                ->first(fn (string $path): bool => is_file($path));
            if (! is_string($manifestPath)) {
                throw new InstallOperationException('discovery_failed', 'Promoted manifest is missing.');
            }
            $this->discovery->syncManifest($manifestPath, (string) $promotionResult->addonType);
            $discovered = $this->registry->find($code);
            if ($discovered === null || $discovered->version !== $targetVersion || $discovered->code !== $code) {
                throw new InstallOperationException('discovery_failed', 'Promoted addon identity was not discovered.');
            }

            $this->transition($journal, 'registering');
            $installed = $this->lifecycle->install($code);
            if ($installed->version !== $targetVersion || ! $installed->is_installed) {
                throw new InstallOperationException('registration_failed', 'Local addon registration did not match the target.');
            }

            $shouldEnable = $type === 'update' ? (bool) ($snapshot['is_enabled'] ?? false) : $enable;
            if ($shouldEnable) {
                $this->transition($journal, 'enabling');
                $installed = $this->lifecycle->enable($code);
            }

            $this->verifyInstalled($installed, $targetVersion, $promotionResult);
            $cleanup = $this->staging->unstage($code, 'Install operation completed', $actor);
            if (! $cleanup->success) {
                throw new InstallOperationException('cleanup_failed', 'Staging cleanup failed.');
            }
            $this->transition($journal, 'completed');
            $journal['completed_at'] = now()->toIso8601String();
            $this->writeJournal($journal);
            $this->events->info($code, 'verified_addon_install_completed', 'Verified addon install operation completed.', [
                'operation_id' => $operationId, 'operation_type' => $type, 'target_version' => $targetVersion,
                'artifact_sha256' => $journal['artifact_sha256'],
            ]);

            return new VerifiedAddonInstallResult(true, $code, $targetVersion, $operationId, $type, 'completed', enabled: $installed->is_enabled);
        } catch (InstallOperationException $exception) {
            return $this->compensate($journal, $code, $type, $snapshot, $promotionResult, $actor, $exception->failureCode, [$exception->getMessage()]);
        } catch (\Throwable) {
            return $this->compensate($journal, $code, $type, $snapshot, $promotionResult, $actor, 'post_install_verification_failed', ['Install operation failed.']);
        } finally {
            $lock->release();
        }
    }

    private function requiresManualIntervention(string $code): bool
    {
        foreach (Storage::disk('addons')->allFiles('addons/recovery-journal/'.$code) as $path) {
            $journal = json_decode((string) Storage::disk('addons')->get($path), true);
            if (is_array($journal) && ($journal['state'] ?? null) === 'manual_intervention_required') {
                return true;
            }
        }

        return false;
    }

    private function compensate(array $journal, string $code, string $type, ?array $snapshot, ?ArtifactPromotionResult $promotion, ArtifactReviewActor $actor, string $failureCode, array $diagnostics): VerifiedAddonInstallResult
    {
        $rolledBack = false;
        if ($promotion?->success) {
            $this->transition($journal, 'rolling_back');
            $rollback = $this->promotion->rollback($code, $promotion->transactionId, 'Foreground install compensation', $actor);
            $rolledBack = $rollback->success;
            if (! $rolledBack) {
                $failureCode = 'rollback_failed';
                $diagnostics = ['Foreground rollback failed; journal and backup retained.'];
            }
        }
        $this->restoreRecord($code, $snapshot);
        $journal['state'] = $rolledBack ? 'rolled_back' : 'failed';
        $journal['failure_code'] = $failureCode;
        $journal['failed_at'] = now()->toIso8601String();
        $journal['diagnostics'] = $diagnostics;
        $this->writeJournal($journal);

        return new VerifiedAddonInstallResult(false, $code, $journal['target_version'] ?? null, $journal['operation_id'], $type, $journal['state'], $failureCode, $diagnostics, rolledBack: $rolledBack);
    }

    private function restoreRecord(string $code, ?array $snapshot): void
    {
        if ($snapshot === null) {
            SystemAddon::query()->where('code', $code)->delete();

            return;
        }
        SystemAddon::query()->updateOrCreate(['code' => $code], $snapshot);
    }

    private function verifyInstalled(SystemAddon $addon, string $version, ArtifactPromotionResult $promotion): void
    {
        if (! $addon->is_installed || $addon->version !== $version || ! is_string($promotion->livePath) || ! is_dir($promotion->livePath)) {
            throw new InstallOperationException('post_install_verification_failed', 'Installed addon post-check failed.');
        }
        $manifestPath = base_path((string) $addon->manifest_path);
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
        if (! is_array($manifest) || ($manifest['code'] ?? null) !== $addon->code || ($manifest['version'] ?? null) !== $version) {
            throw new InstallOperationException('post_install_verification_failed', 'Live manifest post-check failed.');
        }
    }

    private function dependencyBlocker(array $report): ?string
    {
        foreach ((array) ($report['nodes'] ?? []) as $node) {
            if (! ($node['required'] ?? true)) {
                continue;
            }
            if (($node['state'] ?? null) !== 'satisfied_installed') {
                return match ($node['state'] ?? null) {
                    'cycle' => 'dependency_cycle',
                    'installed_version_mismatch' => 'dependency_version_mismatch',
                    default => 'dependency_missing',
                };
            }
        }

        return null;
    }

    private function initialJournal(string $id, string $code, string $type, ArtifactReviewActor $actor, ?array $snapshot): array
    {
        return ['schema_version' => 1, 'operation_id' => $id, 'code' => $code, 'operation_type' => $type,
            'state' => 'prepared', 'actor' => $actor->toArray(), 'started_at' => now()->toIso8601String(),
            'previous_version' => $snapshot['version'] ?? null, 'previous_enabled' => (bool) ($snapshot['is_enabled'] ?? false),
            'steps' => [['state' => 'prepared', 'at' => now()->toIso8601String()]], 'diagnostics' => []];
    }

    private function transition(array &$journal, string $state): void
    {
        $journal['state'] = $state;
        $journal['steps'][] = ['state' => $state, 'at' => now()->toIso8601String()];
        $this->writeJournal($journal);
    }

    private function failJournal(array $journal, string $code, ?string $version, string $type, string $failureCode, array $diagnostics): VerifiedAddonInstallResult
    {
        $journal['state'] = 'failed';
        $journal['failure_code'] = $failureCode;
        $journal['failed_at'] = now()->toIso8601String();
        $journal['diagnostics'] = $diagnostics;
        $this->writeJournal($journal);

        return $this->failure($code, $version, $journal['operation_id'], $type, $failureCode, $diagnostics);
    }

    private function failure(string $code, ?string $version, string $id, string $type, string $failureCode, array $diagnostics): VerifiedAddonInstallResult
    {
        return new VerifiedAddonInstallResult(false, $code, $version, $id, $type, 'failed', $failureCode, $diagnostics);
    }

    private function writeJournal(array $journal): void
    {
        Storage::disk('addons')->put('addons/install-journal/'.$journal['code'].'/'.$journal['operation_id'].'.json', json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
