<?php

namespace App\Support\Addons\Registry;

/**
 * Review workflow states for quarantined remote artifacts (Phase 3.3).
 *
 * Only these four values are valid; metadata.json must never store an
 * arbitrary string outside this set.
 */
final class ArtifactReviewStatus
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const REVOKED = 'revoked';

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::PENDING => 'Очікує перевірки',
        self::APPROVED => 'Схвалено',
        self::REJECTED => 'Відхилено',
        self::REVOKED => 'Схвалення відкликано',
    ];

    /**
     * @var array<string, string>
     */
    public const COLORS = [
        self::PENDING => 'warning',
        self::APPROVED => 'success',
        self::REJECTED => 'danger',
        self::REVOKED => 'gray',
    ];

    /**
     * @param  array<int, string>  $extra
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::APPROVED,
            self::REJECTED,
            self::REVOKED,
        ];
    }

    public static function isValid(string $status): bool
    {
        return isset(self::LABELS[$status]);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function color(string $status): string
    {
        return self::COLORS[$status] ?? 'gray';
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return self::LABELS;
    }

    /**
     * @return array<string, string>
     */
    public static function colors(): array
    {
        return self::COLORS;
    }
}
