<?php

namespace App\Support\Addons\Registry;

final readonly class ManagedTreeEvidence
{
    public function __construct(
        public string $kind,
        public string $existence,
        public string $integrity,
        public string $ownership,
        public ?string $code = null,
        public ?string $version = null,
        public ?string $type = null,
        public ?string $vendor = null,
        public ?string $operationId = null,
        public ?string $manifestDigest = null,
        public ?string $inventoryDigest = null,
        public int $fileCount = 0,
        public int $totalBytes = 0,
        public string $diagnosticCode = 'tree_missing',
        public string $diagnosticMessage = 'Managed tree is missing.',
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
