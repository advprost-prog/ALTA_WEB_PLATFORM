<?php

namespace App\Support\Addons\Registry;

final class ArtifactDownloadResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?string $path,
        public readonly ?string $metadataPath,
        public readonly ?array $metadata,
        public readonly array $diagnostics = [],
    ) {}

    public static function success(string $path, ?string $metadataPath, array $metadata = []): self
    {
        return new self(
            success: true,
            status: 'quarantined',
            path: $path,
            metadataPath: $metadataPath,
            metadata: $metadata,
            diagnostics: [],
        );
    }

    public static function failed(string $status, array $diagnostics = []): self
    {
        return new self(
            success: false,
            status: $status,
            path: null,
            metadataPath: null,
            metadata: null,
            diagnostics: $diagnostics,
        );
    }
}
