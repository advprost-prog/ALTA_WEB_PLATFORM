<?php

namespace App\Support\Addons\Registry;

final class ArtifactPromotionStatus
{
    public const NOT_PROMOTED = 'not_promoted';
    public const READY = 'ready';
    public const PROMOTING = 'promoting';
    public const PROMOTED = 'promoted';
    public const BLOCKED = 'blocked';
    public const FAILED = 'failed';
    public const ROLLBACK_AVAILABLE = 'rollback_available';
    public const ROLLING_BACK = 'rolling_back';
    public const ROLLED_BACK = 'rolled_back';
    public const ROLLBACK_FAILED = 'rollback_failed';
    public const STALE = 'stale';

    public const LABELS = [
        self::NOT_PROMOTED => 'Не перенесено',
        self::READY => 'Готовий до перенесення',
        self::PROMOTING => 'Перенесення',
        self::PROMOTED => 'Перенесено',
        self::BLOCKED => 'Заблоковано',
        self::FAILED => 'Помилка перенесення',
        self::ROLLBACK_AVAILABLE => 'Доступний rollback',
        self::ROLLING_BACK => 'Відкат',
        self::ROLLED_BACK => 'Відкочено',
        self::ROLLBACK_FAILED => 'Помилка відкату',
        self::STALE => 'Promotion застарів',
    ];

    public const COLORS = [
        self::NOT_PROMOTED => 'gray',
        self::READY => 'success',
        self::PROMOTING => 'warning',
        self::PROMOTED => 'success',
        self::BLOCKED => 'warning',
        self::FAILED => 'danger',
        self::ROLLBACK_AVAILABLE => 'warning',
        self::ROLLING_BACK => 'warning',
        self::ROLLED_BACK => 'success',
        self::ROLLBACK_FAILED => 'danger',
        self::STALE => 'danger',
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