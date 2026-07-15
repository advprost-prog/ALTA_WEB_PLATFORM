<?php

namespace App\Support\Addons\Providers;

final readonly class ResolvedAddonProvider
{
    /** @param array<string, list<string>> $psr4 */
    public function __construct(
        public string $addonCode,
        public string $providerClass,
        public string $providerFile,
        public string $packageRoot,
        public string $mode,
        public array $psr4 = [],
    ) {}
}
