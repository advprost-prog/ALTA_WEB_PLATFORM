<?php

namespace App\Support\Addons\Registry;

final class ArtifactStagingStatus
{
    public const NOT_STAGED = 'not_staged';

    public const STAGING = 'staging';

    public const STAGED = 'staged';

    public const BLOCKED = 'blocked';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    public const STALE = 'stale';

    public const LABELS = [
        self::NOT_STAGED => 'Не підготовлено', self::STAGING => 'Підготовка', self::STAGED => 'У staging',
        self::BLOCKED => 'Заблоковано', self::REJECTED => 'Відхилено', self::FAILED => 'Помилка', self::STALE => 'Staging застарів',
    ];

    public const COLORS = [
        self::NOT_STAGED => 'gray', self::STAGING => 'warning', self::STAGED => 'success', self::BLOCKED => 'warning',
        self::REJECTED => 'danger', self::FAILED => 'danger', self::STALE => 'danger',
    ];
}
