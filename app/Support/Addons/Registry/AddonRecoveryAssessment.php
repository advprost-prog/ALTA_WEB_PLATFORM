<?php

namespace App\Support\Addons\Registry;

final readonly class AddonRecoveryAssessment
{
    public function __construct(
        public string $operationId,
        public string $code,
        public string $operationType,
        public string $journalState,
        public ?string $previousVersion,
        public ?string $targetVersion,
        public string $classification,
        public string $proposedAction,
        public bool $automaticEligible,
        public array $diagnostics,
        public string $fingerprint,
        public array $evidence,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
