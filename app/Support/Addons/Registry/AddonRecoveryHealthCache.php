<?php

namespace App\Support\Addons\Registry;

use Illuminate\Support\Facades\Cache;

final class AddonRecoveryHealthCache
{
    public const KEY = 'addons:recovery-health:v1';

    public function invalidate(): void
    {
        Cache::forget(self::KEY);
    }
}
