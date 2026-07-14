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
        public bool $destructiveActionExpected,
        public array $manualReasons,
        public array $diagnostics,
        public string $fingerprint,
        public array $evidence,
        public ManagedTreeEvidence $liveEvidence,
        public ManagedTreeEvidence $backupEvidence,
        public ManagedTreeEvidence $candidateEvidence,
        public ManagedTreeEvidence $stagingEvidence,
    ) {}

    public function toArray(): array
    {
        return [
            ...get_object_vars($this),
            'liveEvidence' => $this->liveEvidence->toArray(),
            'backupEvidence' => $this->backupEvidence->toArray(),
            'candidateEvidence' => $this->candidateEvidence->toArray(),
            'stagingEvidence' => $this->stagingEvidence->toArray(),
        ];
    }
}
