<?php

namespace App\Support\Addons\Registry;

use App\Models\SystemAddon;
use App\Support\Addons\AddonDiscovery;
use App\Support\Addons\AddonEventLogger;
use App\Support\Addons\AddonLifecycle;
use App\Support\Addons\AddonRegistry;
use App\Support\Addons\PlatformVersion;
use App\Support\Addons\Version\VersionComparator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AddonOperationalRollbackService
{
    public function __construct(
        private readonly AddonRecoveryService $recovery,
        private readonly BackupIntegrityService $backups,
        private readonly AddonDiscovery $discovery,
        private readonly AddonRegistry $registry,
        private readonly AddonLifecycle $lifecycle,
        private readonly VersionComparator $versions,
        private readonly PlatformVersion $platform,
        private readonly AddonEventLogger $events,
    ) {}

    public function assess(string $code, ?string $sourceOperationId = null): array
    {
        $sources = $this->completedUpdates($code);
        if ($sourceOperationId !== null) {
            $sources = array_values(array_filter($sources, fn (array $journal): bool => $journal['operation_id'] === $sourceOperationId));
        }
        if (count($sources) !== 1) {
            return $this->result(false, count($sources) > 1 ? 'rollback_source_ambiguous' : 'rollback_source_not_eligible', 'Exactly one completed update must be selected.');
        }
        $source = $sources[0];
        $assessment = $this->recovery->inspect((string) $source['operation_id']);
        if ($assessment === null || $assessment->classification !== 'completed_consistent' || $assessment->operationType !== 'update') {
            return $this->result(false, 'rollback_source_not_eligible', 'The selected operation is not a consistent completed update.');
        }
        if ($assessment->liveEvidence->integrity !== 'verified' || $assessment->liveEvidence->version !== $source['target_version']) {
            return $this->result(false, 'rollback_current_integrity_failed', 'Current live evidence is not the verified update target.');
        }
        if ($assessment->backupEvidence->integrity !== 'verified' || $assessment->backupEvidence->version !== $source['previous_version']) {
            return $this->result(false, 'rollback_backup_invalid', 'Previous version backup is not verified.');
        }
        $manifest = $this->backupManifest($assessment);
        if ($manifest === null) {
            return $this->result(false, 'rollback_backup_invalid', 'Rollback target manifest is invalid.');
        }
        $blocking = $this->dependencyBlockers($code, (string) $source['previous_version'], $manifest);
        if ($blocking !== []) {
            return [...$this->result(false, 'rollback_dependency_blocked', 'Rollback dependency preflight failed.'), 'blocking' => $blocking];
        }
        $fingerprint = hash('sha256', json_encode([
            'source' => $source['operation_id'], 'code' => $code, 'current' => $source['target_version'], 'target' => $source['previous_version'],
            'assessment' => $assessment->fingerprint, 'dependencies' => $blocking,
        ], JSON_UNESCAPED_SLASHES));

        return [...$this->result(true, 'rollback_plan_ready', 'Completed update can be rolled back safely.'),
            'source_operation_id' => $source['operation_id'], 'current_version' => $source['target_version'],
            'target_version' => $source['previous_version'], 'fingerprint' => $fingerprint, 'blocking' => []];
    }

    public function rollback(string $code, ?string $sourceOperationId, string $expectedFingerprint, ArtifactReviewActor $actor): array
    {
        $initial = $this->assess($code, $sourceOperationId);
        if (! $initial['success']) {
            return $initial;
        }
        if (! hash_equals($expectedFingerprint, (string) $initial['fingerprint'])) {
            return $this->result(false, 'rollback_state_changed', 'Rollback evidence changed after assessment.');
        }
        $lock = Cache::lock('addon-install-operation:'.$code, 60);
        if (! $lock->get()) {
            return $this->result(false, 'rollback_operation_active', 'Another addon mutation is active.');
        }
        try {
            $current = $this->assess($code, (string) $initial['source_operation_id']);
            if (! $current['success'] || ! hash_equals((string) $initial['fingerprint'], (string) ($current['fingerprint'] ?? ''))) {
                return $this->result(false, 'rollback_state_changed', 'Rollback evidence changed after lock acquisition.');
            }
            $source = $this->source((string) $initial['source_operation_id']);
            $assessment = $this->recovery->inspect((string) $initial['source_operation_id']);
            if ($source === null || $assessment === null) {
                return $this->result(false, 'rollback_source_not_eligible', 'Rollback source disappeared.');
            }
            $journal = $this->startJournal($source, $assessment, $current, $actor);
            $this->events->warning($code, 'operational_rollback_started', 'Operational addon rollback started.', $this->audit($journal));
            try {
                $this->execute($source, $assessment, $journal);
                $this->transition($journal, 'completed');
                $this->events->info($code, 'operational_rollback_completed', 'Operational addon rollback completed.', $this->audit($journal));

                return $this->result(true, 'rollback_completed', 'Addon version rollback completed.');
            } catch (\Throwable $exception) {
                $codeValue = str_starts_with($exception->getMessage(), 'rollback_') ? $exception->getMessage() : 'rollback_failed';
                $journal['failure_code'] = $codeValue;
                if (($journal['state'] ?? null) === 'compensated_to_current') {
                    $journal['finished_at'] = now()->toIso8601String();
                    $this->persistJournal($journal);
                } else {
                    $this->transition($journal, $codeValue === 'rollback_compensation_failed' ? 'manual_intervention_required' : 'failed');
                }
                $this->events->error($code, 'operational_rollback_failed', 'Operational addon rollback failed.', [...$this->audit($journal), 'code' => $codeValue]);

                return $this->result(false, $codeValue, 'Rollback failed; evidence was preserved.');
            }
        } finally {
            $lock->release();
        }
    }

    private function execute(array $source, AddonRecoveryAssessment $assessment, array &$journal): void
    {
        $live = $this->livePath($assessment);
        $previousBackup = $this->backupPath($assessment);
        if ($live === null || $previousBackup === null) {
            throw new \RuntimeException('rollback_plan_invalid');
        }
        $currentSnapshot = $this->registry->find((string) $source['code'])?->getAttributes();
        if ($currentSnapshot === null) {
            throw new \RuntimeException('rollback_state_changed');
        }
        $this->transition($journal, 'preserving_current');
        $safetyBackup = $this->createSafetyBackup($source, $assessment, $journal, $live, $currentSnapshot);
        $currentSafety = dirname($live).'/.'.basename($live).'.rollback-current-'.$journal['rollback_id'];
        if (! rename($live, $currentSafety)) {
            throw new \RuntimeException('rollback_current_preservation_failed');
        }
        $this->transition($journal, 'promoting_previous');
        if (! rename($previousBackup.'/payload', $live)) {
            if (! rename($currentSafety, $live)) {
                throw new \RuntimeException('rollback_compensation_failed');
            }
            throw new \RuntimeException('rollback_previous_promotion_failed');
        }
        try {
            $this->transition($journal, 'discovering');
            $manifestPath = $this->manifestPath($live);
            if ($manifestPath === null || $this->discovery->syncManifest($manifestPath, (string) $assessment->backupEvidence->type) === null) {
                throw new \RuntimeException('rollback_discovery_failed');
            }
            $this->transition($journal, 'registering');
            $addon = $this->registry->find((string) $source['code']);
            if ($addon === null) {
                throw new \RuntimeException('rollback_registration_failed');
            }
            $addon->forceFill(['status' => SystemAddon::STATUS_INSTALLED, 'is_installed' => true, 'is_enabled' => false, 'enabled_at' => null])->save();
            $addon = $this->lifecycle->install((string) $source['code']);
            if ((bool) ($source['previous_enabled'] ?? false)) {
                $this->transition($journal, 'restoring_enable_state');
                try {
                    $addon = $this->lifecycle->enable((string) $source['code']);
                } catch (\Throwable) {
                    $addon = $this->lifecycle->disable((string) $source['code']);
                    $journal['warnings'][] = 'rollback_enable_deferred';
                }
            }
            $this->transition($journal, 'verifying');
            if ($this->manifestVersion($live) !== $source['previous_version'] || $addon->version !== $source['previous_version']) {
                throw new \RuntimeException('rollback_verification_failed');
            }
            $journal['current_safety_backup_id'] = basename($safetyBackup);
        } catch (\Throwable $failure) {
            $this->transition($journal, 'compensating');
            $failedPrevious = dirname($live).'/.'.basename($live).'.rollback-failed-'.$journal['rollback_id'];
            $moved = is_dir($live) && rename($live, $failedPrevious);
            $restored = is_dir($currentSafety) && rename($currentSafety, $live);
            $this->restoreSnapshot((string) $source['code'], $currentSnapshot);
            if (! $moved || ! $restored || $this->manifestVersion($live) !== $source['target_version']) {
                throw new \RuntimeException('rollback_compensation_failed');
            }
            $this->transition($journal, 'compensated_to_current');
            $this->events->warning((string) $source['code'], 'operational_rollback_compensated', 'Operational rollback compensated to current version.', $this->audit($journal));
            throw $failure;
        }
    }

    private function dependencyBlockers(string $code, string $targetVersion, array $targetManifest): array
    {
        $blocking = [];
        $compatibility = is_array($targetManifest['compatibility'] ?? null) ? $targetManifest['compatibility'] : [];
        $platformConstraint = $compatibility['platform'] ?? $compatibility['app'] ?? null;
        if (is_string($platformConstraint) && $platformConstraint !== '' && ! $this->versions->satisfies($this->platform->version(), $platformConstraint)) {
            $blocking[] = ['code' => 'platform_incompatible', 'constraint' => $platformConstraint];
        }
        foreach (['app_min_version' => '>=', 'app_max_version' => '<='] as $field => $operator) {
            $value = $compatibility[$field] ?? null;
            if (is_string($value) && $value !== '' && ! $this->versions->satisfies($this->platform->version(), $operator.$value)) {
                $blocking[] = ['code' => 'platform_incompatible', 'constraint' => $operator.$value];
            }
        }
        foreach ((array) ($targetManifest['dependencies'] ?? []) as $dependency) {
            $item = is_array($dependency) ? $dependency : ['code' => $dependency];
            if (($item['required'] ?? true) === false) {
                continue;
            }
            $dependencyAddon = $this->registry->find((string) ($item['code'] ?? ''));
            $constraint = (string) ($item['constraint'] ?? $item['version'] ?? '*');
            if ($dependencyAddon === null || ! $dependencyAddon->is_installed || ($constraint !== '*' && ! $this->versions->satisfies((string) $dependencyAddon->version, $constraint))) {
                $blocking[] = ['code' => (string) ($item['code'] ?? ''), 'constraint' => $constraint, 'reason' => 'rollback_target_dependency_unsatisfied'];
            }
        }
        foreach (SystemAddon::query()->installed()->where('code', '!=', $code)->orderBy('code')->get() as $dependent) {
            foreach ((array) ($dependent->metadata['manifest']['dependencies'] ?? []) as $dependency) {
                $item = is_array($dependency) ? $dependency : ['code' => $dependency];
                if (($item['code'] ?? null) !== $code || ($item['required'] ?? true) === false) {
                    continue;
                }
                $constraint = (string) ($item['constraint'] ?? $item['version'] ?? '*');
                if ($constraint !== '*' && ! $this->versions->satisfies($targetVersion, $constraint)) {
                    $blocking[] = ['code' => $dependent->code, 'constraint' => $constraint, 'reason' => 'installed_dependent_incompatible'];
                }
            }
        }

        return $blocking;
    }

    private function createSafetyBackup(array $source, AddonRecoveryAssessment $assessment, array $journal, string $live, array $snapshot): string
    {
        $disk = Storage::disk((string) Config::get('addons-registry.promotion.backup_disk', 'addons'));
        $root = trim((string) Config::get('addons-registry.promotion.backup_path', 'addons/backups'), '/');
        $path = $disk->path($root.'/'.$source['code'].'/rollback-'.$journal['rollback_id']);
        if (! mkdir($path.'/payload', 0755, true) && ! is_dir($path.'/payload')) {
            throw new \RuntimeException('rollback_safety_backup_failed');
        }
        $this->copyTree($live, $path.'/payload');
        $this->backups->create($path, [
            'code' => $source['code'], 'version' => $source['target_version'], 'type' => $assessment->liveEvidence->type,
            'vendor' => $assessment->liveEvidence->vendor, 'operation_id' => $journal['rollback_id'], 'operation_type' => 'operational_rollback',
            'previous_enabled' => (bool) ($snapshot['is_enabled'] ?? false), 'installed_snapshot' => $snapshot,
        ]);
        if (! $this->backups->verify($path)['valid']) {
            throw new \RuntimeException('rollback_safety_backup_failed');
        }

        return $path;
    }

    private function copyTree(string $source, string $destination): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new \RuntimeException('rollback_safety_backup_failed');
            }
            $target = $destination.'/'.str_replace('\\', '/', $iterator->getSubPathName());
            if ($entry->isDir()) {
                if (! is_dir($target) && ! mkdir($target, 0755, true) && ! is_dir($target)) {
                    throw new \RuntimeException('rollback_safety_backup_failed');
                }
            } elseif (! copy($entry->getPathname(), $target)) {
                throw new \RuntimeException('rollback_safety_backup_failed');
            }
        }
    }

    private function completedUpdates(string $code): array
    {
        $journals = [];
        foreach (Storage::disk('addons')->allFiles('addons/install-journal/'.$code) as $path) {
            $journal = json_decode((string) Storage::disk('addons')->get($path), true);
            if (is_array($journal) && ($journal['operation_type'] ?? null) === 'update' && ($journal['state'] ?? null) === 'completed') {
                $journals[] = $journal;
            }
        }
        usort($journals, fn (array $a, array $b): int => strcmp((string) $a['operation_id'], (string) $b['operation_id']));

        return $journals;
    }

    private function source(string $id): ?array
    {
        foreach (Storage::disk('addons')->allFiles('addons/install-journal') as $path) {
            $journal = json_decode((string) Storage::disk('addons')->get($path), true);
            if (is_array($journal) && ($journal['operation_id'] ?? null) === $id) {
                return $journal;
            }
        }

        return null;
    }

    private function startJournal(array $source, AddonRecoveryAssessment $assessment, array $plan, ArtifactReviewActor $actor): array
    {
        $journal = ['schema_version' => 1, 'rollback_id' => (string) Str::uuid(), 'source_operation_id' => $source['operation_id'],
            'code' => $source['code'], 'current_version' => $source['target_version'], 'target_version' => $source['previous_version'],
            'current_evidence_fingerprint' => $assessment->fingerprint, 'preflight_fingerprint' => $plan['fingerprint'],
            'current_enabled' => (bool) ($assessment->evidence['db_enabled'] ?? false), 'actor' => $actor->toArray(),
            'state' => 'preflight_validated', 'started_at' => now()->toIso8601String(), 'steps' => [['state' => 'prepared', 'at' => now()->toIso8601String()], ['state' => 'preflight_validated', 'at' => now()->toIso8601String()]], 'warnings' => []];
        $this->persistJournal($journal);

        return $journal;
    }

    private function transition(array &$journal, string $state): void
    {
        $journal['state'] = $state;
        $journal['steps'][] = ['state' => $state, 'at' => now()->toIso8601String()];
        if (in_array($state, ['completed', 'failed', 'compensated_to_current', 'manual_intervention_required'], true)) {
            $journal['finished_at'] = now()->toIso8601String();
        }
        $this->persistJournal($journal);
    }

    private function persistJournal(array $journal): void
    {
        Storage::disk('addons')->put('addons/rollback-journal/'.$journal['code'].'/'.$journal['rollback_id'].'.json', json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function backupManifest(AddonRecoveryAssessment $assessment): ?array
    {
        $path = $this->backupPath($assessment);
        $manifest = $path === null ? null : $this->manifestPath($path.'/payload');
        $data = $manifest !== null ? json_decode((string) file_get_contents($manifest), true) : null;

        return is_array($data) ? $data : null;
    }

    private function livePath(AddonRecoveryAssessment $assessment): ?string
    {
        $addon = $this->registry->find($assessment->code);
        if ($addon === null) {
            return null;
        }
        $root = (string) Config::get('addons-registry.live_roots.'.($addon->type === 'extension' ? 'extensions_path' : 'modules_path'), base_path($addon->type === 'extension' ? 'extensions' : 'modules'));
        $matches = glob(rtrim($root, '/').'/*/*/'.($addon->type === 'extension' ? 'extension.json' : 'module.json')) ?: [];
        foreach ($matches as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true);
            if (is_array($data) && ($data['code'] ?? null) === $assessment->code) {
                return dirname($manifest);
            }
        }

        return null;
    }

    private function backupPath(AddonRecoveryAssessment $assessment): ?string
    {
        $disk = Storage::disk((string) Config::get('addons-registry.promotion.backup_disk', 'addons'));
        $root = trim((string) Config::get('addons-registry.promotion.backup_path', 'addons/backups'), '/').'/'.$assessment->code;
        foreach ($disk->directories($root) as $directory) {
            $path = $disk->path($directory);
            $verified = $this->backups->verify($path);
            if (($verified['valid'] ?? false) && ($verified['record']['version'] ?? null) === $assessment->previousVersion
                && ($verified['record']['source_operation_id'] ?? null) === ($assessment->evidence['promotion_transaction_id'] ?? null)) {
                return $path;
            }
        }

        return null;
    }

    private function manifestPath(string $tree): ?string
    {
        foreach (['module.json', 'extension.json', 'manifest.json'] as $name) {
            if (is_file($tree.'/'.$name)) {
                return $tree.'/'.$name;
            }
        }

        return null;
    }

    private function manifestVersion(string $tree): ?string
    {
        $path = $this->manifestPath($tree);
        $data = $path === null ? null : json_decode((string) file_get_contents($path), true);

        return is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : null;
    }

    private function restoreSnapshot(string $code, array $snapshot): void
    {
        $addon = SystemAddon::query()->firstOrNew(['code' => $code]);
        $addon->forceFill(array_diff_key($snapshot, array_flip(['id', 'code', 'created_at', 'updated_at'])))->save();
    }

    private function audit(array $journal): array
    {
        return array_intersect_key($journal, array_flip(['rollback_id', 'source_operation_id', 'code', 'current_version', 'target_version', 'preflight_fingerprint', 'state']));
    }

    private function result(bool $success, string $code, string $message): array
    {
        return ['success' => $success, 'code' => $code, 'message' => $message];
    }
}
