<?php

namespace App\Support\Addons\Marketplace;

/**
 * Platform compatibility status for a marketplace catalog item.
 *
 * Computed from the item's platform constraint (requires_platform /
 * platform_version) against the current platform version.
 */
final class CompatibilityStatus
{
    public const COMPATIBLE = 'compatible';

    public const INCOMPATIBLE = 'incompatible';

    public const UNKNOWN = 'unknown';

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::COMPATIBLE => 'Сумісний',
        self::INCOMPATIBLE => 'Несумісний',
        self::UNKNOWN => 'Сумісність невідома',
    ];

    /**
     * @var array<string, string>
     */
    public const COLORS = [
        self::COMPATIBLE => 'success',
        self::INCOMPATIBLE => 'danger',
        self::UNKNOWN => 'warning',
    ];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function color(string $status): string
    {
        return self::COLORS[$status] ?? 'gray';
    }
}
