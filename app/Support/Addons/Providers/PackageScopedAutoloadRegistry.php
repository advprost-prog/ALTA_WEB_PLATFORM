<?php

namespace App\Support\Addons\Providers;

use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use Throwable;

final class PackageScopedAutoloadRegistry
{
    /** @var array<string, array{root:string, prefixes:list<string>, loader:callable}> */
    private array $loaders = [];

    public function load(ResolvedAddonProvider $provider): bool
    {
        if ($provider->mode === 'bundled') {
            require_once $provider->providerFile;

            return $this->validateLoadedProvider($provider);
        }

        $this->assertNoCollision($provider);

        if (class_exists($provider->providerClass, false)) {
            return $this->validateLoadedProvider($provider);
        }

        $existing = $this->loaders[$provider->addonCode] ?? null;

        if ($existing !== null && $existing['root'] !== $provider->packageRoot) {
            $this->unregister($provider->addonCode);
            $existing = null;
        }

        if ($existing === null) {
            $loader = $this->loaderFor($provider);
            spl_autoload_register($loader, true, true);
            $this->loaders[$provider->addonCode] = [
                'root' => $provider->packageRoot,
                'prefixes' => array_keys($provider->psr4),
                'loader' => $loader,
            ];
        }

        try {
            require_once $provider->providerFile;

            return $this->validateLoadedProvider($provider);
        } catch (Throwable $exception) {
            $this->unregister($provider->addonCode);

            if ($exception instanceof AddonProviderException) {
                throw $exception;
            }

            throw new AddonProviderException('provider_class_invalid', 'Provider source could not be loaded.');
        }
    }

    public function unregister(string $addonCode): void
    {
        $entry = $this->loaders[$addonCode] ?? null;
        if ($entry === null) {
            return;
        }

        spl_autoload_unregister($entry['loader']);
        unset($this->loaders[$addonCode]);
    }

    public function isRegistered(string $addonCode): bool
    {
        return isset($this->loaders[$addonCode]);
    }

    public function count(): int
    {
        return count($this->loaders);
    }

    private function loaderFor(ResolvedAddonProvider $provider): callable
    {
        return static function (string $class) use ($provider): void {
            $matches = array_filter(
                array_keys($provider->psr4),
                static fn (string $prefix): bool => str_starts_with($class, $prefix),
            );
            usort($matches, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

            foreach ($matches as $prefix) {
                $suffix = substr($class, strlen($prefix));
                foreach ($provider->psr4[$prefix] as $root) {
                    $candidate = $root.'/'.str_replace('\\', '/', $suffix).'.php';
                    $real = realpath($candidate);
                    if ($real !== false && self::inside($real, $root) && is_file($real) && is_readable($real)) {
                        require_once $real;

                        return;
                    }
                }
            }
        };
    }

    private function validateLoadedProvider(ResolvedAddonProvider $provider): bool
    {
        if (! class_exists($provider->providerClass, false)) {
            throw new AddonProviderException('provider_class_invalid', 'Provider source does not declare the manifest class.');
        }

        $reflection = new ReflectionClass($provider->providerClass);
        $reflectedFile = $reflection->getFileName();
        if ($reflectedFile === false || realpath($reflectedFile) !== $provider->providerFile) {
            throw new AddonProviderException('provider_reflection_mismatch', 'Loaded provider source does not match the approved file.');
        }
        if (! $reflection->isInstantiable() || ! $reflection->isSubclassOf(ServiceProvider::class)) {
            throw new AddonProviderException('provider_class_invalid', 'Provider must be an instantiable Laravel service provider.');
        }

        return true;
    }

    private function assertNoCollision(ResolvedAddonProvider $provider): void
    {
        foreach ($this->loaders as $code => $entry) {
            if ($code === $provider->addonCode) {
                continue;
            }
            foreach (array_keys($provider->psr4) as $candidate) {
                foreach ($entry['prefixes'] as $claimed) {
                    if (str_starts_with(strtolower($candidate), strtolower($claimed))
                        || str_starts_with(strtolower($claimed), strtolower($candidate))) {
                        throw new AddonProviderException('namespace_collision', 'Provider namespace is already owned by another active addon.');
                    }
                }
            }
        }
    }

    private static function inside(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $path === $root || str_starts_with($path, $root.'/');
    }
}
