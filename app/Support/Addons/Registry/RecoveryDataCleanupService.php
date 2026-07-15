<?php

namespace App\Support\Addons\Registry;

use App\Support\Addons\AddonEventLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

final class RecoveryDataCleanupService
{
    private const TERMINAL = ['completed', 'rolled_back', 'compensated_to_current', 'cancelled', 'reconciled'];

    public function __construct(private readonly BackupIntegrityService $integrity, private readonly AddonEventLogger $events) {}

    /** @return list<BackupRetentionAssessment> */
    public function scanBackups(): array
    {
        $policy = $this->policy();
        $references = $this->references();
        $rows = [];
        foreach ($this->backupDirectories() as $path) {
            $record = $this->json($path.'/backup.json') ?? [];
            $id = is_string($record['backup_id'] ?? null) ? $record['backup_id'] : basename($path);
            $code = is_string($record['code'] ?? null) ? $record['code'] : null;
            $created = is_string($record['created_at'] ?? null) ? $record['created_at'] : null;
            $age = $this->age($created, $path);
            $operation = is_string($record['source_operation_id'] ?? null) ? $record['source_operation_id'] : null;
            $verified = ($record['verification_state'] ?? null) === 'verified' && ($record['status'] ?? null) !== 'deleted';
            $managed = $this->safePath($path, $this->backupRoot());
            $reference = $operation !== null && isset($references['unresolved'][$operation]);
            $rollback = $operation !== null && isset($references['rollback'][$operation]);
            $manual = (bool) ($record['retain'] ?? $record['manually_retained'] ?? false);
            $integrity = ! $managed ? 'unmanaged' : ($verified ? 'verified' : 'unknown');
            $rows[] = compact('path', 'record', 'id', 'code', 'created', 'age', 'operation', 'verified', 'managed', 'reference', 'rollback', 'manual', 'integrity');
        }
        usort($rows, fn (array $a, array $b): int => strcmp((string) $b['created'], (string) $a['created']) ?: strcmp($a['id'], $b['id']));
        $counts = [];
        $lastGood = [];
        foreach ($rows as $row) {
            if ($row['code'] !== null && $row['verified'] && $row['managed'] && ! isset($lastGood[$row['code']]) && $this->operationTerminal($row['operation'])) {
                $lastGood[$row['code']] = $row['id'];
            }
        }
        $result = [];
        foreach ($rows as $row) {
            $rank = $row['code'] === null ? PHP_INT_MAX : (($counts[$row['code']] = ($counts[$row['code']] ?? 0) + 1));
            $knownGood = $row['code'] !== null && ($lastGood[$row['code']] ?? null) === $row['id'];
            [$eligible, $reason] = $this->backupDecision($row, $rank, $knownGood, $policy);
            $fingerprint = hash('sha256', json_encode([$row['id'], $row['record'], $eligible, $reason, $rank, $knownGood, $references], JSON_UNESCAPED_SLASHES));
            $result[] = new BackupRetentionAssessment($row['id'], $row['code'], $row['record']['version'] ?? null,
                $row['operation'], $row['integrity'], (string) ($row['record']['status'] ?? 'unknown'), $row['created'],
                $row['record']['verified_at'] ?? null, $row['age'], $row['reference'], $row['rollback'],
                ($row['record']['source_operation_type'] ?? null) === 'operational_rollback', $knownGood, $row['manual'], $eligible, $reason, $fingerprint);
        }
        usort($result, fn ($a, $b): int => strcmp((string) $a->addonCode, (string) $b->addonCode) ?: strcmp((string) $a->createdAt, (string) $b->createdAt) ?: strcmp($a->backupId, $b->backupId));

        return $result;
    }

