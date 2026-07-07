<?php

namespace App\Providers;

use App\Support\Addons\AddonEventLogger;
use App\Support\Addons\AddonHookRegistry;
use App\Support\Addons\AddonManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AddonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AddonHookRegistry::class);
    }

    public function boot(AddonManager $manager, AddonEventLogger $events): void
    {
        if (! Schema::hasTable('system_addons')) {
            return;
        }

        try {
            $manager->bootEnabledAddons();
        } catch (Throwable $exception) {
            $events->error(null, 'addon_boot_manager_failed', 'Addon boot manager failed.', [
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
