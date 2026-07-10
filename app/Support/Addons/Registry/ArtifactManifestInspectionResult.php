<?php

namespace App\Support\Addons\Registry;

/**
 * Result of {@see QuarantinedArtifactInspector::inspect()}.
 */
final class ArtifactManifestInspectionResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?array $manifest = null,
        public readonly ?string $manifestPath = null,
        public readonly array $diagnostics = [],
    ) {}

    public function isTrusted(): bool
    {
        return $this->status === QuarantinedArtifactInspector::STATUS_VALID;
    }

    public function label(): string
    {
        return QuarantinedArtifactInspector::LABELS[$this->status] ?? $this->status;
    }
}
