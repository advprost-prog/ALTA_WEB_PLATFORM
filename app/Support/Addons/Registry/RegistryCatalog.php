<?php

namespace App\Support\Addons\Registry;

use Illuminate\Support\Facades\Cache;

class RegistryCatalog
{
    public function __construct(
        private readonly RegistryClient $client,
        private readonly array $config,
    ) {}

    /**
     * @return array{registry: array<string, mixed>, items: list<RegistryItem>, diagnostics: list<string>}
     */
    public function load(): array
    {
        $enabled = (bool) ($this->config['enabled'] ?? false);

        if (! $enabled) {
            return [
                'registry' => [],
                'items' => [],
                'diagnostics' => [],
            ];
        }

        $cacheKey = 'addons.registry.catalog';
        $ttl = (int) ($this->config['cache_ttl'] ?? 3600);

        try {
            $payload = Cache::remember($cacheKey, $ttl, function () {
                return $this->client->fetch();
            });
        } catch (RegistryException $exception) {
            return [
                'registry' => [],
                'items' => [],
                'diagnostics' => [$exception->getMessage()],
            ];
        } catch (\Throwable $exception) {
            return [
                'registry' => [],
                'items' => [],
                'diagnostics' => ['Registry unavailable: '.$exception->getMessage()],
            ];
        }

        if (! is_array($payload)) {
            return [
                'registry' => [],
                'items' => [],
                'diagnostics' => ['Invalid registry payload.'],
            ];
        }

        $registry = is_array($payload['registry'] ?? null) ? $payload['registry'] : [];
        $items = [];
        $diagnostics = [];

        foreach ($payload['items'] ?? [] as $index => $rawItem) {
            if (! is_array($rawItem)) {
                $diagnostics[] = "Invalid registry item at index {$index}: expected array.";

                continue;
            }

            if (! isset($rawItem['code']) || ! is_string($rawItem['code']) || trim($rawItem['code']) === '') {
                $diagnostics[] = "Invalid registry item at index {$index}: missing [code].";

                continue;
            }

            if (! isset($rawItem['type']) || ! is_string($rawItem['type']) || trim($rawItem['type']) === '') {
                $diagnostics[] = "Invalid registry item at index {$index}: missing [type].";

                continue;
            }

            $items[] = RegistryItem::fromArray($rawItem);
        }

        return [
            'registry' => $registry,
            'items' => $items,
            'diagnostics' => $diagnostics,
        ];
    }

    public function flush(): void
    {
        Cache::forget('addons.registry.catalog');
    }
}
