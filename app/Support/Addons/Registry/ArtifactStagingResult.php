<?php

namespace App\Support\Addons\Registry;

final class ArtifactStagingResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $code,
        public readonly ?string $version,
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $stagingPath = null,
        public readonly int $fileCount = 0,
        public readonly int $totalSize = 0,
        public readonly ?string $manifestPath = null,
        public readonly array $diagnostics = [],
        public readonly array $blockedReasons = [],
        public readonly array $inventory = [],
        public readonly array $metadata = [],
    ) {}

    public static function success(string $code, string $version, string $message, array $data = []): self
    {
        return new self(true, $code, $version, ArtifactStagingStatus::STAGED, $message, $data['staging_path'] ?? null,
            $data['file_count'] ?? 0, $data['total_size'] ?? 0, $data['manifest_path'] ?? null, [], [],
            $data['inventory'] ?? [], $data['metadata'] ?? []);
    }

    public static function failure(string $code, ?string $version, string $status, string $message, array $reasons = []): self
    {
        return new self(false, $code, $version, $status, $message, diagnostics: $reasons, blockedReasons: $reasons);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
