<?php

namespace App\Support\Addons\Registry;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ArtifactStagingManager
{
    public function __construct(
        private readonly RegistryCatalog $catalog,
        private readonly ArtifactReviewManager $reviews,
        private readonly ArchiveSafetyValidator $validator,
        private readonly SafeArchiveExtractor $extractor,
    ) {}

    public function stage(string $code, ArtifactReviewActor $actor): ArtifactStagingResult
    {
        $resolved = $this->resolve($code);
        if ($resolved === null) {
            return ArtifactStagingResult::failure($code, null, ArtifactStagingStatus::BLOCKED, 'Artifact не знайдено.', ['Artifact не знайдено у quarantine.']);
        }
        $reasons = $this->blockedReasons($resolved);
        if ($reasons !== []) {
            return ArtifactStagingResult::failure($code, $resolved['version'], ArtifactStagingStatus::BLOCKED, 'Staging заблоковано.', $reasons);
        }

        $config = Config::get('addons-registry.staging', []);
        $storage = Storage::disk($resolved['disk']);
        $validation = $this->validator->validate($storage->path($resolved['artifact_path']), $config);
        if (! $validation['success']) {
            return ArtifactStagingResult::failure($code, $resolved['version'], ArtifactStagingStatus::REJECTED, 'Archive safety validation не пройдена.', $validation['diagnostics']);
        }

        $sha = hash_file('sha256', $storage->path($resolved['artifact_path']));
        $root = trim((string) ($config['path'] ?? 'addons/staging'), '/');
        $final = $root.'/'.$code.'/'.$resolved['version'].'/'.$sha;
        if ($storage->exists($final.'/staging.json')) {
            $existing = json_decode($storage->get($final.'/staging.json'), true);
            if (is_array($existing) && ($existing['source']['artifact_sha256'] ?? null) === $sha && ($existing['code'] ?? null) === $code) {
                return ArtifactStagingResult::success($code, $resolved['version'], 'Artifact уже підготовлений у staging.', $this->resultData($final, $existing));
            }

            return ArtifactStagingResult::failure($code, $resolved['version'], ArtifactStagingStatus::FAILED, 'Конфлікт існуючого staging directory.', ['staging.json не відповідає artifact.']);
        }
        if ($storage->exists($final)) {
            return ArtifactStagingResult::failure($code, $resolved['version'], ArtifactStagingStatus::FAILED, 'Існує partial staging directory.', ['Спочатку виконайте unstage.']);
        }

        $tmp = $root.'/.tmp/'.str()->uuid();
        try {
            $storage->makeDirectory($tmp.'/payload');
            $extracted = $this->extractor->extract($storage->path($resolved['artifact_path']), $storage->path($tmp.'/payload'), $validation['inventory'], $config);
            $inventory = array_map(fn ($e) => ['path' => $e['path'], 'type' => $e['type'], 'size' => $e['size'], 'sha256' => $e['sha256']], $extracted['inventory']);
            usort($inventory, fn ($a, $b) => strcmp($a['path'], $b['path']));
            $inventoryHash = hash('sha256', $this->canonical($inventory));
            $snapshot = $resolved['review']['approved_integrity_snapshot'] ?? [];
            $metadata = [
                'schema_version' => 1, 'status' => ArtifactStagingStatus::STAGED, 'code' => $code, 'version' => $resolved['version'],
                'source' => ['quarantine_path' => $resolved['artifact_path'], 'artifact_filename' => basename($resolved['artifact_path']), 'artifact_sha256' => $sha, 'artifact_size' => filesize($storage->path($resolved['artifact_path']))],
                'security' => ['signature_status' => $resolved['report']['signature_status'], 'signature_key_id' => $snapshot['signature_key_id'] ?? null, 'manifest_status' => $resolved['report']['manifest_status'], 'trust_status' => $resolved['report']['trust_status'], 'review_status' => $resolved['report']['review_status'], 'approval_is_stale' => $resolved['report']['approval_is_stale']],
                'staged_at' => now()->toIso8601String(), 'staged_by' => $actor->id, 'staged_by_name' => $actor->name, 'staged_by_type' => $actor->type,
                'file_count' => $extracted['file_count'], 'total_uncompressed_size' => $extracted['total_size'], 'manifest_path' => $validation['manifest_path'],
                'fingerprint' => ['approval_snapshot_hash' => hash('sha256', $this->canonical($snapshot)), 'inventory_hash' => $inventoryHash], 'inventory' => $inventory,
            ];
            $storage->put($tmp.'/staging.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $finalAbsolute = $storage->path($final);
            if (! is_dir(dirname($finalAbsolute))) {
                mkdir(dirname($finalAbsolute), 0755, true);
            }
            if (! rename($storage->path($tmp), $finalAbsolute)) {
                throw new RuntimeException('Atomic staging move failed.');
            }
            $this->updateSummary($resolved, $final, $metadata, false);

            return ArtifactStagingResult::success($code, $resolved['version'], 'Artifact підготовлено у staging.', $this->resultData($final, $metadata));
        } catch (\Throwable $e) {
            $this->deleteDirectory($storage->path($tmp));

            return ArtifactStagingResult::failure($code, $resolved['version'], ArtifactStagingStatus::FAILED, 'Staging не виконано.', [$e->getMessage()]);
        }
    }

    public function unstage(string $code, ?string $note, ArtifactReviewActor $actor): ArtifactStagingResult
    {
        $resolved = $this->resolve($code);
        if ($resolved === null) {
            return ArtifactStagingResult::failure($code, null, ArtifactStagingStatus::FAILED, 'Artifact не знайдено.');
        }
        $path = $resolved['review']['staging_path'] ?? null;
        $root = trim((string) Config::get('addons-registry.staging.path', 'addons/staging'), '/');
        if (! is_string($path) || ! str_starts_with($path.'/', $root.'/'.$code.'/'.$resolved['version'].'/')) {
            return ArtifactStagingResult::failure($code, $resolved['version'], ArtifactStagingStatus::BLOCKED, 'Unsafe staging path.', ['Path не належить configured staging root.']);
        }
        $storage = Storage::disk($resolved['disk']);
        $staging = $storage->exists($path.'/staging.json') ? json_decode($storage->get($path.'/staging.json'), true) : null;
        if (! is_array($staging) || ($staging['code'] ?? null) !== $code || ($staging['version'] ?? null) !== $resolved['version']) {
            return ArtifactStagingResult::failure($code, $resolved['version'], ArtifactStagingStatus::FAILED, 'Invalid staging metadata.');
        }
        $storage->deleteDirectory($path);
        $this->updateSummary($resolved, null, [], false);

        return new ArtifactStagingResult(true, $code, $resolved['version'], ArtifactStagingStatus::NOT_STAGED, 'Staging copy видалено; quarantine збережено.');
    }

    public function getStagingReport(string $code): array
    {
        return $this->resolve($code) ?? ['code' => $code, 'status' => ArtifactStagingStatus::NOT_STAGED];
    }

    public function canStage(string $code): bool
    {
        $r = $this->resolve($code);

        return $r !== null && $this->blockedReasons($r) === [];
    }

    public function getStageBlockedReasons(string $code): array
    {
        $r = $this->resolve($code);

        return $r === null ? ['Artifact не знайдено.'] : $this->blockedReasons($r);
    }

    public function isStagingStale(string $code): bool
    {
        $r = $this->resolve($code);

        return (bool) ($r['review']['staging_is_stale'] ?? false) || (bool) ($r['report']['approval_is_stale'] ?? false);
    }

    private function resolve(string $code): ?array
    {
        $item = collect($this->catalog->load()['items'] ?? [])->first(fn ($i) => $i->code === $code);
        if ($item === null || ! is_array($item->raw['artifact'] ?? null)) {
            return null;
        }
        $artifact = $item->raw['artifact'];
        $disk = (string) Config::get('addons-registry.downloads.disk', 'addons');
        $dir = trim((string) Config::get('addons-registry.downloads.quarantine_path', 'addons/quarantine'), '/').'/'.$code.'/'.$item->version;
        $path = $dir.'/'.basename(parse_url($artifact['url'], PHP_URL_PATH) ?: $code.'.zip');
        $metadataPath = $dir.'/metadata.json';
        $storage = Storage::disk($disk);
        if (! $storage->exists($path) || ! $storage->exists($metadataPath)) {
            return null;
        }
        $metadata = json_decode($storage->get($metadataPath), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $reviewResult = $this->reviews->getReviewReport($code);
        if (! $reviewResult['success']) {
            return null;
        }

        return ['code' => $code, 'version' => $item->version, 'disk' => $disk, 'artifact_path' => $path, 'metadata_path' => $metadataPath, 'review' => $metadata, 'report' => $reviewResult['report']];
    }

    private function blockedReasons(array $r): array
    {
        $c = Config::get('addons-registry.staging', []);
        $reasons = [];
        if (! ($c['enabled'] ?? false)) {
            $reasons[] = 'Staging вимкнено.';
        }
        if (($r['review']['status'] ?? '') !== 'quarantined') {
            $reasons[] = 'Artifact не перебуває у quarantine.';
        }
        if (($c['require_trusted'] ?? true) && $r['report']['trust_status'] !== 'trusted') {
            $reasons[] = 'Artifact не trusted.';
        }
        if (($c['require_approved'] ?? true) && $r['report']['review_status'] !== 'approved') {
            $reasons[] = 'Artifact не approved.';
        }
        if (($c['block_stale_approval'] ?? true) && $r['report']['approval_is_stale']) {
            $reasons[] = 'Approval stale.';
        }

        return $reasons;
    }

    private function updateSummary(array $r, ?string $path, array $staging, bool $stale): void
    {
        $m = $r['review'];
        $m['staging_status'] = $path ? ArtifactStagingStatus::STAGED : ArtifactStagingStatus::NOT_STAGED;
        $m['staging_path'] = $path;
        $m['staged_at'] = $staging['staged_at'] ?? null;
        $m['staged_by'] = $staging['staged_by'] ?? null;
        $m['staged_by_name'] = $staging['staged_by_name'] ?? null;
        $m['staging_artifact_sha256'] = $staging['source']['artifact_sha256'] ?? null;
        $m['staging_inventory_hash'] = $staging['fingerprint']['inventory_hash'] ?? null;
        $m['staging_diagnostics'] = [];
        $m['staging_is_stale'] = $stale;
        Storage::disk($r['disk'])->put($r['metadata_path'], json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function resultData(string $path, array $m): array
    {
        return ['staging_path' => $path, 'file_count' => $m['file_count'] ?? 0, 'total_size' => $m['total_uncompressed_size'] ?? 0, 'manifest_path' => $m['manifest_path'] ?? null, 'inventory' => $m['inventory'] ?? [], 'metadata' => $m];
    }

    private function canonical(array $value): string
    {
        if (array_is_list($value)) {
            return json_encode(array_map(fn ($v) => is_array($v) ? json_decode($this->canonical($v), true) : $v, $value), JSON_UNESCAPED_SLASHES);
        } ksort($value);
        foreach ($value as &$v) {
            if (is_array($v)) {
                $v = json_decode($this->canonical($v), true);
            }
        }

return json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        } foreach (array_diff(scandir($path), ['.', '..']) as $f) {
            $p = $path.'/'.$f;
            is_dir($p) ? $this->deleteDirectory($p) : @unlink($p);
        } @rmdir($path);
    }
}
