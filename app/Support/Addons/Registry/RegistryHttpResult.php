<?php

namespace App\Support\Addons\Registry;

final readonly class RegistryHttpResult
{
    public function __construct(
        public int $status,
        public ?array $payload,
        public ?string $etag,
        public ?string $lastModified,
        public ?string $contentType,
        public ?string $cacheControl,
        public ?string $retryAfter,
        public string $sourceUrl,
        public string $sourceHost,
        public string $requestedAt,
        public string $respondedAt,
        public ?string $errorCategory = null,
        public ?string $diagnostic = null,
        public ?array $internalContext = null,
    ) {}

    public function isSuccessful(): bool
    {
        return in_array($this->status, [200, 304], true) && $this->errorCategory === null;
    }
}
