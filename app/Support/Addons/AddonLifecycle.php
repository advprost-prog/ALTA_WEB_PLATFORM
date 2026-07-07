<?php

namespace App\Support\Addons;

use App\Models\SystemAddon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AddonLifecycle
{
    public function __construct(
        private readonly AddonRegistry $registry,
        private readonly AddonEventLogger $events,
    ) {}

    public function install(string $code): SystemAddon
    {
        return DB::transaction(function () use ($code): SystemAddon {
            $addon = $this->addonOrFail($code);
            $this->ensureLocalLifecycleAllowed($addon);
            $this->assertDependenciesResolvable($addon, requireEnabled: false);
            $this->assertCompatible($addon);

            $addon->forceFill([
                'status' => SystemAddon::STATUS_INSTALLED,
                'is_installed' => true,
                'is_enabled' => false,
                'installed_at' => $addon->installed_at ?: now(),
                'enabled_at' => null,
                'disabled_at' => null,
                'removed_at' => null,
                'last_error' => null,
            ])->save();

            $this->events->info($addon->code, 'installed', 'Addon installed locally.');

            return $addon;
        });
    }

    public function enable(string $code): SystemAddon
    {
        return DB::transaction(function () use ($code): SystemAddon {
            $addon = $this->addonOrFail($code);
            $this->ensureLocalLifecycleAllowed($addon);
            $this->assertDependenciesResolvable($addon, requireEnabled: true);
            $this->assertCompatible($addon);
            $this->assertManifestPresent($addon);

            if ($addon->service_provider && ! $this->serviceProviderIsAllowed($addon)) {
                $this->fail($addon, 'Service provider is outside the allowed local addon namespace/path.');
            }

            $addon->forceFill([
                'status' => SystemAddon::STATUS_ENABLED,
                'is_installed' => true,
                'is_enabled' => true,
                'installed_at' => $addon->installed_at ?: now(),
                'enabled_at' => now(),
                'disabled_at' => null,
                'removed_at' => null,
                'last_error' => null,
            ])->save();

            $this->events->info($addon->code, 'enabled', 'Addon enabled.');

            return $addon;
        });
    }

    public function disable(string $code): SystemAddon
    {
        return DB::transaction(function () use ($code): SystemAddon {
            $addon = $this->addonOrFail($code);

            $addon->forceFill([
                'status' => SystemAddon::STATUS_DISABLED,
                'is_enabled' => false,
                'enabled_at' => null,
                'disabled_at' => now(),
                'last_error' => null,
            ])->save();

            $this->events->info($addon->code, 'disabled', 'Addon disabled.');

            return $addon;
        });
    }

    public function uninstall(string $code): SystemAddon
    {
        return DB::transaction(function () use ($code): SystemAddon {
            $addon = $this->addonOrFail($code);

            $addon->forceFill([
                'status' => SystemAddon::STATUS_DISCOVERED,
                'is_installed' => false,
                'is_enabled' => false,
                'enabled_at' => null,
                'disabled_at' => now(),
                'last_error' => null,
            ])->save();

            $this->events->info($addon->code, 'uninstalled', 'Addon soft-uninstalled. Files were not removed.');

            return $addon;
        });
    }

    public function remove(string $code): SystemAddon
    {
        return DB::transaction(function () use ($code): SystemAddon {
            $addon = $this->addonOrFail($code);

            $addon->forceFill([
                'status' => SystemAddon::STATUS_REMOVED,
                'is_installed' => false,
                'is_enabled' => false,
                'enabled_at' => null,
                'disabled_at' => now(),
                'removed_at' => now(),
            ])->save();

            $this->events->warning($addon->code, 'removed', 'Addon soft-removed. Files were not removed.');

            return $addon;
        });
    }

    public function serviceProviderIsAllowed(SystemAddon $addon): bool
    {
        if (! $addon->service_provider) {
            return true;
        }

        $path = $this->serviceProviderPath($addon);

        if ($path === null) {
            return false;
        }

        $basePath = realpath(base_path()) ?: base_path();
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedBase = str_replace('\\', '/', $basePath);

        return str_starts_with($normalizedPath, $normalizedBase);
    }

    public function serviceProviderClassExists(SystemAddon $addon): bool
    {
        if (! $addon->service_provider) {
            return true;
        }

        $path = $this->serviceProviderPath($addon);

        if ($path && is_file($path)) {
            require_once $path;
        }

        return class_exists($addon->service_provider);
    }

    public function serviceProviderPath(SystemAddon $addon): ?string
    {
        $manifest = $addon->metadata['manifest'] ?? [];
        $provider = $addon->service_provider;

        if (! is_string($provider) || $provider === '') {
            return null;
        }

        $directory = dirname(base_path((string) $addon->manifest_path));
        $prefix = $addon->type === SystemAddon::TYPE_MODULE
            ? $this->expectedNamespacePrefix($directory, 'Modules')
            : $this->expectedNamespacePrefix($directory, 'Extensions');

        if (! str_starts_with($provider, $prefix)) {
            return null;
        }

        $relativeClass = substr($provider, strlen($prefix));
        $relativePath = str_replace('\\', '/', $relativeClass).'.php';
        $path = $directory.'/src/'.$relativePath;

        if (isset($manifest['service_provider_path']) && is_string($manifest['service_provider_path'])) {
            $path = $directory.'/'.ltrim($manifest['service_provider_path'], '/');
        }

        return $path;
    }

    /**
     * @return array<int, string>
     */
    public function dependencyIssues(SystemAddon $addon, bool $requireEnabled = true): array
    {
        $manifest = $addon->metadata['manifest'] ?? [];
        $dependencies = is_array($manifest['dependencies'] ?? null) ? $manifest['dependencies'] : [];
        $issues = [];

        foreach ($dependencies as $dependency) {
            $dependencyCode = is_array($dependency) ? (string) ($dependency['code'] ?? '') : (string) $dependency;
            $dependencyAddon = $this->registry->find($dependencyCode);

            if (! $dependencyAddon || ! $dependencyAddon->is_installed) {
                $issues[] = "Dependency [{$dependencyCode}] is not installed.";

                continue;
            }

            if ($requireEnabled && ! $dependencyAddon->is_enabled) {
                $issues[] = "Dependency [{$dependencyCode}] is not enabled.";
            }
        }

        return $issues;
    }

    /**
     * @return array<int, string>
     */
    public function compatibilityIssues(SystemAddon $addon): array
    {
        $compatibility = $addon->metadata['manifest']['compatibility'] ?? [];
        $issues = [];

        if (! is_array($compatibility)) {
            return ['Compatibility block is missing or invalid.'];
        }

        foreach ([
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ] as $field => $currentVersion) {
            $constraint = $compatibility[$field] ?? null;

            if (is_string($constraint) && $constraint !== '' && ! $this->versionSatisfies($currentVersion, $constraint)) {
                $issues[] = "{$field} constraint [{$constraint}] is not satisfied by [{$currentVersion}].";
            }
        }

        return $issues;
    }

    private function addonOrFail(string $code): SystemAddon
    {
        $addon = $this->registry->find($code);

        if (! $addon) {
            throw new RuntimeException("Addon [{$code}] was not found. Run addons:discover first.");
        }

        return $addon;
    }

    private function ensureLocalLifecycleAllowed(SystemAddon $addon): void
    {
        if ($addon->source !== SystemAddon::SOURCE_LOCAL) {
            $this->fail($addon, 'Phase 1 lifecycle only supports local addons.');
        }
    }

    private function assertDependenciesResolvable(SystemAddon $addon, bool $requireEnabled): void
    {
        $issues = $this->dependencyIssues($addon, $requireEnabled);

        if ($issues !== []) {
            $this->fail($addon, implode(' ', $issues));
        }
    }

    private function assertCompatible(SystemAddon $addon): void
    {
        $issues = $this->compatibilityIssues($addon);

        if ($issues !== []) {
            $this->fail($addon, implode(' ', $issues));
        }
    }

    private function assertManifestPresent(SystemAddon $addon): void
    {
        if (! $addon->manifest_path || ! is_file(base_path($addon->manifest_path))) {
            $this->fail($addon, 'Manifest file is missing.');
        }
    }

    private function fail(SystemAddon $addon, string $message): never
    {
        $addon->forceFill([
            'status' => SystemAddon::STATUS_FAILED,
            'is_enabled' => false,
            'last_error' => $message,
        ])->save();

        $this->events->error($addon->code, 'lifecycle_failed', $message);

        throw new RuntimeException($message);
    }

    private function expectedNamespacePrefix(string $directory, string $rootNamespace): string
    {
        $relative = trim(str_replace('\\', '/', str_replace(base_path(), '', $directory)), '/');
        $parts = array_slice(explode('/', $relative), 1);

        return $rootNamespace.'\\'.implode('\\', $parts).'\\';
    }

    private function versionSatisfies(string $currentVersion, string $constraint): bool
    {
        $constraint = trim($constraint);

        if (str_starts_with($constraint, '>=')) {
            return version_compare($currentVersion, trim(substr($constraint, 2)), '>=');
        }

        if (str_starts_with($constraint, '<=')) {
            return version_compare($currentVersion, trim(substr($constraint, 2)), '<=');
        }

        if (str_starts_with($constraint, '>')) {
            return version_compare($currentVersion, trim(substr($constraint, 1)), '>');
        }

        if (str_starts_with($constraint, '<')) {
            return version_compare($currentVersion, trim(substr($constraint, 1)), '<');
        }

        if (str_starts_with($constraint, '^')) {
            $minimum = trim(substr($constraint, 1));
            $major = explode('.', $minimum)[0] ?? '0';

            return version_compare($currentVersion, $minimum, '>=') && version_compare($currentVersion, ((int) $major + 1).'.0.0', '<');
        }

        return version_compare($currentVersion, $constraint, '>=');
    }
}
