<?php

namespace App\Support\Addons\Registry;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

final class StagingIntegrityVerifier
{
    public function verify(string $stagingPath): array
    {
        $diagnostics = [];
        $staging = $this->readJson($stagingPath.'/staging.json');

        if ($staging === null) {
            return $this->failure('Staging metadata відсутня.', ['staging.json не знайдено.']);
        }

        if (($staging['schema_version'] ?? null) !== 1) {
            $diagnostics[] = 'Unsupported staging schema_version.';
        }

        foreach (['code', 'version', 'manifest_path'] as $field) {
            if (! is_string($staging[$field] ?? null) || trim((string) $staging[$field]) === '') {
                $diagnostics[] = "Staging metadata field [{$field}] is missing.";
            }
        }

        $payloadRoot = $stagingPath.'/payload';
        if (! is_dir($payloadRoot)) {
            return $this->failure('Staging payload is missing.', ['payload directory not found.']);
        }

        $disk = (string) Config::get('addons-registry.downloads.disk', 'addons');
        $storage = Storage::disk($disk);
        $sourcePath = (string) ($staging['source']['quarantine_path'] ?? '');
        $sourceAbsolutePath = $sourcePath !== '' ? $storage->path($sourcePath) : null;
        $sourceMetadata = $sourceAbsolutePath !== null ? $this->readJson(dirname($sourceAbsolutePath).'/metadata.json') : null;
        $sourceMetadata = is_array($sourceMetadata) ? $sourceMetadata : [];

        $manifestPath = (string) ($staging['manifest_path'] ?? '');
        $manifestFile = $payloadRoot.'/'.ltrim($manifestPath, '/');
        if (! is_file($manifestFile)) {
            $diagnostics[] = 'Staging manifest file is missing.';
        }

        $manifest = $this->readJson($manifestFile);
        if ($manifest === null) {
            $diagnostics[] = 'Staging manifest JSON is invalid or missing.';
        } else {
            if (($manifest['code'] ?? null) !== ($staging['code'] ?? null)) {
                $diagnostics[] = 'Manifest code does not match staging code.';
            }
            if (($manifest['version'] ?? null) !== ($staging['version'] ?? null)) {
                $diagnostics[] = 'Manifest version does not match staging version.';
            }
            if (array_key_exists('addon_type', $staging) && ($manifest['type'] ?? null) !== ($staging['addon_type'] ?? null)) {
                $diagnostics[] = 'Manifest type does not match staging addon type.';
            }
        }

        $actual = $this->inventoryForPath($payloadRoot, $diagnostics);
        $expected = is_array($staging['inventory'] ?? null) ? $staging['inventory'] : [];

        if ($expected === []) {
            $diagnostics[] = 'Staging inventory metadata is missing or invalid.';
        }

        if ($this->sortInventory($expected) !== $this->sortInventory($actual['inventory'])) {
            $diagnostics[] = 'Staging inventory does not match payload tree.';
        }

        if (($staging['file_count'] ?? null) !== $actual['file_count']) {
            $diagnostics[] = 'Staging file_count does not match actual payload files.';
        }

        if (($staging['total_uncompressed_size'] ?? null) !== $actual['total_size']) {
            $diagnostics[] = 'Staging total size does not match actual payload size.';
        }

        $inventoryHash = hash('sha256', $this->canonical($this->sortInventory($actual['inventory'])));
        if (($staging['fingerprint']['inventory_hash'] ?? null) !== $inventoryHash) {
            $diagnostics[] = 'Staging inventory fingerprint mismatch.';
        }

        $approvalSnapshotHash = hash('sha256', $this->canonical($sourceMetadata['approved_integrity_snapshot'] ?? []));
        if (($staging['fingerprint']['approval_snapshot_hash'] ?? null) !== $approvalSnapshotHash) {
            $diagnostics[] = 'Staging approval snapshot fingerprint mismatch.';
        }

        $stale = false;
        if (($sourceMetadata['status'] ?? null) !== 'quarantined') {
            $diagnostics[] = 'Quarantine artifact is no longer quarantined.';
            $stale = true;
        }

        if (($sourceMetadata['review_status'] ?? null) !== ArtifactReviewStatus::APPROVED) {
            $diagnostics[] = 'Promotion source review status is not approved.';
            $stale = true;
        }

        if ((bool) ($sourceMetadata['approval_is_stale'] ?? false) || (bool) ($sourceMetadata['staging_is_stale'] ?? false)) {
            $diagnostics[] = 'Promotion source review/staging is stale.';
            $stale = true;
        }

        if (($sourceMetadata['staging_status'] ?? null) !== ArtifactStagingStatus::STAGED) {
            $diagnostics[] = 'Promotion source staging status is not staged.';
            $stale = true;
        }

        if ($sourceAbsolutePath !== null && is_file($sourceAbsolutePath)) {
            $actualSha = hash_file('sha256', $sourceAbsolutePath) ?: null;
            if ($actualSha !== ($staging['source']['artifact_sha256'] ?? null)) {
                $diagnostics[] = 'Quarantine artifact SHA-256 mismatch.';
            }
        }

        return [
            'success' => $diagnostics === [],
            'staging' => $staging,
            'manifest' => $manifest,
            'payload_path' => $payloadRoot,
            'inventory' => $this->sortInventory($actual['inventory']),
            'inventory_hash' => $inventoryHash,
            'file_count' => $actual['file_count'],
            'total_size' => $actual['total_size'],
            'source_metadata' => $sourceMetadata,
            'staging_is_stale' => $stale,
            'diagnostics' => array_values(array_unique($diagnostics)),
        ];
    }

