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
            return $this->failure('artifact_staging_metadata_invalid', 'Staging metadata is invalid.', [
                $this->diagnostic('artifact_staging_metadata_invalid', 'Staging metadata file is missing.', ['staging.json was not found.']),
            ]);
        }

        if (($staging['schema_version'] ?? null) !== 1) {
            $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Unsupported staging schema version.', ['schema_version must be 1.']);
        }

        foreach (['code', 'version', 'manifest_path'] as $field) {
            if (! is_string($staging[$field] ?? null) || trim((string) $staging[$field]) === '') {
                $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Staging metadata field is missing.', ["field={$field}"]);
            }
        }

        if ($diagnostics !== []) {
            return $this->failure('artifact_staging_metadata_invalid', 'Staging metadata is invalid.', $diagnostics);
        }

        $payloadRoot = $stagingPath.'/payload';
        if (! is_dir($payloadRoot)) {
            return $this->failure('artifact_staging_file_missing', 'Staging payload is missing.', [
                $this->diagnostic('artifact_staging_file_missing', 'Staging payload directory is missing.', ['payload directory not found.']),
            ]);
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
            $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Staging manifest JSON is invalid or missing.', ['manifest.json could not be parsed.']);
        } else {
            if (($manifest['code'] ?? null) !== ($staging['code'] ?? null)) {
                $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Manifest code does not match staging code.', [
                    'manifest_code='.($manifest['code'] ?? 'null'),
                    'staging_code='.$staging['code'],
                ]);
            }
            if (($manifest['version'] ?? null) !== ($staging['version'] ?? null)) {
                $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Manifest version does not match staging version.', [
                    'manifest_version='.($manifest['version'] ?? 'null'),
                    'staging_version='.$staging['version'],
                ]);
            }
            if (array_key_exists('addon_type', $staging) && ($manifest['type'] ?? null) !== ($staging['addon_type'] ?? null)) {
                $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Manifest type does not match staging addon type.', [
                    'manifest_type='.($manifest['type'] ?? 'null'),
                    'staging_addon_type='.($staging['addon_type'] ?? 'null'),
                ]);
            }
        }

        $actual = $this->inventoryForPath($payloadRoot, $diagnostics);
        $expected = is_array($staging['inventory'] ?? null) ? $staging['inventory'] : [];

        if ($expected === []) {
            return $this->failure('artifact_staging_metadata_invalid', 'Staging inventory metadata is invalid.', [
                $this->diagnostic('artifact_staging_metadata_invalid', 'Staging inventory metadata is missing or invalid.', []),
            ]);
        }

        $expectedMap = $this->inventoryMap($expected);
        $actualMap = $this->inventoryMap($actual['inventory']);
        $inventoryMismatch = false;

        foreach ($expectedMap as $path => $expectedEntry) {
            if (! array_key_exists($path, $actualMap)) {
                $diagnostics[] = $this->diagnostic('artifact_staging_file_missing', 'Staged file is missing.', ["path={$path}"]);
                $inventoryMismatch = true;
                continue;
            }

            $actualEntry = $actualMap[$path];
            if ($this->entriesDiffer($expectedEntry, $actualEntry)) {
                $diagnostics[] = $this->diagnostic('artifact_staging_file_modified', 'Staged file was modified.', ["path={$path}"]);
                $inventoryMismatch = true;
            }
        }

        foreach ($actualMap as $path => $actualEntry) {
            if (! array_key_exists($path, $expectedMap)) {
                $diagnostics[] = $this->diagnostic('artifact_staging_file_extra', 'Unexpected staged file found.', ["path={$path}"]);
                $inventoryMismatch = true;
            }
        }

        if (($staging['file_count'] ?? null) !== $actual['file_count']) {
            $inventoryMismatch = true;
        }

        if (($staging['total_uncompressed_size'] ?? null) !== $actual['total_size']) {
            $inventoryMismatch = true;
        }

        $inventoryHash = hash('sha256', $this->canonical($this->sortInventory($actual['inventory'])));
        if (($staging['fingerprint']['inventory_hash'] ?? null) !== $inventoryHash) {
            $diagnostics[] = $this->diagnostic('artifact_staging_inventory_mismatch', 'Staging inventory fingerprint mismatch.', [
                'expected='.($staging['fingerprint']['inventory_hash'] ?? 'null'),
                'actual='.$inventoryHash,
            ]);
        }

        if ($inventoryMismatch) {
            $diagnostics[] = $this->diagnostic('artifact_staging_inventory_mismatch', 'Staging inventory does not match payload tree.', [
                'expected_count='.(string) count($expectedMap),
                'actual_count='.(string) count($actualMap),
            ]);
        }

        $approvalSnapshotHash = hash('sha256', $this->canonical($sourceMetadata['approved_integrity_snapshot'] ?? []));
        if (($staging['fingerprint']['approval_snapshot_hash'] ?? null) !== $approvalSnapshotHash) {
            $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Staging approval snapshot fingerprint mismatch.', [
                'expected='.($staging['fingerprint']['approval_snapshot_hash'] ?? 'null'),
                'actual='.$approvalSnapshotHash,
            ]);
        }

        $stale = false;
        if (($sourceMetadata['status'] ?? null) !== 'quarantined') {
            $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Quarantine artifact is no longer quarantined.', []);
            $stale = true;
        }

        if (($sourceMetadata['review_status'] ?? null) !== ArtifactReviewStatus::APPROVED) {
            $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Promotion source review status is not approved.', []);
            $stale = true;
        }

        if ((bool) ($sourceMetadata['approval_is_stale'] ?? false) || (bool) ($sourceMetadata['staging_is_stale'] ?? false)) {
            $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Promotion source review/staging is stale.', []);
            $stale = true;
        }

        if (($sourceMetadata['staging_status'] ?? null) !== ArtifactStagingStatus::STAGED) {
            $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Promotion source staging status is not staged.', []);
            $stale = true;
        }

        if ($sourceAbsolutePath !== null && is_file($sourceAbsolutePath)) {
            $actualSha = hash_file('sha256', $sourceAbsolutePath) ?: null;
            if ($actualSha !== ($staging['source']['artifact_sha256'] ?? null)) {
                $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Quarantine artifact SHA-256 mismatch.', [
                    'expected='.($staging['source']['artifact_sha256'] ?? 'null'),
                    'actual='.$actualSha,
                ]);
            }
        }

        if ($inventoryMismatch && ! array_filter($diagnostics, static fn (array $diagnostic): bool => $diagnostic['code'] === 'artifact_staging_inventory_mismatch')) {
            $diagnostics[] = $this->diagnostic('artifact_staging_inventory_mismatch', 'Staging inventory mismatch detected.', []);
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
            'diagnostics' => array_values($this->uniqueDiagnostics($diagnostics)),
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
                $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Symlink is forbidden in staging payload.', ["path={$relative}"]);
                continue;
            }

            if (! $entry->isDir() && ! $entry->isFile()) {
                $diagnostics[] = $this->diagnostic('artifact_staging_metadata_invalid', 'Special file is forbidden in staging payload.', ["path={$relative}"]);
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
     * @param  array<int, array{path: string, type: string, size: int, sha256: ?string}>  $inventory
     * @return array<string, array{path: string, type: string, size: int, sha256: ?string}>
     */
    private function inventoryMap(array $inventory): array
    {
        $map = [];

        foreach ($inventory as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
                continue;
            }

            $map[(string) $entry['path']] = [
                'path' => (string) $entry['path'],
                'type' => (string) ($entry['type'] ?? 'file'),
                'size' => (int) ($entry['size'] ?? 0),
                'sha256' => isset($entry['sha256']) ? (string) $entry['sha256'] : null,
            ];
        }

        return $map;
    }

    /**
     * @param  array{path: string, type: string, size: int, sha256: ?string}  $expected
     * @param  array{path: string, type: string, size: int, sha256: ?string}  $actual
     */
    private function entriesDiffer(array $expected, array $actual): bool
    {
        return $expected['type'] !== $actual['type']
            || $expected['size'] !== $actual['size']
            || $expected['sha256'] !== $actual['sha256'];
    }

    /**
     * @param  array<int, array{code: string, message: string, details: array<int, string>}>  $diagnostics
     * @return array<int, array{code: string, message: string, details: array<int, string>}>
     */
    private function uniqueDiagnostics(array $diagnostics): array
    {
        $unique = [];

        foreach ($diagnostics as $diagnostic) {
            if (! is_array($diagnostic) || ! isset($diagnostic['code'], $diagnostic['message'])) {
                continue;
            }

            $key = $diagnostic['code'].'|'.$diagnostic['message'].'|'.json_encode($diagnostic['details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $unique[$key] = [
                'code' => (string) $diagnostic['code'],
                'message' => (string) $diagnostic['message'],
                'details' => array_values(array_map('strval', (array) ($diagnostic['details'] ?? []))),
            ];
        }

        return array_values($unique);
    }

    /**
     * @return array{code: string, message: string, details: array<int, string>}
     */
    private function diagnostic(string $code, string $message, array $details = []): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'details' => array_values(array_map('strval', $details)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $code, string $message, array $diagnostics): array
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
            'diagnostics' => array_values($this->uniqueDiagnostics([
                $this->diagnostic($code, $message),
                ...$diagnostics,
            ])),
        ];
    }
}