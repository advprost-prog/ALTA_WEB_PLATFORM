<?php

namespace App\Support\Addons\Marketplace;

/**
 * Local update status for a marketplace catalog item.
 *
 * Describes the relationship between the installed version (system_addons.version)
 * and the available version (catalog item version).
 */
final class UpdateStatus
{
    public const NOT_INSTALLED = 'not_installed';

    public const UP_TO_DATE = 'up_to_date';

    public const UPDATE_AVAILABLE = 'update_available';

    public const INSTALLED_NEWER = 'installed_newer';

    public const UNKNOWN = 'unknown';

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::NOT_INSTALLED => 'Не встановлено',
        self::UP_TO_DATE => 'Актуальна',
        self::UPDATE_AVAILABLE => 'Доступне оновлення',
        self::INSTALLED_NEWER => 'Встановлена новіша',
        self::UNKNOWN => 'Невідомо',
    ];

    /**
     * @var array<string, string>
     */
    public const COLORS = [
        self::NOT_INSTALLED => 'gray',
        self::UP_TO_DATE => 'success',
        self::UPDATE_AVAILABLE => 'warning',
        self::INSTALLED_NEWER => 'info',
        self::UNKNOWN => 'gray',
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