    /**
     * @return array{inventory: array<int, array<string, mixed>>, file_count: int, total_size: int}
     */
    private function inventoryForPath(string $path, array &$diagnostics): array
    {
        $inventory = [];
        $fileCount = 0;
        $totalSize = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            $absolute = $entry->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen(rtrim($path, '/\\')))), '/');

            if ($relative === '') {
                continue;
            }

            if ($entry->isLink()) {
                $diagnostics[] = 'Symlink is forbidden in staging payload: '.$relative;
                continue;
            }

            if (! $entry->isDir() && ! $entry->isFile()) {
                $diagnostics[] = 'Special file is forbidden in staging payload: '.$relative;
                continue;
            }

            if ($entry->isDir()) {
                continue;
            }

            $fileCount++;
            $size = $entry->getSize() ?: 0;
            $totalSize += $size;
            $inventory[] = ['path' => $relative, 'type' => 'file', 'size' => $size, 'sha256' => hash_file('sha256', $absolute) ?: null];
        }

        return ['inventory' => $inventory, 'file_count' => $fileCount, 'total_size' => $totalSize];
    }

    /**
     * @param  array<int, array<string, mixed>>  $inventory
     * @return array<int, array<string, mixed>>
     */
    private function sortInventory(array $inventory): array
    {
        usort($inventory, fn (array $left, array $right): int => strcmp((string) $left['path'], (string) $right['path']));

        return array_map(static fn (array $entry): array => [
            'path' => $entry['path'],
            'type' => $entry['type'],
            'size' => $entry['size'],
            'sha256' => $entry['sha256'],
        ], $inventory);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonical(array $value): string
    {
        if (array_is_list($value)) {
            return json_encode(array_map(fn ($item) => is_array($item) ? json_decode($this->canonical($item), true) : $item, $value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = json_decode($this->canonical($item), true);
            }
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function readJson(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $message, array $diagnostics): array
    {
        return [
            'success' => false,
            'staging' => null,
            'manifest' => null,
            'payload_path' => null,
            'inventory' => [],
            'inventory_hash' => null,
            'file_count' => 0,
            'total_size' => 0,
            'source_metadata' => null,
            'staging_is_stale' => true,
            'diagnostics' => array_values(array_unique([$message, ...$diagnostics])),
        ];
    }
}