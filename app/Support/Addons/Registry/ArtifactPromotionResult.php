<?php

namespace App\Support\Addons\Registry;

final class ArtifactPromotionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $code,
        public readonly ?string $version,
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $addonType = null,
        public readonly ?string $livePath = null,
        public readonly ?string $backupPath = null,
        public readonly ?string $transactionId = null,
        public readonly array $diagnostics = [],
        public readonly array $blockedReasons = [],
        public readonly array $metadata = [],
        public readonly bool $rollbackAvailable = false,
    ) {}

    public static function success(string $code, ?string $version, string $status, string $message, array $data = []): self
    {
        return new self(true, $code, $version, $status, $message, $data['addon_type'] ?? null, $data['live_path'] ?? null, $data['backup_path'] ?? null, $data['transaction_id'] ?? null, [], [], $data['metadata'] ?? [], (bool) ($data['rollback_available'] ?? false));
    }

    public static function failure(string $code, ?string $version, string $status, string $message, array $reasons = [], array $data = []): self
    {
        return new self(false, $code, $version, $status, $message, $data['addon_type'] ?? null, $data['live_path'] ?? null, $data['backup_path'] ?? null, $data['transaction_id'] ?? null, $reasons, $reasons, $data['metadata'] ?? [], (bool) ($data['rollback_available'] ?? false));
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}