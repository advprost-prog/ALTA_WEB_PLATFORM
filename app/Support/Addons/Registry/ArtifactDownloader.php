<?php

namespace App\Support\Addons\Registry;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ArtifactDownloader
{
    public function __construct(
        private readonly RegistryClient $client,
        private readonly array $config,
        private readonly ArtifactValidator $validator = new ArtifactValidator,
    ) {}

    public function download(RegistryItem $item): ArtifactDownloadResult
    {
        $downloadsConfig = $this->config['downloads'] ?? [];
        $downloadsEnabled = (bool) ($downloadsConfig['enabled'] ?? false);

        if (! $downloadsEnabled) {
            return ArtifactDownloadResult::failed('downloads_disabled', ['Downloads are disabled.']);
        }

        $artifact = $this->validator->normalizedArtifact($item->raw['artifact'] ?? []);

        $metadataIssues = $this->validator->validateMetadata($artifact);

        if ($metadataIssues !== []) {
            return ArtifactDownloadResult::failed('not_available', $metadataIssues);
        }

        $host = parse_url($artifact['url'], PHP_URL_HOST) ?: $artifact['url'];

        if (! $this->client->isHostAllowed($host)) {
            return ArtifactDownloadResult::failed('host_not_allowed', ["Registry host [{$host}] is not allowed."]);
        }

        $quarantinePath = (string) ($downloadsConfig['quarantine_path'] ?? 'addons/quarantine');
        $disk = (string) ($downloadsConfig['disk'] ?? 'local');
        $maxSize = (int) ($downloadsConfig['max_size'] ?? 20 * 1024 * 1024);

        if ($artifact['size'] > $maxSize) {
            return ArtifactDownloadResult::failed('failed', ['Artifact size exceeds maximum allowed size.']);
        }

        $filename = basename(parse_url($artifact['url'], PHP_URL_PATH) ?: $item->code.'.zip');
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($extension !== 'zip') {
            return ArtifactDownloadResult::failed('not_available', ['Unsupported artifact extension: '.$extension]);
        }
        $directory = rtrim($quarantinePath.'/'.$item->code.'/'.$item->version, '/');
        $path = $directory.'/'.$filename;
        $metadataPath = $directory.'/metadata.json';

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 5))
                ->withOptions(['verify' => (bool) ($this->config['verify_ssl'] ?? true)])
                ->acceptJson()
                ->get($artifact['url']);
        } catch (\Throwable $exception) {
            return ArtifactDownloadResult::failed('failed', ['Download failed: '.$exception->getMessage()]);
        }

        if (! $response->successful()) {
            return ArtifactDownloadResult::failed('failed', ['HTTP error: '.$response->status()]);
        }

        $body = $response->body();

        if (strlen($body) > $maxSize) {
            return ArtifactDownloadResult::failed('failed', ['Downloaded file exceeds maximum allowed size.']);
        }

        $calculatedHash = hash('sha256', $body);

        if ($calculatedHash !== $artifact['sha256']) {
            return ArtifactDownloadResult::failed('rejected', ['Checksum mismatch.']);
        }

        $diskInstance = Storage::disk($disk);

        if (! $diskInstance->put($path, $body)) {
            return ArtifactDownloadResult::failed('failed', ['Failed to store artifact.']);
        }

        $metadata = [
            'code' => $item->code,
            'version' => $item->version,
            'source_url' => $artifact['url'],
            'sha256' => $artifact['sha256'],
            'size' => $artifact['size'],
            'downloaded_at' => now()->toIso8601String(),
            'status' => 'quarantined',
        ];

        $diskInstance->put($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ArtifactDownloadResult::success($path, $metadataPath, $metadata);
    }
}
