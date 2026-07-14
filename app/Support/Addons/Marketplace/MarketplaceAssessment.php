<?php

namespace App\Support\Addons\Marketplace;

final readonly class MarketplaceAssessment
{
    public function __construct(
        public string $code,
        public string $source,
        public string $runtimeState,
        public ?string $installedVersion,
        public ?string $localCatalogVersion,
        public ?string $remoteVersion,
        public string $versionState,
        public array $identity,
        public array $compatibility,
        public array $dependencies,
        public string $registryState,
        public ?array $publisher,
        public ?string $signingKeyId,
        public ?string $publishedAt,
        public ?array $artifact,
        public array $actions,
        public array $diagnostics,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
