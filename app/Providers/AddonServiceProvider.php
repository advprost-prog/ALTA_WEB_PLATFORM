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
            $this->configureBackupRestorePostgreSqlTools();
        } catch (Throwable $exception) {
            $events->error(null, 'addon_boot_manager_failed', 'Addon boot manager failed.', [
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function configureBackupRestorePostgreSqlTools(): void
    {
        $host = config('backup-restore-host.postgresql_backup', []);
        if (! is_array($host) || ($host['enabled'] ?? false) !== true || ! config()->has('alta-backup-restore.database.postgresql')) {
            return;
        }

        $directory = $host['client_directory'] ?? null;
        $libraryPath = $host['library_path'] ?? null;
        $connections = array_values(array_filter((array) ($host['connections'] ?? []), 'is_string'));
        $majors = array_values(array_filter((array) ($host['server_major_versions'] ?? []), 'is_int'));
        if (! is_string($directory) || $directory === '' || ! is_string($libraryPath) || $libraryPath === '' || $connections === [] || $majors === []) {
            return;
        }

        $definitions = [];
        foreach ($connections as $connection) {
            if (config('database.connections.'.$connection.'.driver') !== 'pgsql') {
                continue;
            }
            $definitions[$connection] = [
                'engine' => 'postgresql',
                'role' => 'primary',
                'backup_enabled' => true,
                'restore_enabled' => false,
                'allowed_server_major_versions' => $majors,
                'expected_schemas' => ['public'],
            ];
        }
        if ($definitions === []) {
            return;
        }

        config([
            'alta-backup-restore.execution.backup_enabled' => true,
            'alta-backup-restore.database.postgresql.enabled' => true,
            'alta-backup-restore.database.postgresql.trusted_binary_directories' => [$directory],
            'alta-backup-restore.database.postgresql.process_environment.LD_LIBRARY_PATH' => $libraryPath,
            'alta-backup-restore.database.allowed_connections' => $definitions,
        ]);
    }
}
