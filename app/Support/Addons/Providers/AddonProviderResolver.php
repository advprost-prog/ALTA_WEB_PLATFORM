<?php

namespace App\Support\Addons\Providers;

use App\Models\SystemAddon;
use JsonException;
use ReflectionClass;

final class AddonProviderResolver
{
    private const PACKAGE_NAME = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/';

    /** @var list<string> */
    private const RESERVED_NAMESPACES = [
        'App\\', 'Modules\\', 'Extensions\\', 'Database\\',
        'Illuminate\\', 'Laravel\\', 'Filament\\', 'Livewire\\',
        'Symfony\\', 'Composer\\', 'PHPUnit\\', 'Mockery\\', 'Tests\\',
        'Psr\\', 'GuzzleHttp\\', 'Monolog\\', 'League\\', 'Carbon\\', 'Doctrine\\',
    ];

    public function __construct(private readonly PackageScopedAutoloadRegistry $autoloaders) {}

    public function resolve(SystemAddon $addon): ResolvedAddonProvider
    {
        $provider = $addon->service_provider;
        if (! is_string($provider) || $provider === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $provider) !== 1) {
            throw new AddonProviderException('provider_not_allowed', 'Manifest provider class is invalid.');
        }

        $root = $this->packageRoot($addon);
        $bundledPrefix = $this->bundledPrefix($root, $addon->type);

        if (str_starts_with($provider, $bundledPrefix)) {
            return $this->resolveBundled($addon, $root, $provider, $bundledPrefix);
        }

