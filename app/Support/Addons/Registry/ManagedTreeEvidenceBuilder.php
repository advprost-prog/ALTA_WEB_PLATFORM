<?php

namespace App\Support\Addons\Registry;

final class ManagedTreeEvidenceBuilder
{
    public function __construct(
        private readonly ManagedTreeInventory $inventory,
        private readonly BackupIntegrityService $backups,
        private readonly StagingIntegrityVerifier $staging,
    ) {}

    public function inspect(string $kind, ?string $path, string $root, array $expected = []): ManagedTreeEvidence
    {
        if ($path === null) {
            return new ManagedTreeEvidence($kind, 'missing', 'not_verifiable', 'not_applicable');
        }
        if ($this->symlinkInPath($path, $root)) {
            return $this->failure($kind, 'not_verifiable', 'symlink_conflict', 'tree_symlink_conflict', 'Managed tree has a symlink conflict.');
        }
        if (! file_exists($path)) {
            return new ManagedTreeEvidence($kind, 'missing', 'not_verifiable', 'not_applicable');
        }
        if (! $this->inside($path, $root)) {
            return $this->failure($kind, 'not_verifiable', 'unmanaged', 'tree_unmanaged_path', 'Managed tree is outside its configured root.');
        }
        if ($kind === 'backup') {
            return $this->backup($path, $expected);
        }
        if ($kind === 'staging') {
            return $this->staged($path, $expected);
        }

        return $this->tree($kind, $path, $expected, $kind === 'candidate' ? '.candidate-evidence.json' : null);
    }

    private function tree(string $kind, string $path, array $expected, ?string $ownershipFile): ManagedTreeEvidence
    {
        $ownership = 'managed';
        $operationId = null;
        if ($ownershipFile !== null) {
            $metadata = $this->json($path.'/'.$ownershipFile);
            $operationId = is_string($metadata['operation_id'] ?? null) ? $metadata['operation_id'] : null;
            if ($metadata === null || $operationId !== ($expected['operation_id'] ?? null)
                || ($metadata['code'] ?? null) !== ($expected['code'] ?? null)) {
                $ownership = 'ownership_mismatch';
            }
        }
        try {
            $manifest = $this->manifest($path);
            $tree = $this->inventory->build($path);
        } catch (\Throwable) {
            return $this->failure($kind, 'integrity_failed', $ownership, 'tree_integrity_failed', 'Managed tree integrity verification failed.');
        }
        if ($manifest === null || (($expected['code'] ?? null) !== null && $manifest['data']['code'] !== $expected['code'])) {
            return $this->failure($kind, 'integrity_failed', $ownership, 'tree_manifest_invalid', 'Managed tree manifest is invalid.');
        }
        $digest = hash('sha256', json_encode($manifest['data'], JSON_UNESCAPED_SLASHES));
        if (($expected['inventory_digest'] ?? null) !== null && $tree['inventory_digest'] !== $expected['inventory_digest']) {
            return $this->failure($kind, 'integrity_failed', $ownership, 'tree_inventory_mismatch', 'Managed tree inventory does not match expected evidence.');
        }
        if ($ownershipFile !== null && (($metadata['inventory_digest'] ?? null) !== $tree['inventory_digest'] || ($metadata['version'] ?? null) !== ($manifest['data']['version'] ?? null))) {
            return $this->failure($kind, 'integrity_failed', $ownership, 'tree_inventory_mismatch', 'Candidate evidence does not match its managed tree.');
        }

        return new ManagedTreeEvidence($kind, 'present', 'verified', $ownership, $manifest['data']['code'] ?? null,
            $manifest['data']['version'] ?? null, $manifest['data']['type'] ?? null, $manifest['data']['vendor'] ?? null,
            $operationId, $digest, $tree['inventory_digest'], $tree['file_count'], $tree['total_bytes'], 'tree_verified', 'Managed tree is verified.');
    }

