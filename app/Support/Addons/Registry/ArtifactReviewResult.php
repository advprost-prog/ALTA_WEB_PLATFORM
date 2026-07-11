<?php

namespace App\Support\Addons\Registry;

/**
 * Result of an {@see ArtifactReviewManager} operation.
 *
 * Never throws for expected business-blocks (untrusted, rejected, etc.) —
 * the caller inspects {@see $success} and {@see $diagnostics}/{$blockedReasons}.
 */
final class ArtifactReviewResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?string $reviewStatus = null,
        public readonly array $diagnostics = [],
        public readonly ?array $report = null,
        public readonly string $code = '',
        public readonly string $message = '',
        public readonly array $blockedReasons = [],
    ) {}

    public function label(): string
    {
        return $this->reviewStatus !== null ? ArtifactReviewStatus::label($this->reviewStatus) : $this->status;
    }

    /**
     * @return array{success: bool, status: string, review_status: string|null, code: string, message: string, diagnostics: list<string>, blocked_reasons: list<string>, report: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'review_status' => $this->reviewStatus,
            'code' => $this->code,
            'message' => $this->message,
            'diagnostics' => $this->diagnostics,
            'blocked_reasons' => $this->blockedReasons,
            'report' => $this->report,
        ];
    }

    public static function success(
        string $action,
        string $code,
        ?string $reviewStatus,
        ?array $report = null,
        string $message = '',
    ): self {
        return new self(true, $action, $reviewStatus, [], $report, $code, $message, []);
    }

    public static function failure(
        string $action,
        string $code,
        array $blockedReasons,
        ?string $reviewStatus = null,
        string $message = '',
    ): self {
        return new self(false, $action, $reviewStatus, $blockedReasons, null, $code, $message, $blockedReasons);
    }
}
