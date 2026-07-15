<?php

namespace App\Support\Addons\Marketplace;

final class RegistryPresentationState
{
    /** @param array<string, mixed> $catalog */
    public function resolve(array $catalog): string
    {
        $enabled = (bool) config('addons-registry.enabled', false);
        $url = trim((string) config('addons-registry.url', ''));
        $rawState = (string) ($catalog['state'] ?? 'unavailable');
        $itemCount = count($catalog['items'] ?? []);
        $errorCategory = (string) ($catalog['meta']['last_error_category'] ?? '');

        if (! $enabled) {
            return $url === '' ? 'not_configured' : 'disabled';
        }

        if (in_array($errorCategory, ['html_challenge_response', 'invalid_content_type', 'host_rejected', 'dns_failure', 'connect_failure', 'tls_failure', 'timeout', 'redirect_rejected'], true) && $itemCount === 0) {
            return $errorCategory;
        }

        if (in_array($errorCategory, ['invalid_json', 'schema_invalid', 'invalid_schema', 'invalid_response'], true)) {
            return 'invalid_response';
        }

        if ($rawState === 'fresh') {
            return $itemCount === 0 ? 'connected_empty' : 'connected_with_items';
        }

        if ($rawState === 'stale') {
            return 'stale_cache';
        }

        if (in_array($rawState, ['offline', 'rate_limited'], true) && $itemCount > 0) {
            return 'unavailable_with_cache';
        }

        return $itemCount > 0 ? 'unavailable_with_cache' : 'unavailable_without_cache';
    }
}