    private function backup(string $path, array $expected): ManagedTreeEvidence
    {
        $result = $this->backups->verify($path);
        $status = (string) ($result['status'] ?? 'invalid');
        if ($status !== 'verified') {
            $integrity = $status === 'legacy_unverified' ? 'legacy_unverified' : 'integrity_failed';
            $ownership = $status === 'unmanaged' ? 'unmanaged' : ($status === 'symlink_conflict' ? 'symlink_conflict' : 'managed');

            return $this->failure('backup', $integrity, $ownership, 'backup_'.$status, 'Backup evidence could not be verified.');
        }
        $record = $result['record'];
        $ownership = ($expected['operation_id'] ?? null) !== null && ($record['source_operation_id'] ?? null) !== $expected['operation_id'] ? 'ownership_mismatch' : 'managed';

        return new ManagedTreeEvidence('backup', 'present', 'verified', $ownership, $record['code'] ?? null, $record['version'] ?? null,
            $record['type'] ?? null, $record['vendor'] ?? null, $record['source_operation_id'] ?? null, $record['manifest_digest'] ?? null,
            $record['inventory_digest'] ?? null, (int) ($record['file_count'] ?? 0), (int) ($record['total_bytes'] ?? 0), 'tree_verified', 'Backup tree is verified.');
    }

    private function staged(string $path, array $expected): ManagedTreeEvidence
    {
        $result = $this->staging->verify($path);
        $metadata = is_array($result['staging'] ?? null) ? $result['staging'] : [];
        $operationId = is_string($metadata['operation_id'] ?? null) ? $metadata['operation_id'] : ($expected['operation_id'] ?? null);
        $ownership = ($expected['operation_id'] ?? null) !== null && $operationId !== $expected['operation_id'] ? 'ownership_mismatch' : 'managed';
        if (! ($result['success'] ?? false)) {
            return $this->failure('staging', 'integrity_failed', $ownership, 'staging_integrity_failed', 'Staging tree integrity verification failed.');
        }
        $manifest = $result['manifest'];

        return new ManagedTreeEvidence('staging', 'present', 'verified', $ownership, $manifest['code'] ?? null, $manifest['version'] ?? null,
            $manifest['type'] ?? null, $manifest['vendor'] ?? null, $operationId,
            hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES)), $result['inventory_hash'] ?? null,
            (int) ($result['file_count'] ?? 0), (int) ($result['total_size'] ?? 0), 'tree_verified', 'Staging tree is verified.');
    }

    private function inside(string $path, string $root): bool
    {
        $realRoot = realpath($root);
        $realPath = realpath($path);

        return $realRoot !== false && $realPath !== false && ($realPath === $realRoot || str_starts_with($realPath, $realRoot.DIRECTORY_SEPARATOR));
    }

    private function symlinkInPath(string $path, string $root): bool
    {
        for ($current = $path; strlen($current) >= strlen($root); $current = dirname($current)) {
            if (is_link($current)) {
                return true;
            }
            if ($current === $root || dirname($current) === $current) {
                break;
            }
        }

        return false;
    }

    private function manifest(string $path): ?array
    {
        foreach (['module.json', 'extension.json', 'manifest.json'] as $name) {
            $data = $this->json($path.'/'.$name);
            if (is_array($data) && is_string($data['code'] ?? null) && is_string($data['version'] ?? null)) {
                return ['data' => $data];
            }
        }

        return null;
    }

    private function json(string $path): ?array
    {
        if (! is_file($path) || is_link($path)) {
            return null;
        }
        $value = json_decode((string) file_get_contents($path), true);

        return is_array($value) ? $value : null;
    }

    private function failure(string $kind, string $integrity, string $ownership, string $code, string $message): ManagedTreeEvidence
    {
        return new ManagedTreeEvidence($kind, 'present', $integrity, $ownership, diagnosticCode: $code, diagnosticMessage: $message);
    }
}
