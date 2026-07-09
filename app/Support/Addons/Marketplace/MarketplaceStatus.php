<?php

namespace App\Support\Addons\Marketplace;

/**
 * Computed statuses for marketplace catalog items.
 *
 * These extend the Phase 1 lifecycle statuses (discovered/installed/enabled/
 * disabled/failed/removed) with marketplace-specific states that describe the
 * relationship between a catalog entry and the local system_addons table.
 */
final class MarketplaceStatus
{
    public const AVAILABLE = 'available';

    public const DISCOVERED = 'discovered';

    public const INSTALLED = 'installed';

    public const ENABLED = 'enabled';

    public const DISABLED = 'disabled';

    public const MISSING_FILES = 'missing_files';

    public const INVALID = 'invalid';

    public const FAILED = 'failed';

    public const REMOVED = 'removed';

    public const REMOTE_AVAILABLE = 'remote_available';

    public const LOCAL_AVAILABLE = 'local_available';

    public const REMOTE_ONLY = 'remote_only';

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::AVAILABLE => 'Доступний',
        self::DISCOVERED => 'Виявлено',
        self::INSTALLED => 'Встановлено',
        self::ENABLED => 'Увімкнено',
        self::DISABLED => 'Вимкнено',
        self::MISSING_FILES => 'Файли відсутні',
        self::INVALID => 'Некоректний',
        self::FAILED => 'Помилка',
        self::REMOVED => 'Видалено',
        self::REMOTE_AVAILABLE => 'У registry',
        self::LOCAL_AVAILABLE => 'Локально доступний',
        self::REMOTE_ONLY => 'Тільки у registry',
    ];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::LABELS;
    }
}
