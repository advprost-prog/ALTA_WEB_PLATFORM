<?php

namespace App\Support\Addons;

use App\Models\SystemAddon;
use App\Support\Addons\Providers\AddonProviderException;
use App\Support\Addons\Providers\AddonProviderResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AddonLifecycle
{
    public function __construct(
        private readonly AddonRegistry $registry,
        private readonly AddonEventLogger $events,
        private readonly AddonProviderResolver $providers,
    ) {}

    public function install(string $code): SystemAddon
    {
        return DB::transaction(function () use ($code): SystemAddon {
            $addon = $this->addonOrFail($code);
            $this->ensureLocalLifecycleAllowed($addon);
            $this->assertDependenciesResolvable($addon, requireEnabled: false);
            $this->assertCompatible($addon);

            if ($addon->is_installed && in_array($addon->status, [
                SystemAddon::STATUS_INSTALLED,
                SystemAddon::STATUS_ENABLED,
                SystemAddon::STATUS_DISABLED,
            ], true)) {
                return $addon;
            }

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

            if ($addon->is_enabled && $addon->status === SystemAddon::STATUS_ENABLED) {
                return $addon;
            }

            if ($addon->service_provider && ! $this->serviceProviderIsAllowed($addon)) {
                $diagnostic = $this->serviceProviderDiagnostic($addon) ?? 'provider_not_allowed';
                $this->fail($addon, "Service provider rejected [{$diagnostic}].");
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

            if (! $addon->is_enabled && $addon->status === SystemAddon::STATUS_DISABLED) {
                return $addon;
            }

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

            if (! $addon->is_installed && $addon->status === SystemAddon::STATUS_DISCOVERED) {
                return $addon;
            }

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

    /**
     * @param  array<string, mixed>  $context
     */
    public function markRuntimeFailure(SystemAddon $addon, string $message, string $event = 'runtime_failed', array $context = []): SystemAddon
    {
        $safeMessage = $this->sanitizeRuntimeError($message);

        $addon->forceFill([
            'status' => SystemAddon::STATUS_FAILED,
            'is_enabled' => false,
            'enabled_at' => null,
            'disabled_at' => now(),
            'last_error' => $safeMessage,
        ])->save();

        $this->events->error($addon->code, $event, $safeMessage, $this->sanitizeRuntimeContext($context));

        return $addon->refresh();
    }

    public function serviceProviderIsAllowed(SystemAddon $addon): bool
    {
        if (! $addon->service_provider) {
            return true;
        }

        $diagnostic = $this->serviceProviderDiagnostic($addon);

        // A missing source remains an allowed declaration but is never loaded;
        // AddonManager records it through the existing provider-missing path.
        return $diagnostic === null || $diagnostic === 'provider_file_missing';
    }

    public function serviceProviderDiagnostic(SystemAddon $addon): ?string
    {
        try {
            $this->providers->resolve($addon);
        } catch (AddonProviderException $exception) {
            return $exception->diagnosticCode;
        }

        return null;
    }

    public function serviceProviderClassExists(SystemAddon $addon): bool
    {
        if (! $addon->service_provider) {
            return true;
        }

        try {
            return $this->providers->load($addon);
        } catch (AddonProviderException) {
            return false;
        }
    }

    public function serviceProviderPath(SystemAddon $addon): ?string
    {
        try {
            return $this->providers->resolve($addon)->providerFile;
        } catch (AddonProviderException) {
            return null;
        }
    }

    public function unregisterServiceProvider(string $addonCode): void
    {
        $this->providers->unregister($addonCode);
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

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeRuntimeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = $this->sanitizeRuntimeError($value, 500);

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function sanitizeRuntimeError(string $message, int $limit = 180): string
    {
        $sanitized = str_replace([base_path(), '\\'], ['[base_path]', '/'], $message);
        $sanitized = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\s+/', ' ', trim($sanitized)) ?? trim($sanitized);

        return mb_strimwidth($sanitized, 0, $limit, '...');
    }

    private function versionSatisfies(string $currentVersion, string $constraint): bool
    {
        $constraint = trim($constraint);

        if (str_contains($constraint, ' ')) {
            $parts = preg_split('/\s+/', $constraint, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return $parts !== [] && collect($parts)->every(
                fn (string $part): bool => $this->versionSatisfies($currentVersion, $part),
            );
        }

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
