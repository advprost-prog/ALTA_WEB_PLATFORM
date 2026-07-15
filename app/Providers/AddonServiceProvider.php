<?php

namespace App\Providers;

use App\Support\Addons\AddonEventLogger;
use App\Support\Addons\AddonHookRegistry;
use App\Support\Addons\AddonManager;
use App\Support\Addons\Marketplace\MarketplaceCatalog;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Providers\PackageScopedAutoloadRegistry;
use App\Support\Addons\Registry\RegistryCatalog;
use App\Support\Addons\Registry\RegistryClient;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AddonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AddonHookRegistry::class);
        $this->app->singleton(MarketplaceCatalog::class);
        $this->app->singleton(MarketplaceManager::class);
        $this->app->singleton(PackageScopedAutoloadRegistry::class);

        $this->app->singleton(RegistryClient::class, fn ($app) => new RegistryClient(config('addons-registry', [])));
        $this->app->singleton(RegistryCatalog::class, fn ($app) => new RegistryCatalog(
            $app->make(RegistryClient::class),
            config('addons-registry', []),
        ));
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
