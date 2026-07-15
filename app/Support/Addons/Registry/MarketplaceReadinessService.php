<?php

namespace App\Support\Addons\Registry;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class MarketplaceReadinessService
{
    public function __construct(
        private readonly AddonRecoveryHealthService $health,
        private readonly RecoveryDataCleanupService $cleanup,
        private readonly RegistryCatalog $registry,
        private readonly ?array $runtime = null,
    ) {}

    public function inspect(bool $production = false): array
    {
        $items = [];
        $this->runtime($items);
        $this->registryConfig($items, $production);
        $this->trust($items, $production);
        $this->storage($items);
        $this->persistence($items);
        $this->operations($items);
        if ($production) {
            $this->remoteSmoke($items);
        }
        $blocked = count(array_filter($items, fn (array $item): bool => $item['severity'] === 'blocker'));
        $warnings = count(array_filter($items, fn (array $item): bool => $item['severity'] === 'warning'));

        return [
            'status' => $blocked > 0 ? 'blocked' : ($warnings > 0 ? 'ready_with_warnings' : 'ready'),
            'blocker_count' => $blocked,
            'warning_count' => $warnings,
            'items' => $items,
        ];
    }

    private function runtime(array &$items): void
    {
        $extensions = $this->runtime['extensions'] ?? [];
        foreach (['sodium', 'zip', 'json', 'hash'] as $extension) {
            $available = array_key_exists($extension, $extensions) ? (bool) $extensions[$extension] : extension_loaded($extension);
            $this->add($items, 'runtime_'.$extension, $available ? 'info' : 'blocker', $available ? strtoupper($extension).' runtime capability is available.' : strtoupper($extension).' runtime capability is missing.', 'Install and enable the required PHP extension in the target runtime.');
        }
        $php = $this->runtime['php_version'] ?? PHP_VERSION;
        $this->add($items, 'runtime_php_version', version_compare($php, '8.2.0', '>=') ? 'info' : 'blocker', 'PHP runtime compatibility was checked.', 'Use a supported PHP 8.2 or newer runtime.');
        foreach (['rename', 'file_put_contents', 'hash_file'] as $function) {
            $available = $this->runtime['functions'][$function] ?? function_exists($function);
            $this->add($items, 'runtime_function_'.$function, $available ? 'info' : 'blocker', 'Required filesystem capability '.$function.' was checked.', 'Enable the required PHP filesystem function.');
        }
        $this->add($items, 'platform_version', 'info', 'Application platform version is '.((string) Config::get('platform.version', 'unknown')).'.', 'Confirm addon compatibility constraints before publishing.');
    }