    public function cleanupBackup(string $id, string $fingerprint): array
    {
        $initial = $this->backup($id);
        if ($initial === null || ! $initial->eligible || ! hash_equals($initial->fingerprint, $fingerprint)) {
            return $this->result(false, 'backup_cleanup_state_changed', $id);
        }
        $lock = Cache::lock('addon-install-operation:'.$initial->addonCode, 60);
        if (! $lock->get()) {
            return $this->result(false, 'backup_cleanup_lock_unavailable', $id);
        }
        try {
            $current = $this->backup($id);
            if ($current === null || ! $current->eligible || ! hash_equals($fingerprint, $current->fingerprint)) {
                return $this->result(false, 'backup_cleanup_state_changed', $id);
            }
            if (! $this->enabled()) {
                return $this->result(false, 'backup_cleanup_blocked', $id);
            }
            $path = $this->findBackupPath($id);
            if ($path === null || ! $this->safePath($path, $this->backupRoot()) || basename($path) !== $id) {
                return $this->result(false, 'backup_cleanup_blocked', $id);
            }
            $record = $this->json($path.'/backup.json') ?? [];
            $verified = $this->integrity->verify($path);
            if (! ($verified['valid'] ?? false) || ($verified['record']['backup_id'] ?? null) !== $id
                || ($verified['record']['code'] ?? null) !== $current->addonCode) {
                return $this->result(false, 'backup_cleanup_blocked', $id);
            }
            $record['status'] = 'cleanup_pending';
            $record['cleanup_reason'] = $current->reason;
            $record['cleanup_started_at'] = now()->toIso8601String();
            $this->atomicJson($path.'/backup.json', $record);
            $this->events->warning($current->addonCode, 'backup_cleanup_started', 'Addon backup cleanup started.', ['backup_id' => $id, 'reason' => $current->reason]);
            $tombstone = [...$record, 'status' => 'cleanup_pending'];
            $tombstonePath = $this->tombstoneRoot().'/'.$id.'.json';
            $this->atomicJson($tombstonePath, $tombstone);
            $this->deleteExact($path, $this->backupRoot());
            if (file_exists($path) || is_link($path)) {
                throw new \RuntimeException('delete_failed');
            }
            $tombstone['status'] = 'deleted';
            $tombstone['deleted_at'] = now()->toIso8601String();
            $this->atomicJson($tombstonePath, $tombstone);
            $this->events->info($current->addonCode, 'backup_cleanup_completed', 'Addon backup cleanup completed.', ['backup_id' => $id, 'reason' => $current->reason]);

            return $this->result(true, 'backup_cleanup_completed', $id);
        } catch (\Throwable) {
            $this->events->error($initial->addonCode, 'backup_cleanup_failed', 'Addon backup cleanup failed.', ['backup_id' => $id, 'code' => 'backup_cleanup_failed']);

            return $this->result(false, 'backup_cleanup_failed', $id);
        } finally {
            $lock->release();
        }
    }

    /** @return list<StaleRemnantAssessment> */
    public function scanRemnants(): array
    {
        $items = [];
        $threshold = $this->policy()['stale'];
        foreach ($this->remnantPaths() as [$path, $kind, $root]) {
            $safe = $this->safePath($path, $root);
            $ownership = $this->remnantOwner($path, $kind);
            $age = max(0, time() - (int) (@filemtime($path) ?: time()));
            $operation = $ownership['operation'];
            $terminal = $operation !== null && $this->operationTerminal($operation);
            $referenced = $operation !== null && ! $terminal;
            $eligible = $this->policy()['valid'] && $safe && $ownership['owned'] && $age >= $threshold && ! $referenced;
            $reason = ! $safe ? (is_link($path) ? 'stale_item_symlink' : 'stale_item_unmanaged')
                : (! $ownership['owned'] ? 'stale_item_unmanaged' : ($referenced ? 'stale_item_referenced' : ($age < $threshold ? 'stale_item_recent' : 'stale_item_eligible')));
            $identifier = hash('sha256', $kind."\0".$path);
            $fingerprint = hash('sha256', json_encode([$identifier, @lstat($path), $operation, $eligible, $reason], JSON_UNESCAPED_SLASHES));
            $items[] = new StaleRemnantAssessment($identifier, $kind, $operation, $ownership['code'], $safe ? 'managed' : 'unmanaged',
                $ownership['owned'] ? 'owned' : 'unknown', $age, false, $referenced, $terminal, $eligible, $reason, $fingerprint);
        }
        usort($items, fn ($a, $b): int => strcmp($a->kind, $b->kind) ?: strcmp($a->identifier, $b->identifier));

        return $items;
    }

