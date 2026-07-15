<?php

namespace App\Support\Addons\Registry;

final readonly class BackupRetentionAssessment
{
    public function __construct(
        public string $backupId, public ?string $addonCode, public ?string $version,
        public ?string $sourceOperationId, public string $integrityStatus, public string $backupStatus,
        public ?string $createdAt, public ?string $verifiedAt, public ?int $ageSeconds,
        public bool $referencedByIncompleteOperation, public bool $referencedByRollbackOperation,
        public bool $currentSafetyBackup, public bool $lastKnownGood, public bool $manuallyRetained,
        public bool $eligible, public string $reason, public string $fingerprint,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
