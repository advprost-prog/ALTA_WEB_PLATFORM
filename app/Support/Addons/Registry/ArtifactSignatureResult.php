<?php

namespace App\Support\Addons\Registry;

/**
 * Result of {@see ArtifactSignatureVerifier::verify()}.
 *
 * Never throws a fatal exception to the UI: verification problems are reported
 * via {@see $status} and {@see $diagnostics} so the Marketplace UI/CLI/doctor
 * can render a diagnostic instead of crashing.
 */
final class ArtifactSignatureResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $keyId = null,
        public readonly array $diagnostics = [],
    ) {}

    public function isTrusted(): bool
    {
        return $this->status === ArtifactSignatureVerifier::STATUS_VALID;
    }

    public function label(): string
    {
        return ArtifactSignatureVerifier::LABELS[$this->status] ?? $this->status;
    }
}