    public function cleanupRemnant(string $identifier, string $fingerprint): array
    {
        $initial = collect($this->scanRemnants())->first(fn ($item) => $item->identifier === $identifier);
        if ($initial === null || ! $initial->eligible || ! hash_equals($fingerprint, $initial->fingerprint) || ! $this->enabled()) {
            return $this->result(false, 'stale_item_state_changed', $identifier);
        }
        $lock = Cache::lock('addon-install-operation:'.($initial->addonCode ?? $initial->operationId ?? $identifier), 60);
        if (! $lock->get()) {
            return $this->result(false, 'stale_cleanup_lock_unavailable', $identifier);
        }
        try {
            $current = collect($this->scanRemnants())->first(fn ($item) => $item->identifier === $identifier);
            if ($current === null || ! $current->eligible || ! hash_equals($fingerprint, $current->fingerprint)) {
                return $this->result(false, 'stale_item_state_changed', $identifier);
            }
            foreach ($this->remnantPaths() as [$path, , $root]) {
                if (hash('sha256', $current->kind."\0".$path) === $identifier) {
                    $this->events->warning($current->addonCode, 'stale_cleanup_started', 'Stale addon data cleanup started.', ['identifier' => $identifier, 'kind' => $current->kind]);
                    $this->deleteExact($path, $root);
                    $this->events->info($current->addonCode, 'stale_cleanup_completed', 'Stale addon data cleanup completed.', ['identifier' => $identifier, 'kind' => $current->kind]);

                    return $this->result(true, 'stale_cleanup_completed', $identifier);
                }
            }

            return $this->result(false, 'stale_item_state_changed', $identifier);
        } catch (\Throwable) {
            return $this->result(false, 'stale_cleanup_failed', $identifier);
        } finally {
            $lock->release();
        }
    }

    private function backupDecision(array $row, int $rank, bool $lastGood, array $policy): array
    {
        if (! $policy['valid'] || ! $row['managed']) {
            return [false, $row['managed'] ? 'cleanup_blocked_integrity_unknown' : 'cleanup_blocked_unmanaged'];
        }
        if ($row['reference'] || $row['rollback']) {
            return [false, 'retain_active_reference'];
        }
        if ($row['manual']) {
            return [false, 'retain_manual'];
        }
        if (! $row['verified']) {
            return [false, 'cleanup_blocked_integrity_unknown'];
        }
        if ($lastGood) {
            return [false, 'retain_last_known_good'];
        }
        if ($rank <= $policy['min']) {
            return [false, 'retain_minimum_count'];
        }
        if ($rank > $policy['max']) {
            return [true, 'eligible_max_count'];
        }
        if ($row['age'] !== null && $row['age'] >= $policy['days'] * 86400) {
            return [true, 'eligible_age'];
        }

        return [false, 'retain_recent'];
    }

    private function policy(): array
    {
        $min = filter_var(Config::get('addons-registry.cleanup.backup_retention_min_count'), FILTER_VALIDATE_INT);
        $max = filter_var(Config::get('addons-registry.cleanup.backup_retention_max_count'), FILTER_VALIDATE_INT);
        $days = filter_var(Config::get('addons-registry.cleanup.backup_retention_days'), FILTER_VALIDATE_INT);
        $stale = filter_var(Config::get('addons-registry.cleanup.stale_after'), FILTER_VALIDATE_INT);
        $valid = $min !== false && $max !== false && $days !== false && $stale !== false && $min >= 1 && $max >= $min && $days >= 1 && $stale >= 1;

        return ['valid' => $valid, 'min' => $valid ? $min : PHP_INT_MAX, 'max' => $valid ? $max : PHP_INT_MAX, 'days' => $valid ? $days : PHP_INT_MAX, 'stale' => $valid ? $stale : PHP_INT_MAX];
    }

    private function enabled(): bool
    {
        return filter_var(Config::get('addons-registry.cleanup.enabled', false), FILTER_VALIDATE_BOOL);
    }

    private function backup(string $id): ?BackupRetentionAssessment
    {
        return collect($this->scanBackups())->first(fn ($item) => $item->backupId === $id);
    }

    private function findBackupPath(string $id): ?string
    {
        return collect($this->backupDirectories())->first(fn ($path) => basename($path) === $id);
    }

