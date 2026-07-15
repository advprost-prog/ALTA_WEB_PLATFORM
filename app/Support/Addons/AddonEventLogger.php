<?php

namespace App\Support\Addons;

use App\Models\SystemAddonEvent;
use App\Support\Addons\Registry\AddonRecoveryHealthCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AddonEventLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function info(?string $addonCode, string $event, string $message, array $context = []): void
    {
        $this->log($addonCode, $event, SystemAddonEvent::LEVEL_INFO, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(?string $addonCode, string $event, string $message, array $context = []): void
    {
        $this->log($addonCode, $event, SystemAddonEvent::LEVEL_WARNING, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(?string $addonCode, string $event, string $message, array $context = []): void
    {
        $this->log($addonCode, $event, SystemAddonEvent::LEVEL_ERROR, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(?string $addonCode, string $event, string $level, string $message, array $context): void
    {
        if (! Schema::hasTable('system_addon_events')) {
            return;
        }

        SystemAddonEvent::create([
            'addon_code' => $addonCode,
            'event' => $event,
            'level' => $level,
            'message' => $message,
            'context' => $context === [] ? null : $context,
        ]);

        if (str_contains($event, 'recovery') || str_contains($event, 'rollback') || str_contains($event, 'cleanup') || str_contains($event, 'install')) {
            Cache::forget(AddonRecoveryHealthCache::KEY);
        }
    }
}
