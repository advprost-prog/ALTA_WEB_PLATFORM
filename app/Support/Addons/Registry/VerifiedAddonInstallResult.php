<?php

namespace App\Support\Addons\Registry;

final readonly class VerifiedAddonInstallResult
{
    public function __construct(
        public bool $success,
        public string $code,
        public ?string $version,
        public string $operationId,
        public string $operationType,
        public string $state,
        public ?string $failureCode = null,
        public array $diagnostics = [],
        public bool $enabled = false,
        public bool $rolledBack = false,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
