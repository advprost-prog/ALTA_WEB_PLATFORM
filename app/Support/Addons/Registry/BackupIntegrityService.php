<?php

namespace App\Support\Addons\Registry;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

final class BackupIntegrityService
{
    public function __construct(private readonly ManagedTreeInventory $inventory) {}

    public function create(string $backupPath, array $identity): array
    {
        $payload = $backupPath.'/payload';
        $tree = $this->inventory->build($payload);
        $manifest = $this->manifest($payload);
        if ($manifest === null || ($manifest['data']['code'] ?? null) !== $identity['code'] || ($manifest['data']['version'] ?? null) !== $identity['version']) {
            throw new \RuntimeException('backup_identity_mismatch');
        }
        $record = [
            'schema_version' => 2, 'backup_id' => basename($backupPath), 'code' => $identity['code'],
            'version' => $identity['version'], 'type' => $manifest['data']['type'] ?? $identity['type'],
            'vendor' => $manifest['data']['vendor'] ?? $identity['vendor'], 'source_operation_id' => $identity['operation_id'],
            'source_operation_type' => $identity['operation_type'] ?? 'update', 'previous_enabled' => (bool) ($identity['previous_enabled'] ?? false),
            'installed_snapshot' => $identity['installed_snapshot'] ?? null, 'manifest_path' => $manifest['path'],
            'manifest_digest' => hash('sha256', json_encode($manifest['data'], JSON_UNESCAPED_SLASHES)),
            ...$tree, 'created_at' => now()->toIso8601String(), 'verified_at' => now()->toIso8601String(),
            'verification_state' => 'verified', 'status' => 'verified',
        ];
        $temp = $backupPath.'/.backup.'.bin2hex(random_bytes(6)).'.part';
        file_put_contents($temp, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        if (! rename($temp, $backupPath.'/backup.json')) {
            @unlink($temp);
            throw new \RuntimeException('backup_record_invalid');
        }
        if (! $this->verify($backupPath)['valid']) {
            throw new \RuntimeException('backup_integrity_failed');
        }

        return $record;
    }

    public function verify(string $backupPath): array
    {
        if (! $this->managed($backupPath)) {
            return ['valid' => false, 'status' => 'unmanaged', 'diagnostics' => ['backup_unmanaged_path']];
        }
        if (is_link($backupPath)) {
            return ['valid' => false, 'status' => 'symlink_conflict', 'diagnostics' => ['backup_symlink_conflict']];
        }
        if (! is_file($backupPath.'/backup.json')) {
            return ['valid' => false, 'status' => 'legacy_unverified', 'diagnostics' => ['backup_legacy_unverified']];
        }
        $record = json_decode((string) file_get_contents($backupPath.'/backup.json'), true);
        if (! is_array($record) || ($record['schema_version'] ?? null) !== 2 || ($record['verification_state'] ?? null) !== 'verified') {
            return ['valid' => false, 'status' => 'invalid', 'diagnostics' => ['backup_record_invalid']];
        }
        try {
            $tree = $this->inventory->build($backupPath.'/payload');
            $manifest = $this->manifest($backupPath.'/payload');
        } catch (\Throwable) {
            return ['valid' => false, 'status' => 'corrupt', 'diagnostics' => ['backup_integrity_failed']];
        }
        $valid = $manifest !== null && $tree['inventory_digest'] === ($record['inventory_digest'] ?? null)
            && $tree['files'] === ($record['files'] ?? null) && $tree['file_count'] === ($record['file_count'] ?? null)
            && $tree['total_bytes'] === ($record['total_bytes'] ?? null)
            && hash('sha256', json_encode($manifest['data'], JSON_UNESCAPED_SLASHES)) === ($record['manifest_digest'] ?? null)
            && ($manifest['data']['code'] ?? null) === ($record['code'] ?? null) && ($manifest['data']['version'] ?? null) === ($record['version'] ?? null);

        return ['valid' => $valid, 'status' => $valid ? 'verified' : 'corrupt', 'diagnostics' => $valid ? [] : ['backup_integrity_failed'], 'record' => $record];
    }

    private function managed(string $path): bool
    {
        $disk = Storage::disk((string) Config::get('addons-registry.promotion.backup_disk', 'addons'));
        $root = realpath($disk->path(trim((string) Config::get('addons-registry.promotion.backup_path', 'addons/backups'), '/')));
        $parent = realpath(dirname($path));

        return $root !== false && $parent !== false && ($parent === $root || str_starts_with($parent, $root.DIRECTORY_SEPARATOR));
    }

    private function manifest(string $payload): ?array
    {
        foreach (['module.json', 'extension.json', 'manifest.json'] as $name) {
            $path = $payload.'/'.$name;
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (is_array($data)) {
                return ['path' => $name, 'data' => $data];
            }
        }

        return null;
    }
}
