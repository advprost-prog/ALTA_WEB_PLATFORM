<?php

namespace App\Support\Addons\Registry;

/**
 * Result of {@see ArtifactTrustEvaluator::evaluate()}.
 */
final class ArtifactTrustResult
{
    public function __construct(
        public readonly string $trustStatus,
        public readonly array $diagnostics = [],
    ) {}

    public function isTrusted(): bool
    {
        return $this->trustStatus === ArtifactTrustEvaluator::TRUST_TRUSTED;
    }

    public function isRejected(): bool
    {
        return $this->trustStatus === ArtifactTrustEvaluator::TRUST_REJECTED;
    }

    public function label(): string
    {
        return ArtifactTrustEvaluator::LABELS[$this->trustStatus] ?? $this->trustStatus;
    }
}
