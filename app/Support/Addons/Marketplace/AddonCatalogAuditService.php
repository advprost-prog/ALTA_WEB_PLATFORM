<?php

namespace App\Support\Addons\Marketplace;

use App\Support\Addons\AddonRegistry;

final class AddonCatalogAuditService
{
    public function __construct(private readonly AddonRegistry $registry) {}

    public function audit(): array
    {
        $rows = [];
        foreach ((array) config('addons-marketplace.items', []) as $entry) {
            if (! is_array($entry) || ! is_string($entry['code'] ?? null)) {
                continue;
            }
            $path = is_string($entry['path'] ?? null) ? base_path($entry['path']) : null;
            $manifest = $path !== null && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $addon = $this->registry->find($entry['code']);
            $rows[] = [
                'code' => $entry['code'],
                'classification' => $entry['audit_classification'] ?? 'unknown_manual_review',
                'visibility' => $entry['visibility'] ?? 'production',
                'implementation_state' => $entry['implementation_state'] ?? 'functional',
                'manifest_exists' => is_array($manifest),
                'service_provider' => is_string($manifest['service_provider'] ?? null),
                'installed' => (bool) $addon?->is_installed,
                'enabled' => (bool) $addon?->is_enabled,
                'database_files_consistent' => $addon === null || ! $addon->is_installed || is_array($manifest),
            ];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $rows;
    }
}