        return $this->resolvePackage($addon, $root, $provider);
    }

    public function load(SystemAddon $addon): bool
    {
        return $this->autoloaders->load($this->resolve($addon));
    }

    public function unregister(string $addonCode): void
    {
        $this->autoloaders->unregister($addonCode);
    }

    public function ownsClass(SystemAddon $addon, string $class): bool
    {
        try {
            $resolved = $this->resolve($addon);
            if (! $this->load($addon) || ! class_exists($class)) {
                return false;
            }
            $file = (new ReflectionClass($class))->getFileName();

            return is_string($file) && $this->inside($file, $resolved->packageRoot);
        } catch (AddonProviderException) {
            return false;
        }
    }

    private function resolveBundled(SystemAddon $addon, string $root, string $provider, string $prefix): ResolvedAddonProvider
    {
        $manifest = is_array($addon->metadata['manifest'] ?? null) ? $addon->metadata['manifest'] : [];
        $relative = 'src/'.str_replace('\\', '/', substr($provider, strlen($prefix))).'.php';
        if (isset($manifest['service_provider_path']) && is_string($manifest['service_provider_path'])) {
            $relative = $manifest['service_provider_path'];
        }

        $file = $this->confinedFile($root, $relative, 'provider_file_missing', 'provider_file_escape');

        return new ResolvedAddonProvider($addon->code, $provider, $file, $root, 'bundled');
    }

    private function resolvePackage(SystemAddon $addon, string $root, string $provider): ResolvedAddonProvider
    {
        $this->assertNotReserved($provider);
        $composerPath = $root.'/composer.json';
        if (! is_file($composerPath) || ! is_readable($composerPath)) {
            throw new AddonProviderException('package_metadata_missing', 'Standalone addon composer.json is missing.');
        }

        try {
            $composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AddonProviderException('package_metadata_invalid', 'Standalone addon composer.json is invalid.');
        }
        if (! is_array($composer) || ! is_string($composer['name'] ?? null)
            || preg_match(self::PACKAGE_NAME, $composer['name']) !== 1) {
            throw new AddonProviderException('package_metadata_invalid', 'Standalone addon package name is invalid.');
        }

        $autoload = $composer['autoload'] ?? null;
        if (! is_array($autoload)) {
            throw new AddonProviderException('psr4_missing', 'Standalone addon PSR-4 metadata is missing.');
        }
        foreach (['files', 'classmap', 'include-path'] as $unsupported) {
            if (array_key_exists($unsupported, $autoload)) {
                throw new AddonProviderException('package_autoload_unsupported', 'Standalone addon uses an unsupported Composer autoload mechanism.');
            }
        }
        if (! is_array($autoload['psr-4'] ?? null) || $autoload['psr-4'] === []) {
            throw new AddonProviderException('psr4_missing', 'Standalone addon PSR-4 metadata is missing.');
        }

        $mappings = $this->validatedMappings($root, $autoload['psr-4']);
        $prefixes = array_values(array_filter(array_keys($mappings), static fn (string $prefix): bool => str_starts_with($provider, $prefix)));
        if ($prefixes === []) {
            throw new AddonProviderException('provider_prefix_mismatch', 'Manifest provider is outside package-owned PSR-4 namespaces.');
        }
        usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $prefix = $prefixes[0];
        $suffix = substr($provider, strlen($prefix));
        if ($suffix === '') {
            throw new AddonProviderException('provider_prefix_mismatch', 'Manifest provider cannot equal a PSR-4 prefix.');
        }

        $candidates = [];
        foreach ($mappings[$prefix] as $sourceRoot) {
            $candidate = $sourceRoot.'/'.str_replace('\\', '/', $suffix).'.php';
            $real = realpath($candidate);
            if ($real === false || ! is_file($real) || ! is_readable($real)) {
                continue;
            }
            if (! $this->inside($real, $sourceRoot) || ! $this->inside($real, $root)) {
                throw new AddonProviderException('provider_file_escape', 'Provider source escapes the package root.');
            }
            $candidates[$real] = true;
        }
        $files = array_keys($candidates);
        if ($files === []) {
            throw new AddonProviderException('provider_file_missing', 'Manifest provider source file is missing.');
        }
        if (count($files) !== 1) {
            throw new AddonProviderException('provider_file_ambiguous', 'Manifest provider resolves to multiple source files.');
        }

        return new ResolvedAddonProvider($addon->code, $provider, $files[0], $root, 'package', $mappings);
    }

    /** @param array<mixed> $raw @return array<string, list<string>> */
    private function validatedMappings(string $root, array $raw): array
    {
        $result = [];
        foreach ($raw as $prefix => $directories) {
            if (! is_string($prefix) || preg_match('/^([A-Za-z_][A-Za-z0-9_]*\\\\)+$/', $prefix) !== 1) {
                throw new AddonProviderException('package_metadata_invalid', 'Package contains an invalid PSR-4 prefix.');
            }
            $this->assertNotReserved($prefix);
            $directories = is_string($directories) ? [$directories] : $directories;
            if (! is_array($directories) || $directories === []) {
                throw new AddonProviderException('psr4_path_invalid', 'PSR-4 source directories are invalid.');
            }
            foreach ($directories as $directory) {
                if (! is_string($directory) || ! $this->safeRelativePath($directory)) {
                    throw new AddonProviderException('psr4_path_invalid', 'PSR-4 source directory must be package-relative.');
                }
                $real = realpath($root.'/'.rtrim(str_replace('\\', '/', $directory), '/'));
                if ($real === false || ! is_dir($real) || ! is_readable($real)) {
                    throw new AddonProviderException('psr4_path_invalid', 'PSR-4 source directory is missing or unreadable.');
                }
                if (! $this->inside($real, $root)) {
                    throw new AddonProviderException('psr4_path_escape', 'PSR-4 source directory escapes the package root.');
                }
                $result[$prefix][] = $real;
            }
            $result[$prefix] = array_values(array_unique($result[$prefix]));
        }

        return $result;
    }

    private function packageRoot(SystemAddon $addon): string
    {
        if (! is_string($addon->manifest_path) || $addon->manifest_path === '' || str_contains($addon->manifest_path, "\0")) {
            throw new AddonProviderException('provider_not_allowed', 'Addon manifest path is invalid.');
        }
        $manifest = realpath(base_path($addon->manifest_path));
        if ($manifest === false || ! is_file($manifest)) {
            throw new AddonProviderException('provider_not_allowed', 'Addon manifest is not available.');
        }
        $root = realpath(dirname($manifest));
        if ($root === false || ! $this->insideApprovedRoot($root, $addon->type)) {
            throw new AddonProviderException('provider_not_allowed', 'Addon package is outside approved active roots.');
        }

        return $root;
    }

    private function insideApprovedRoot(string $packageRoot, string $type): bool
    {
        foreach ($this->approvedRoots($type) as $approved) {
            if ($this->inside($packageRoot, $approved) && $packageRoot !== $approved) {
                return true;
            }
        }

        return false;
    }

    private function bundledPrefix(string $root, string $type): string
    {
        $namespace = $type === SystemAddon::TYPE_EXTENSION ? 'Extensions' : 'Modules';
        $approved = collect($this->approvedRoots($type))
            ->first(fn (string $candidate): bool => $this->inside($root, $candidate));
        if (! is_string($approved)) {
            throw new AddonProviderException('provider_not_allowed', 'Addon package is outside approved active roots.');
        }
        $relative = trim(str_replace('\\', '/', substr($root, strlen(rtrim($approved, DIRECTORY_SEPARATOR)))), '/');

        return $namespace.'\\'.str_replace('/', '\\', $relative).'\\';
    }

    /** @return list<string> */
    private function approvedRoots(string $type): array
    {
        $directory = $type === SystemAddon::TYPE_EXTENSION ? 'extensions' : 'modules';
        $key = $type === SystemAddon::TYPE_EXTENSION ? 'extensions_path' : 'modules_path';
        $candidates = [base_path($directory), (string) config('addons-registry.live_roots.'.$key, '')];
        $roots = [];
        foreach ($candidates as $candidate) {
            $real = $candidate !== '' ? realpath($candidate) : false;
            if ($real !== false && is_dir($real)) {
                $roots[] = $real;
            }
        }

        return array_values(array_unique($roots));
    }

    private function confinedFile(string $root, string $relative, string $missingCode, string $escapeCode): string
    {
        if (! $this->safeRelativePath($relative)) {
            throw new AddonProviderException($escapeCode, 'Provider source path is unsafe.');
        }
        $real = realpath($root.'/'.str_replace('\\', '/', $relative));
        if ($real === false || ! is_file($real) || ! is_readable($real)) {
            throw new AddonProviderException($missingCode, 'Provider source file is missing.');
        }
        if (! $this->inside($real, $root)) {
            throw new AddonProviderException($escapeCode, 'Provider source escapes the addon root.');
        }

        return $real;
    }

    private function assertNotReserved(string $namespace): void
    {
        foreach (self::RESERVED_NAMESPACES as $reserved) {
            if (str_starts_with(strtolower($namespace), strtolower($reserved))) {
                throw new AddonProviderException('namespace_reserved', 'Package namespace is reserved by the host or framework.');
            }
        }
    }

    private function safeRelativePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return $normalized !== '' && ! str_contains($normalized, "\0")
            && ! str_starts_with($normalized, '/') && ! str_starts_with($normalized, '//')
            && preg_match('/^[A-Za-z]:\//', $normalized) !== 1
            && ! in_array('..', explode('/', $normalized), true);
    }

    private function inside(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $path === $root || str_starts_with($path, $root.'/');
    }
}
