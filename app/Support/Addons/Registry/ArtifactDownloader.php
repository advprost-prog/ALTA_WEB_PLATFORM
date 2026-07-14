<?php

namespace App\Support\Addons\Registry;

use Illuminate\Support\Facades\Cache;
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
        $downloads = (array) ($this->config['downloads'] ?? []);
        if (! (bool) ($downloads['enabled'] ?? false)) {
            return ArtifactDownloadResult::failed('downloads_disabled', ['Downloads are disabled.']);
        }
        $artifact = $this->validator->normalizedArtifact($item->raw['artifact'] ?? []);
        if (($issues = $this->validator->validateMetadata($artifact)) !== []) {
            return ArtifactDownloadResult::failed('not_available', $issues);
        }
        if (! $this->client->isUrlAllowed($artifact['url'])) {
            return ArtifactDownloadResult::failed('unsafe_artifact_url', ['Artifact URL is not allowed.']);
        }

        $publisherId = is_string($item->publisher['public_id'] ?? null) ? $item->publisher['public_id'] : '';
        $max = (int) ($downloads['max_size'] ?? 20 * 1024 * 1024);
        if ($artifact['size'] > $max) {
            return ArtifactDownloadResult::failed('size_limit_exceeded', ['Artifact exceeds the configured size limit.']);
        }
        $lockName = 'artifact-acquisition:'.hash('sha256', $publisherId.'|'.$item->code.'|'.$item->version.'|'.$artifact['sha256']);
        $lock = Cache::lock($lockName, max(10, (int) ($this->config['timeout'] ?? 5) + 5));
        if (! $lock->get()) {
            return ArtifactDownloadResult::failed('lock_unavailable', ['Artifact acquisition is already in progress.']);
        }

        try {
            return $this->acquire($item, $artifact, $publisherId, $downloads, $max);
        } finally {
            $lock->release();
        }
    }

    private function acquire(RegistryItem $item, array $artifact, string $publisherId, array $downloads, int $max): ArtifactDownloadResult
    {
        $disk = Storage::disk((string) ($downloads['disk'] ?? 'local'));
        $root = trim((string) ($downloads['quarantine_path'] ?? 'addons/quarantine'), '/');
        $directory = $root.'/'.$item->code.'/'.$item->version;
        $path = $directory.'/'.self::safeFilename($item->code, $item->version);
        $metadataPath = $directory.'/metadata.json';
        $tempDirectory = $root.'/.tmp';
        $disk->makeDirectory($tempDirectory);
        $temp = $tempDirectory.'/'.bin2hex(random_bytes(16)).'.part';
        $absoluteTemp = $disk->path($temp);
        $handle = @fopen($absoluteTemp, 'x+b');
        if (! is_resource($handle)) {
            return ArtifactDownloadResult::failed('local_write_failed', ['Cannot create quarantine temporary file.']);
        }
        @chmod($absoluteTemp, 0600);
        $publishedNewFile = false;
        $metadataTemp = null;

        try {
            $existing = $disk->exists($metadataPath) ? json_decode((string) $disk->get($metadataPath), true) : null;
            $localValid = is_array($existing) && ($existing['verification_state'] ?? null) === 'verified'
                && ($existing['publisher_public_id'] ?? null) === $publisherId
                && ($existing['code'] ?? null) === $item->code && ($existing['version'] ?? null) === $item->version
                && ($existing['sha256'] ?? null) === $artifact['sha256'] && ($existing['size'] ?? null) === $artifact['size']
                && $disk->exists($path) && filesize($disk->path($path)) === $artifact['size']
                && hash_file('sha256', $disk->path($path)) === $artifact['sha256'];
            $headers = ['Accept' => 'application/zip'];
            if ($localValid && is_string($existing['etag'] ?? null)) {
                $headers['If-None-Match'] = $existing['etag'];
            }
            if ($localValid && is_string($existing['last_modified'] ?? null)) {
                $headers['If-Modified-Since'] = $existing['last_modified'];
            }
            $sinkUsed = ! app()->environment('testing');
            $options = ['verify' => (bool) ($this->config['verify_ssl'] ?? true), 'allow_redirects' => false];
            if ($sinkUsed) {
                $options['sink'] = $handle;
                $options['progress'] = static function (int $total, int $downloaded) use ($artifact, $max): void {
                    if ($downloaded > $artifact['size'] || $downloaded > $max) {
                        throw new \RuntimeException('Artifact stream exceeded its size limit.');
                    }
                };
            }
            $request = fn (array $requestHeaders) => Http::connectTimeout(max(1, (int) ($this->config['connect_timeout'] ?? 3)))
                ->timeout(max(1, (int) ($this->config['timeout'] ?? 5)))
                ->withOptions($options)
                ->withHeaders($requestHeaders)
                ->get($artifact['url']);
            $response = $request($headers);
            if ($response->status() === 304) {
                if (! $localValid) {
                    $response = $request(['Accept' => 'application/zip']);
                    if ($response->status() === 304) {
                        return ArtifactDownloadResult::failed('conditional_cache_inconsistent', ['Artifact returned 304 without valid local evidence.']);
                    }
                } else {
                    $keyId = is_string($artifact['signature']['key_id'] ?? null) ? $artifact['signature']['key_id'] : '';
                    $key = (new PublisherTrustStore((array) ($this->config['trust'] ?? [])))->find($publisherId, $keyId);
                    if (! ($key['allowed'] ?? false)) {
                        return ArtifactDownloadResult::failed((string) $key['code'], ['Signing key is no longer trusted for this publisher.']);
                    }
                    $existing['checked_at'] = now()->toIso8601String();
                    $existing['reused_via_304'] = true;
                    if (! $this->persistMetadataAtomically($disk, $metadataPath, $existing)) {
                        return ArtifactDownloadResult::failed('local_write_failed', ['Cannot update verified artifact evidence.']);
                    }

                    return ArtifactDownloadResult::success($path, $metadataPath, $existing);
                }
            }
            if ($response->status() === 404) {
                return ArtifactDownloadResult::failed('artifact_not_found', ['Artifact is unavailable.']);
            }
            if ($response->status() === 429) {
                return ArtifactDownloadResult::failed('rate_limited', ['Artifact service rate limit reached.']);
            }
            if ($response->status() === 503) {
                return ArtifactDownloadResult::failed('remote_unavailable', ['Artifact storage is temporarily unavailable.']);
            }
            if ($response->status() !== 200) {
                return ArtifactDownloadResult::failed('remote_unavailable', ['Artifact service returned an unexpected status.']);
            }
            $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if ($contentType !== 'application/zip') {
                return ArtifactDownloadResult::failed('invalid_content_type', ['Artifact Content-Type is invalid.']);
            }
            $contentLength = $response->header('Content-Length');
            if ($contentLength !== null && (! ctype_digit($contentLength) || (int) $contentLength !== $artifact['size'])) {
                return ArtifactDownloadResult::failed('invalid_content_length', ['Artifact Content-Length does not match Registry metadata.']);
            }
            $etag = $response->header('ETag');
            if ($etag !== null && $etag !== '' && trim($etag, '"') !== $artifact['sha256']) {
                return ArtifactDownloadResult::failed('etag_mismatch', ['Artifact ETag does not match Registry metadata.']);
            }

            $hash = hash_init('sha256');
            $actual = 0;
            $stream = $sinkUsed ? null : $response->toPsrResponse()->getBody();
            if ($sinkUsed) {
                rewind($handle);
            }
            while ($sinkUsed ? ! feof($handle) : ! $stream->eof()) {
                $chunk = $sinkUsed ? fread($handle, 64 * 1024) : $stream->read(64 * 1024);
                if ($chunk === '') {
                    continue;
                }
                $actual += strlen($chunk);
                if ($actual > $artifact['size'] || $actual > $max) {
                    return ArtifactDownloadResult::failed('size_limit_exceeded', ['Artifact stream exceeded its declared size.']);
                }
                hash_update($hash, $chunk);
                if (! $sinkUsed && fwrite($handle, $chunk) !== strlen($chunk)) {
                    return ArtifactDownloadResult::failed('local_write_failed', ['Failed writing quarantine temporary file.']);
                }
            }
            fflush($handle);
            if ($actual !== $artifact['size']) {
                return ArtifactDownloadResult::failed('size_mismatch', ['Artifact byte count does not match Registry metadata.']);
            }
            $actualHash = hash_final($hash);
            if (! hash_equals($artifact['sha256'], $actualHash)) {
                return ArtifactDownloadResult::failed('sha256_mismatch', ['Artifact SHA-256 verification failed.']);
            }
            $signature = $artifact['signature'];
            if (! is_array($signature)) {
                return ArtifactDownloadResult::failed('malformed_signature', ['A detached signature is required.']);
            }
            $keyId = is_string($signature['key_id'] ?? null) ? $signature['key_id'] : '';
            $key = (new PublisherTrustStore((array) ($this->config['trust'] ?? [])))->find($publisherId, $keyId);
            if (! ($key['allowed'] ?? false)) {
                return ArtifactDownloadResult::failed((string) $key['code'], ['Signing key is not trusted for this publisher.']);
            }
            $signatureResult = (new ArtifactSignatureVerifier)->verifyFile(
                $artifact['signature'], $absoluteTemp, $key['public_key'],
                min($max, (int) ($this->config['trust']['signature_verification_max_bytes'] ?? $max)),
            );
            if ($signatureResult->status !== ArtifactSignatureVerifier::STATUS_VALID) {
                return ArtifactDownloadResult::failed('signature_invalid', $signatureResult->diagnostics);
            }
            $archive = (new ArchiveSafetyValidator)->validate($absoluteTemp, (array) ($downloads['archive_limits'] ?? []));
            if (! ($archive['success'] ?? false)) {
                return ArtifactDownloadResult::failed('archive_unsafe', (array) ($archive['diagnostics'] ?? ['Archive safety validation failed.']));
            }
            $manifest = (new QuarantinedArtifactInspector)->inspect($absoluteTemp, $item->code, $item->version);
            if ($manifest->status !== QuarantinedArtifactInspector::STATUS_VALID) {
                $code = $manifest->status === QuarantinedArtifactInspector::STATUS_IDENTITY_MISMATCH ? 'manifest_identity_mismatch' : 'archive_invalid';

                return ArtifactDownloadResult::failed($code, $manifest->diagnostics);
            }
            $manifestType = $manifest->manifest['type'] ?? null;
            if (is_string($manifestType) && $manifestType !== $item->type) {
                return ArtifactDownloadResult::failed('manifest_identity_mismatch', ['Manifest type does not match Registry metadata.']);
            }
            $manifestVendor = $manifest->manifest['vendor'] ?? null;
            if (is_string($manifestVendor) && $manifestVendor !== $item->vendor) {
                return ArtifactDownloadResult::failed('manifest_identity_mismatch', ['Manifest vendor does not match Registry metadata.']);
            }

            fclose($handle);
            $handle = null;
            $disk->makeDirectory($directory);
            $alreadyExisted = $disk->exists($path);
            if ($alreadyExisted && hash_file('sha256', $disk->path($path)) !== $actualHash) {
                return ArtifactDownloadResult::failed('conditional_cache_inconsistent', ['A different verified artifact already exists for this release.']);
            }
            if (! $alreadyExisted && ! @rename($absoluteTemp, $disk->path($path))) {
                return ArtifactDownloadResult::failed('local_write_failed', ['Cannot atomically publish verified artifact.']);
            }
            $publishedNewFile = ! $alreadyExisted;
            $metadata = $this->metadata($item, $artifact, $publisherId, $keyId, $key, $actualHash, $actual, $etag, $response->header('Last-Modified'), $response->header('X-Request-ID'));
            $metadataTemp = $directory.'/.metadata.'.bin2hex(random_bytes(8)).'.part';
            $encodedMetadata = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($encodedMetadata) || ! $disk->put($metadataTemp, $encodedMetadata)
                || ! @rename($disk->path($metadataTemp), $disk->path($metadataPath))) {
                if ($publishedNewFile) {
                    $disk->delete($path);
                }

                return ArtifactDownloadResult::failed('local_write_failed', ['Cannot persist verified artifact metadata.']);
            }

            return ArtifactDownloadResult::success($path, $metadataPath, $metadata);
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'size limit')) {
                return ArtifactDownloadResult::failed('size_limit_exceeded', ['Artifact stream exceeded its size limit.']);
            }

            return ArtifactDownloadResult::failed('remote_unavailable', ['Artifact acquisition failed.']);
        } catch (\Throwable) {
            return ArtifactDownloadResult::failed('remote_unavailable', ['Artifact acquisition failed.']);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($absoluteTemp)) {
                @unlink($absoluteTemp);
            }
            if (is_string($metadataTemp) && $disk->exists($metadataTemp)) {
                $disk->delete($metadataTemp);
            }
        }
    }

    private function metadata(RegistryItem $item, array $artifact, string $publisherId, string $keyId, array $key, string $hash, int $size, ?string $etag, ?string $lastModified, ?string $requestId): array
    {
        return [
            'code' => $item->code, 'version' => $item->version, 'publisher_public_id' => $publisherId,
            'publisher_name' => $item->publisher['name'] ?? null, 'signature_key_id' => $keyId,
            'signing_key_fingerprint' => $key['fingerprint'], 'local_trust_status' => $key['status'],
            'signature_algorithm' => 'ed25519', 'signature_payload_version' => 'raw-zip-v1', 'artifact_type' => 'zip',
            'source_host' => parse_url($artifact['url'], PHP_URL_HOST), 'source_url' => $artifact['url'],
            'sha256' => $artifact['sha256'], 'actual_sha256' => $hash, 'size' => $artifact['size'], 'actual_size' => $size,
            'etag' => $etag, 'last_modified' => $lastModified, 'downloaded_at' => now()->toIso8601String(),
            'request_id' => $requestId,
            'verified_at' => now()->toIso8601String(), 'status' => 'quarantined', 'verification_state' => 'verified',
            'signature_status' => 'valid', 'signature_checked_at' => now()->toIso8601String(),
            'manifest_status' => 'valid', 'manifest_checked_at' => now()->toIso8601String(), 'trust_status' => 'trusted',
            'review_status' => 'pending', 'review_history' => [], 'staging_status' => ArtifactStagingStatus::NOT_STAGED,
            'promotion_status' => ArtifactPromotionStatus::NOT_PROMOTED, 'artifact_diagnostics' => [],
        ];
    }

    private function persistMetadataAtomically(object $disk, string $path, array $metadata): bool
    {
        $temp = dirname($path).'/.metadata.'.bin2hex(random_bytes(8)).'.part';
        $encoded = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || ! $disk->put($temp, $encoded)) {
            return false;
        }
        $success = @rename($disk->path($temp), $disk->path($path));
        if (! $success) {
            $disk->delete($temp);
        }

        return $success;
    }

    public static function safeFilename(string $code, string $version): string
    {
        $safeCode = preg_replace('/[^a-z0-9._-]+/i', '-', $code) ?: 'addon';
        $safeVersion = preg_replace('/[^a-z0-9._+-]+/i', '-', $version) ?: 'unknown';

        return trim($safeCode, '.-').'-'.trim($safeVersion, '.-').'.zip';
    }
}
