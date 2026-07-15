<?php

namespace App\Support\Addons\Registry;

use App\Models\SystemAddonEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class AddonRecoveryHealthService
{
    public function __construct(
        private readonly AddonRecoveryService $recovery,
        private readonly RecoveryDataCleanupService $cleanup,
        private readonly AddonOperationalRollbackService $rollback,
    ) {}

    public function health(bool $refresh = false): array
    {
        $policy = $this->policy();
        if (! $policy['valid'] || ! $policy['enabled']) {
            return $this->disabled();
        }
        if ($refresh) {
            Cache::forget(AddonRecoveryHealthCache::KEY);
        }

        return Cache::remember(AddonRecoveryHealthCache::KEY, $policy['ttl'], fn (): array => $this->build($policy));
    }

    private function build(array $policy): array
    {
        $assessments = array_slice($this->recovery->scan(), 0, $policy['max']);
        $journalDebt = $this->journalDebt($policy['max']);
        $items = [];
        $automatic = $manual = $active = $corrupt = 0;
        $oldest = null;
        foreach ($assessments as $assessment) {
            $updatedAt = $this->operationUpdatedAt($assessment->operationId);
            $age = $updatedAt === null ? null : max(0, time() - $updatedAt);
            $markedManual = isset($journalDebt['manual_sources'][$assessment->operationId]);
            $isActive = ! $markedManual && $age !== null && $age < $policy['stale'];
            $isManual = $markedManual || (! $assessment->automaticEligible && ! $isActive);
            $automatic += (int) ($assessment->automaticEligible && ! $isActive && ! $isManual);
            $manual += (int) $isManual;
            $active += (int) $isActive;
            $corrupt += (int) ($assessment->backupEvidence->existence === 'present' && $assessment->backupEvidence->integrity !== 'verified');
            $oldest = $age === null ? $oldest : max($oldest ?? 0, $age);
            $items[] = [
                'operation' => substr($assessment->operationId, 0, 8),
                'operation_id' => $assessment->operationId,
                'addon_code' => $assessment->code,
                'operation_type' => $assessment->operationType,
                'state' => $isActive ? 'active' : ($isManual ? 'manual_intervention_required' : 'unresolved'),
                'classification' => $assessment->classification,
                'automatic' => $assessment->automaticEligible && ! $isActive && ! $isManual,
                'proposed_action' => $assessment->proposedAction,
                'integrity' => [
                    'live' => $assessment->liveEvidence->integrity,
                    'backup' => $assessment->backupEvidence->integrity,
                    'candidate' => $assessment->candidateEvidence->integrity,
                    'staging' => $assessment->stagingEvidence->integrity,
                ],
                'age_seconds' => $age,
                'diagnostic' => $isActive ? 'recovery_operation_active' : ($isManual ? 'recovery_manual_intervention_required' : 'recovery_safe_action_available'),
            ];
        }
        foreach ($journalDebt['standalone'] as $debt) {
            if (count($items) >= $policy['max'] || collect($items)->contains(fn (array $item): bool => $item['operation_id'] === $debt['operation_id'] || $item['operation_id'] === $debt['source_operation_id'])) {
                continue;
            }
            $items[] = $debt;
            $manual += (int) ($debt['state'] === 'manual_intervention_required');
            $active += (int) ($debt['state'] === 'active');
            $oldest = $debt['age_seconds'] === null ? $oldest : max($oldest ?? 0, $debt['age_seconds']);
        }
        usort($items, fn (array $a, array $b): int => strcmp($a['addon_code'], $b['addon_code']) ?: strcmp($a['operation_id'], $b['operation_id']));
        $cleanupPending = count(array_filter($this->cleanup->scanBackups(), fn ($backup): bool => $backup->backupStatus === 'cleanup_pending'));
        $status = $manual > 0 || $corrupt > 0 ? 'manual_intervention_required' : (($items !== [] || $cleanupPending > 0) ? 'degraded' : 'healthy');
        $codes = array_values(array_unique(array_column($items, 'addon_code')));
        sort($codes);
        $rollbackCandidates = $this->rollbackCandidates($policy['max']);

        return [
            'status' => $status,
            'unresolved_count' => count($items),
            'automatic_safe_count' => $automatic,
            'manual_intervention_count' => $manual,
            'active_operation_count' => $active,
            'corrupt_backup_count' => $corrupt,
            'cleanup_pending_count' => $cleanupPending,
            'oldest_unresolved_age_seconds' => $oldest,
            'last_successful_recovery_at' => $this->eventTimestamp(['recovery_completed', 'operational_rollback_completed', 'backup_cleanup_completed', 'stale_cleanup_completed']),
            'last_operation_failure' => $this->eventCode(['recovery_failed', 'operational_rollback_failed', 'backup_cleanup_failed', 'stale_cleanup_failed']),
            'affected_addon_codes' => $codes,
            'diagnostic_codes' => array_values(array_unique(array_column($items, 'diagnostic'))),
            'items' => $items,
            'rollback_candidates' => $rollbackCandidates,
            'truncated' => count($assessments) >= $policy['max'],
        ];
    }

    private function journalDebt(int $limit): array
    {
        $manualSources = [];
        $standalone = [];
        foreach (['addons/recovery-journal' => 'recovery', 'addons/rollback-journal' => 'rollback'] as $root => $type) {
            foreach (array_slice(Storage::disk('addons')->allFiles($root), 0, $limit) as $path) {
                $journal = json_decode((string) Storage::disk('addons')->get($path), true);
                if (! is_array($journal)) {
                    continue;
                }
                $state = (string) ($journal['state'] ?? 'unknown');
                if (in_array($state, ['completed', 'compensated_to_current', 'reconciled'], true)) {
                    continue;
                }
                $id = $journal[$type === 'recovery' ? 'recovery_id' : 'rollback_id'] ?? null;
                $source = $journal['source_operation_id'] ?? null;
                if (! is_string($id) || ! is_string($journal['code'] ?? null)) {
                    continue;
                }
                if ($state === 'manual_intervention_required' && is_string($source)) {
                    $manualSources[$source] = true;
                }
                $time = is_string($journal['finished_at'] ?? $journal['started_at'] ?? null) ? strtotime($journal['finished_at'] ?? $journal['started_at']) : false;
                $age = $time === false ? null : max(0, time() - $time);
                $standalone[] = [
                    'operation' => substr($id, 0, 8), 'operation_id' => $id, 'addon_code' => $journal['code'],
                    'source_operation_id' => is_string($source) ? $source : null,
                    'operation_type' => $type, 'state' => $state === 'manual_intervention_required' ? $state : 'active',
                    'classification' => $journal['classification'] ?? $state, 'automatic' => false,
                    'proposed_action' => 'manual_intervention',
                    'integrity' => ['live' => 'not_verifiable', 'backup' => 'not_verifiable', 'candidate' => 'not_verifiable', 'staging' => 'not_verifiable'],
                    'age_seconds' => $age,
                    'diagnostic' => $state === 'manual_intervention_required' ? 'recovery_manual_intervention_required' : $type.'_operation_active',
                ];
            }
        }

        return ['manual_sources' => $manualSources, 'standalone' => $standalone];
    }

    private function rollbackCandidates(int $limit): array
    {
        $rows = [];
        foreach (array_slice(Storage::disk('addons')->allFiles('addons/install-journal'), 0, $limit) as $path) {
            $journal = json_decode((string) Storage::disk('addons')->get($path), true);
            if (! is_array($journal) || ($journal['state'] ?? null) !== 'completed' || ($journal['operation_type'] ?? null) !== 'update'
                || ! is_string($journal['operation_id'] ?? null) || ! is_string($journal['code'] ?? null)) {
                continue;
            }
            $plan = $this->rollback->assess($journal['code'], $journal['operation_id']);
            $rows[] = [
                'operation' => substr($journal['operation_id'], 0, 8),
                'operation_id' => $journal['operation_id'],
                'addon_code' => $journal['code'],
                'current_version' => $plan['current_version'] ?? $journal['target_version'] ?? null,
                'target_version' => $plan['target_version'] ?? $journal['previous_version'] ?? null,
                'eligible' => (bool) ($plan['success'] ?? false),
                'code' => $plan['code'] ?? 'rollback_source_not_eligible',
                'blocking' => $plan['blocking'] ?? [],
            ];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['addon_code'], $b['addon_code']) ?: strcmp($a['operation_id'], $b['operation_id']));

        return $rows;
    }

    private function operationUpdatedAt(string $id): ?int
    {
        foreach (Storage::disk('addons')->allFiles('addons/install-journal') as $path) {
            if (! str_contains($path, $id)) {
                continue;
            }
            $journal = json_decode((string) Storage::disk('addons')->get($path), true);
            foreach (['finished_at', 'failed_at', 'updated_at', 'started_at'] as $key) {
                if (is_string($journal[$key] ?? null) && ($time = strtotime($journal[$key])) !== false) {
                    return $time;
                }
            }

            return Storage::disk('addons')->lastModified($path);
        }

        return null;
    }

    private function eventTimestamp(array $events): ?string
    {
        if (! Schema::hasTable('system_addon_events')) {
            return null;
        }

        return SystemAddonEvent::query()->whereIn('event', $events)->latest('id')->value('created_at')?->toIso8601String();
    }

    private function eventCode(array $events): ?string
    {
        if (! Schema::hasTable('system_addon_events')) {
            return null;
        }
        $event = SystemAddonEvent::query()->whereIn('event', $events)->latest('id')->first();

        return $event?->event;
    }

    private function policy(): array
    {
        $ttl = filter_var(Config::get('addons-registry.recovery_health.cache_ttl'), FILTER_VALIDATE_INT);
        $stale = filter_var(Config::get('addons-registry.recovery_health.stale_after'), FILTER_VALIDATE_INT);
        $max = filter_var(Config::get('addons-registry.recovery_health.max_operations'), FILTER_VALIDATE_INT);
        $valid = $ttl !== false && $stale !== false && $max !== false && $ttl >= 1 && $ttl <= 3600 && $stale >= 30 && $stale <= 86400 && $max >= 1 && $max <= 500;

        return ['valid' => $valid, 'enabled' => filter_var(Config::get('addons-registry.recovery_health.enabled', true), FILTER_VALIDATE_BOOL), 'ttl' => $valid ? $ttl : 1, 'stale' => $valid ? $stale : PHP_INT_MAX, 'max' => $valid ? $max : 1];
    }

    private function disabled(): array
    {
        return ['status' => 'manual_intervention_required', 'unresolved_count' => 0, 'automatic_safe_count' => 0,
            'manual_intervention_count' => 0, 'active_operation_count' => 0, 'corrupt_backup_count' => 0,
            'cleanup_pending_count' => 0, 'oldest_unresolved_age_seconds' => null, 'last_successful_recovery_at' => null,
            'last_operation_failure' => null, 'affected_addon_codes' => [], 'diagnostic_codes' => ['recovery_health_config_invalid'],
            'items' => [], 'rollback_candidates' => [], 'truncated' => false];
    }
}
