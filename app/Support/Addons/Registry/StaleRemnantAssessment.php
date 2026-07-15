<?php

namespace App\Support\Addons\Registry;

final readonly class StaleRemnantAssessment
{
    public function __construct(
        public string $identifier, public string $kind, public ?string $operationId,
        public ?string $addonCode, public string $managedStatus, public string $ownershipStatus,
        public ?int $ageSeconds, public bool $activeLock, public bool $journalReference,
        public bool $terminalOperation, public bool $eligible, public string $reason,
        public string $fingerprint,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