    private function backupRoot(): string
    {
        return Storage::disk((string) Config::get('addons-registry.promotion.backup_disk', 'addons'))->path(trim((string) Config::get('addons-registry.promotion.backup_path', 'addons/backups'), '/'));
    }

    private function tombstoneRoot(): string
    {
        return Storage::disk((string) Config::get('addons-registry.promotion.journal_disk', 'addons'))->path(trim((string) Config::get('addons-registry.cleanup.tombstone_path'), '/'));
    }

    private function backupDirectories(): array
    {
        $root = $this->backupRoot();
        $paths = [];
        if (! is_dir($root) || is_link($root)) {
            return [];
        }
        foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $addon) {
            if (! $addon->isDir() || $addon->isLink()) {
                continue;
            }
            foreach (new \FilesystemIterator($addon->getPathname(), \FilesystemIterator::SKIP_DOTS) as $backup) {
                if ($backup->isDir()) {
                    $paths[] = $backup->getPathname();
                }
            }
        }
        sort($paths);

        return $paths;
    }

    private function references(): array
    {
        $result = ['unresolved' => [], 'rollback' => []];
        foreach ($this->journalFiles() as $file) {
            $journal = $this->json($file);
            if ($journal === null) {
                continue;
            }
            $state = (string) ($journal['state'] ?? $journal['status'] ?? 'unknown');
            foreach (['operation_id', 'source_operation_id', 'promotion_transaction_id', 'rollback_id'] as $key) {
                if (! is_string($journal[$key] ?? null)) {
                    continue;
                }
                if (! in_array($state, self::TERMINAL, true)) {
                    $result['unresolved'][$journal[$key]] = true;
                }
                if (isset($journal['rollback_id']) || str_contains($file, 'rollback')) {
                    $result['rollback'][$journal[$key]] = true;
                }
            }
        }

        return $result;
    }

    private function operationTerminal(?string $id): bool
    {
        if ($id === null) {
            return false;
        }
        foreach ($this->journalFiles() as $file) {
            $j = $this->json($file);
            if (($j['operation_id'] ?? null) === $id || ($j['rollback_id'] ?? null) === $id) {
                return in_array($j['state'] ?? $j['status'] ?? null, self::TERMINAL, true);
            }
        }

        return false;
    }

    private function journalFiles(): array
    {
        $root = Storage::disk('addons')->path('addons');
        $files = [];
        foreach (['install-journal', trim((string) Config::get('addons-registry.promotion.journal_path'), '/'), 'recovery-journal', 'rollback-journal'] as $name) {
            $path = $root.'/'.preg_replace('#^addons/#', '', $name);
            if (! is_dir($path)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $entry) {
                if ($entry->isFile() && ! $entry->isLink() && str_ends_with($entry->getFilename(), '.json')) {
                    $files[] = $entry->getPathname();
                }
            }
        }
        sort($files);

        return array_values(array_unique($files));
    }

    private function remnantPaths(): array
    {
        $items = [];
        $roots = [
            ['root' => Storage::disk((string) Config::get('addons-registry.downloads.disk', 'addons'))->path(trim((string) Config::get('addons-registry.downloads.quarantine_path'), '/')), 'kind' => 'quarantine_part'],
            ['root' => Storage::disk((string) Config::get('addons-registry.staging.disk', 'addons'))->path(trim((string) Config::get('addons-registry.staging.path'), '/')), 'kind' => 'staging'],
            ['root' => $this->backupRoot(), 'kind' => 'backup_temp'],
        ];
        foreach ($roots as $spec) {
            if (! is_dir($spec['root']) || is_link($spec['root'])) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($spec['root'], \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $entry) {
                $name = $entry->getFilename();
                if ($spec['kind'] === 'quarantine_part' && $entry->isFile() && preg_match('/^(?:[a-f0-9]{32}|\.metadata\.[a-f0-9]{16})\.part$/', $name)) {
                    $items[] = [$entry->getPathname(), str_starts_with($name, '.metadata.') ? 'temporary_metadata' : 'quarantine_part', $spec['root']];
                }
                if ($spec['kind'] === 'staging' && $entry->isDir() && is_file($entry->getPathname().'/staging.json')) {
                    $items[] = [$entry->getPathname(), 'staging', $spec['root']];
                }
                if ($spec['kind'] === 'backup_temp' && (($entry->isDir() && str_ends_with($name, '.part')) || ($entry->isFile() && preg_match('/^\.backup\.[a-f0-9]{12}\.part$/', $name)))) {
                    $items[] = [$entry->getPathname(), 'backup_temp', $spec['root']];
                }
            }
        }
        foreach ((array) Config::get('addons-registry.live_roots') as $root) {
            if (! is_dir($root) || is_link($root)) {
                continue;
            }
            foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($entry->isDir() && preg_match('/^\..+\.(promote|rollback|rollback-current)-([0-9a-f-]{36})$/i', $entry->getFilename())) {
                    $items[] = [$entry->getPathname(), 'candidate', $root];
                }
            }
        }

        return $items;
    }

    private function remnantOwner(string $path, string $kind): array
    {
        if ($kind === 'candidate' && preg_match('/-([0-9a-f-]{36})$/i', basename($path), $m)) {
            $meta = $this->json($path.'/.candidate-evidence.json');

            return ['owned' => is_array($meta) && ($meta['operation_id'] ?? null) === $m[1], 'operation' => $m[1], 'code' => $meta['code'] ?? null];
        }
        if ($kind === 'staging') {
            $meta = $this->json($path.'/.staging.json') ?? $this->json($path.'/staging.json');

            return ['owned' => is_string($meta['operation_id'] ?? null), 'operation' => $meta['operation_id'] ?? null, 'code' => $meta['code'] ?? null];
        }
        if ($kind === 'quarantine_part' || $kind === 'temporary_metadata') {
            return ['owned' => true, 'operation' => null, 'code' => null];
        }
        if ($kind === 'backup_temp') {
            $parent = dirname($path);
            $meta = $this->json($parent.'/backup.json');

            return ['owned' => is_array($meta) && is_string($meta['source_operation_id'] ?? null), 'operation' => $meta['source_operation_id'] ?? null, 'code' => $meta['code'] ?? null];
        }

        return ['owned' => false, 'operation' => null, 'code' => null];
    }

    private function safePath(string $path, string $root): bool
    {
        $realRoot = realpath($root);
        $realPath = realpath($path);
        if ($realRoot === false || $realPath === false || is_link($root) || is_link($path) || ($realPath !== $realRoot && ! str_starts_with($realPath, $realRoot.DIRECTORY_SEPARATOR))) {
            return false;
        }
        for ($p = $path; $p !== $root && dirname($p) !== $p; $p = dirname($p)) {
            if (is_link($p)) {
                return false;
            }
        }

        return true;
    }

    private function deleteExact(string $path, string $root): void
    {
        if (! $this->safePath($path, $root) || realpath($path) === realpath($root)) {
            throw new \RuntimeException('unmanaged_path');
        }
        if (is_file($path)) {
            if (! unlink($path)) {
                throw new \RuntimeException('delete_failed');
            }

            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            if ($entry->isLink()) {
                throw new \RuntimeException('symlink_conflict');
            } $ok = $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            if (! $ok) {
                throw new \RuntimeException('delete_failed');
            }
        }
        if (! rmdir($path)) {
            throw new \RuntimeException('delete_failed');
        }
    }

    private function json(string $path): ?array
    {
        if (! is_file($path) || is_link($path)) {
            return null;
        } $v = json_decode((string) file_get_contents($path), true);

        return is_array($v) ? $v : null;
    }

    private function atomicJson(string $path, array $data): void
    {
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            throw new \RuntimeException('write_failed');
        } $tmp = $path.'.'.bin2hex(random_bytes(6)).'.part';
        if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || ! rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('write_failed');
        }
    }

    private function age(?string $created, string $path): ?int
    {
        $time = $created !== null ? strtotime($created) : false;
        $time = $time === false ? @filemtime($path) : $time;

        return $time === false ? null : max(0, time() - $time);
    }

    private function result(bool $success, string $code, string $identifier): array
    {
        return compact('success', 'code', 'identifier');
    }
}
