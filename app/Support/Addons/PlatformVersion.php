<?php

namespace App\Support\Addons;

/**
 * Central, non-hardcoded source of the current platform identity/version.
 *
 * The local Marketplace reads the platform version from here (never hardcoded)
 * to evaluate addon platform compatibility constraints like ">=1.0.0".
 */
final class PlatformVersion
{
    public function name(): string
    {
        return (string) (config('platform.name') ?? 'ALTA Web Platform');
    }

    public function version(): string
    {
        return (string) (config('platform.version') ?? '0.0.0');
    }
}