    private function registryConfig(array &$items, bool $production): void
    {
        $enabled = filter_var(Config::get('addons-registry.enabled', false), FILTER_VALIDATE_BOOL);
        $url = (string) Config::get('addons-registry.url', '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $allowed = array_map(fn ($value): string => strtolower(trim((string) $value)), (array) Config::get('addons-registry.allowed_hosts', []));
        $this->add($items, 'registry_enabled', $enabled ? 'info' : 'warning', $enabled ? 'Remote Registry is enabled.' : 'Remote Registry is disabled.', 'Enable the Registry for production remote releases.');
        if ($production || $enabled) {
            $this->add($items, 'registry_https', $scheme === 'https' ? 'info' : 'blocker', 'Registry HTTPS policy was checked.', 'Use an HTTPS Registry URL.');
            $this->add($items, 'registry_host_allowed', $host !== '' && in_array($host, $allowed, true) ? 'info' : 'blocker', 'Registry host allowlist was checked.', 'Add the exact Registry host to ADDONS_REGISTRY_ALLOWED_HOSTS.');
            $this->add($items, 'registry_tls_verification', Config::get('addons-registry.verify_ssl', true) === true ? 'info' : 'blocker', 'Registry TLS verification policy was checked.', 'Set ADDONS_REGISTRY_VERIFY_SSL=true.');
            $this->add($items, 'registry_redirects_disabled', Config::get('addons-registry.allow_redirects', false) === false ? 'info' : 'blocker', 'Registry redirect policy was checked.', 'Set ADDONS_REGISTRY_ALLOW_REDIRECTS=false.');
        }
        foreach (['timeout', 'connect_timeout', 'max_response_size'] as $key) {
            $valid = filter_var(Config::get('addons-registry.'.$key), FILTER_VALIDATE_INT) !== false && (int) Config::get('addons-registry.'.$key) > 0;
            $this->add($items, 'registry_'.$key, $valid ? 'info' : 'blocker', 'Registry '.$key.' limit was checked.', 'Configure a positive bounded value.');
        }
        $this->add($items, 'registry_schema_v1', 'info', 'Registry schema version 1 is supported.', 'Keep server and client schema compatibility aligned.');
    }

    private function trust(array &$items, bool $production): void
    {
        $config = (array) Config::get('addons-registry.trust', []);
        $entries = array_values((array) ($config['keys'] ?? []));
        foreach ((array) ($config['trusted_keys'] ?? []) as $keyId => $publicKey) {
            $entries[] = ['publisher_id' => $config['legacy_publishers'][$keyId] ?? null, 'key_id' => $keyId, 'algorithm' => 'ed25519', 'public_key' => $publicKey, 'status' => 'active'];
        }
        $seen = [];
        foreach ($entries as $index => $entry) {
            $identity = is_array($entry) ? ($entry['publisher_id'] ?? '').'|'.($entry['key_id'] ?? '') : '';
            $raw = is_array($entry) && is_string($entry['public_key'] ?? null) ? base64_decode($entry['public_key'], true) : false;
            $valid = is_array($entry) && is_string($entry['publisher_id'] ?? null) && preg_match('/^[0-9a-f-]{36}$/i', $entry['publisher_id'])
                && is_string($entry['key_id'] ?? null) && $entry['key_id'] !== '' && ($entry['algorithm'] ?? null) === 'ed25519'
                && in_array($entry['status'] ?? null, ['active', 'retiring', 'disabled', 'revoked'], true) && $raw !== false && strlen($raw) === 32
                && ! array_key_exists('private_key', $entry) && ! isset($seen[$identity]);
            $testMarker = $production && is_array($entry) && preg_match('/(?:test|demo|disposable)/i', (string) ($entry['key_id'] ?? ''));
            $this->add($items, 'trust_entry_'.$index, $valid && ! $testMarker ? 'info' : 'blocker', 'Publisher trust entry '.($index + 1).' was structurally checked.', 'Use a unique publisher/key identity and a verified 32-byte Ed25519 public key; never configure private keys.');
            $seen[$identity] = true;
        }
        if ($entries === []) {
            $this->add($items, 'trust_store_empty', 'warning', 'No trusted publisher keys are configured.', 'Configure an authorized publisher binding before consuming a signed release.');
        }
        $this->add($items, 'signature_memory_cap', 'warning', 'Raw-file signature verification is bounded by the configured artifact size cap.', 'Keep ADDONS_REGISTRY_SIGNATURE_MAX_BYTES aligned with the approved release size.');
    }

    private function storage(array &$items): void
    {
        $roots = $this->roots();
        $real = [];
        foreach ($roots as $name => $path) {
            $exists = is_dir($path);
            $safe = $exists && ! is_link($path) && is_writable($path);
            $this->add($items, 'storage_'.$name, $safe ? 'info' : 'blocker', 'Managed '.$name.' root safety was checked.', 'Create an application-private writable directory without symlinks.');
            if ($exists && ($resolved = realpath($path)) !== false) {
                $real[$name] = $resolved;
            }
        }
        $names = array_keys($real);
        for ($i = 0; $i < count($names); $i++) {
            for ($j = $i + 1; $j < count($names); $j++) {
                $a = $real[$names[$i]];
                $b = $real[$names[$j]];
                if ($a === $b || str_starts_with($a, $b.DIRECTORY_SEPARATOR) || str_starts_with($b, $a.DIRECTORY_SEPARATOR)) {
                    $this->add($items, 'storage_roots_overlap', 'blocker', 'Managed roots overlap unsafely.', 'Configure distinct quarantine, staging, backup, and live roots.');
                }
            }
        }
        if (! array_filter($items, fn (array $item): bool => $item['code'] === 'storage_roots_overlap')) {
            $this->add($items, 'storage_roots_distinct', 'info', 'Managed roots are distinct.', 'Keep managed roots isolated.');
        }
        $devices = array_unique(array_filter(array_map(fn (string $path) => @stat($path)['dev'] ?? null, $real)));
        $this->add($items, 'storage_atomic_rename', count($devices) <= 1 ? 'info' : 'blocker', 'Atomic rename filesystem compatibility was checked.', 'Place staging, backup, candidate, and live roots on a compatible filesystem.');
    }

    private function persistence(array &$items): void
    {
        foreach (['system_addons', 'system_addon_events'] as $table) {
            $this->add($items, 'database_'.$table, Schema::hasTable($table) ? 'info' : 'blocker', 'Required Marketplace table '.$table.' was checked.', 'Apply reviewed application migrations before deployment.');
        }
        try {
            $key = 'addons:preflight:lock';
            $lock = Cache::lock($key, 5);
            $ok = $lock->get();
            if ($ok) {
                $lock->release();
            }
        } catch (\Throwable) {
            $ok = false;
        }
        $this->add($items, 'cache_lock', $ok ? 'info' : 'blocker', 'Cache lock backend capability was checked.', 'Use a shared cache backend with atomic lock support.');
    }

    private function operations(array &$items): void
    {
        $health = $this->health->health(true);
        $this->add($items, 'operations_health', $health['status'] === 'manual_intervention_required' ? 'blocker' : ($health['status'] === 'degraded' ? 'warning' : 'info'), 'Marketplace operation health is '.$health['status'].'.', 'Resolve recovery debt before operating on an affected addon.');
        foreach ($this->cleanup->scanRemnants() as $remnant) {
            if ($remnant->managedStatus === 'unmanaged' || $remnant->ownershipStatus === 'unknown') {
                $this->add($items, 'unmanaged_remnant_retained', 'warning', 'An unmanaged recovery remnant is retained.', 'Inspect the sanitized item manually; automated cleanup remains blocked.');
                break;
            }
        }
        if (! Config::get('addons-registry.cleanup.enabled', false)) {
            $this->add($items, 'cleanup_disabled', 'warning', 'Automated cleanup execution is disabled.', 'Enable only after reviewing retention policy and dry-run output.');
        }
    }

    private function remoteSmoke(array &$items): void
    {
        $first = $this->registry->refresh();
        $firstStatus = $first['meta']['last_http_status'] ?? 0;
        $second = $this->registry->refresh();
        $secondStatus = $second['meta']['last_http_status'] ?? 0;
        $this->add($items, 'registry_remote_200', $firstStatus === 200 ? 'info' : 'blocker', 'Registry initial refresh status was checked.', 'Verify production Registry connectivity and schema.');
        $this->add($items, 'registry_remote_304', $secondStatus === 304 ? 'info' : 'warning', 'Registry conditional refresh behavior was checked.', 'Configure ETag or Last-Modified conditional responses.');
        if (($first['items'] ?? []) === []) {
            $this->add($items, 'registry_empty', 'warning', 'Production Registry is valid but contains no releases.', 'Publish an authorized signed release through the server workflow.');
        }
    }

    private function roots(): array
    {
        $disk = Storage::disk((string) Config::get('addons-registry.downloads.disk', 'addons'));

        return [
            'quarantine' => $disk->path(trim((string) Config::get('addons-registry.downloads.quarantine_path'), '/')),
            'staging' => $disk->path(trim((string) Config::get('addons-registry.staging.path'), '/')),
            'backup' => $disk->path(trim((string) Config::get('addons-registry.promotion.backup_path'), '/')),
            'modules_live' => (string) Config::get('addons-registry.live_roots.modules_path'),
            'extensions_live' => (string) Config::get('addons-registry.live_roots.extensions_path'),
        ];
    }

    private function add(array &$items, string $code, string $severity, string $message, string $remediation): void
    {
        $items[] = compact('code', 'severity', 'message', 'remediation');
    }
}
