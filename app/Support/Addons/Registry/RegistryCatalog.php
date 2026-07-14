<?php

namespace App\Support\Addons\Registry;

use Illuminate\Support\Facades\Cache;

class RegistryCatalog
{
    private const CACHE_KEY = 'addons.registry.catalog.snapshot.v1';

    public function __construct(private readonly RegistryClient $client, private readonly array $config, private readonly RegistrySchemaValidator $validator = new RegistrySchemaValidator) {}

    public function load(): array
    {
        if (! (bool) ($this->config['enabled'] ?? false)) {
            return $this->emptyState('disabled');
        }
        $snapshot = $this->snapshot();
        if ($snapshot !== null && ! $this->expired($snapshot)) {
            return $this->map($snapshot);
        }

        return $this->refresh();
    }

    public function refresh(): array
    {
        if (! (bool) ($this->config['enabled'] ?? false)) {
            return $this->emptyState('disabled');
        }

        $lock = Cache::lock(self::CACHE_KEY.'.refresh-lock', max(5, (int) ($this->config['timeout'] ?? 5) + 2));
        if (! $lock->get()) {
            $snapshot = $this->snapshot();

            return $snapshot === null
                ? $this->emptyState('unavailable', ['Registry refresh is already in progress.'], 'refresh_locked')
                : $this->map($snapshot);
        }

        try {
            return $this->performRefresh();
        } finally {
            $lock->release();
        }
    }

    private function performRefresh(): array
    {
        $snapshot = $this->snapshot();
        $result = $this->client->fetch($snapshot['etag'] ?? null, $snapshot['last_modified'] ?? null);
        $now = now()->toIso8601String();

        if ($result->status === 304 && $result->errorCategory === null) {
            if ($snapshot === null) {
                return $this->emptyState('unavailable', ['Registry returned 304 without a validated snapshot.']);
            }
            $snapshot = array_merge($snapshot, ['etag' => $result->etag ?: $snapshot['etag'], 'last_modified' => $result->lastModified ?: $snapshot['last_modified'], 'checked_at' => $now, 'last_successful_refresh_at' => $now, 'state' => 'fresh', 'last_error' => null, 'last_error_category' => null, 'retry_after' => null, 'last_http_status' => 304]);
            Cache::forever(self::CACHE_KEY, $snapshot);

            return $this->map($snapshot);
        }

        if ($result->status === 200 && $result->errorCategory === null && is_array($result->payload)) {
            $validated = $this->validator->validate($result->payload, $this->config);
            if ($validated['valid']) {
                $document = $validated['document'];
                $snapshot = ['registry' => $document['registry'], 'items' => $document['items'], 'etag' => $result->etag, 'last_modified' => $result->lastModified, 'source_url' => $result->sourceUrl, 'source_host' => $result->sourceHost, 'schema_version' => '1', 'fetched_at' => $now, 'checked_at' => $now, 'validated_at' => $now, 'last_successful_refresh_at' => $now, 'state' => 'fresh', 'last_error' => null, 'last_error_category' => null, 'retry_after' => null, 'last_http_status' => 200];
                Cache::forever(self::CACHE_KEY, $snapshot);

                return $this->map($snapshot);
            }

            return $this->failure($snapshot, 'invalid_schema', implode(' ', $validated['diagnostics']), $result->status, null);
        }

        return $this->failure($snapshot, $result->errorCategory ?? 'unavailable', $result->diagnostic ?? 'Registry unavailable.', $result->status, $result->retryAfter);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('addons.registry.catalog');
    }

    public function getClient(): RegistryClient
    {
        return $this->client;
    }

    public function snapshot(): ?array
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_array($value) ? $value : null;
    }

    private function failure(?array $snapshot, string $category, string $diagnostic, int $status, ?string $retryAfter): array
    {
        if ($snapshot === null) {
            return $this->emptyState($category === 'rate_limited' ? 'rate_limited' : 'unavailable', [$diagnostic], $category, $retryAfter, $status);
        }
        $snapshot = array_merge($snapshot, ['checked_at' => now()->toIso8601String(), 'state' => $category === 'rate_limited' ? 'rate_limited' : ($category === 'invalid_schema' || $category === 'invalid_json' ? 'stale' : 'offline'), 'last_error' => $diagnostic, 'last_error_category' => $category, 'retry_after' => $retryAfter, 'last_http_status' => $status]);
        Cache::forever(self::CACHE_KEY, $snapshot);

        return $this->map($snapshot);
    }

    private function map(array $snapshot): array
    {
        return ['registry' => $snapshot['registry'], 'items' => array_map(fn (array $item) => RegistryItem::fromArray($item), $snapshot['items']), 'diagnostics' => $snapshot['last_error'] ? [$snapshot['last_error']] : [], 'state' => $snapshot['state'], 'meta' => array_diff_key($snapshot, ['registry' => true, 'items' => true])];
    }

    private function expired(array $snapshot): bool
    {
        return now()->diffInSeconds($snapshot['checked_at'] ?? $snapshot['fetched_at'], true) >= max(0, (int) ($this->config['cache_ttl'] ?? 3600));
    }

    private function emptyState(string $state, array $diagnostics = [], ?string $category = null, ?string $retryAfter = null, int $status = 0): array
    {
        return ['registry' => [], 'items' => [], 'diagnostics' => $diagnostics, 'state' => $state, 'meta' => ['state' => $state, 'last_error_category' => $category, 'retry_after' => $retryAfter, 'last_http_status' => $status]];
    }
}
