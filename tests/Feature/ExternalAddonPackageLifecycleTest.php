<?php

namespace Tests\Feature;

use App\Models\SystemAddon;
use App\Support\Addons\AddonManager;
use App\Support\Addons\AddonRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ExternalAddonPackageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $integrationRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->integrationRoot = base_path('modules/ExternalContract');
        File::deleteDirectory($this->integrationRoot);
        File::ensureDirectoryExists($this->integrationRoot);
    }

    protected function tearDown(): void
    {
        app(AddonManager::class)->lifecycle->unregisterServiceProvider('neutral.external-package');
        app(AddonManager::class)->lifecycle->unregisterServiceProvider('alta.backup-restore');
        File::deleteDirectory($this->integrationRoot);
        parent::tearDown();
    }

    public function test_generic_external_package_lifecycle_is_disabled_by_default_and_idempotent(): void
    {
        $root = $this->integrationRoot.'/NeutralPackage';
        $this->writeExternalPackage($root, 'neutral.external-package', 'NeutralVendor\\Package\\NeutralProvider');
        $manager = app(AddonManager::class);

        $manager->discover();
        $addon = app(AddonRegistry::class)->find('neutral.external-package');
        $this->assertNotNull($addon);
        $this->assertFalse($addon->is_installed);
        $this->assertFalse($addon->is_enabled);

        $manager->install($addon->code);
        $this->assertFalse($addon->refresh()->is_enabled);
        $manager->bootEnabledAddons();
        $this->assertFalse(app()->bound('neutral.external-package.booted'));

        $manager->enable($addon->code);
        $this->assertTrue(app()->bound('neutral.external-package.booted'));
        $events = DB::table('system_addon_events')->count();
        $manager->bootEnabledAddons();
        $manager->bootEnabledAddons();
        $this->assertSame($events, DB::table('system_addon_events')->count());

        $manager->disable($addon->code);
        $manager->bootEnabledAddons();
        $this->assertFalse($addon->refresh()->is_enabled);
        $manager->uninstall($addon->code);
        $this->assertFalse($addon->refresh()->is_installed);
    }

    public function test_real_backup_restore_addon_passes_isolated_lifecycle_and_migration_gate(): void
    {
        $source = '/home/olykh/projects/alta-addon-backup-restore';
        $root = $this->integrationRoot.'/BackupRestore';
        foreach (['module.json', 'composer.json', 'README.md'] as $file) {
            File::ensureDirectoryExists($root);
            File::copy($source.'/'.$file, $root.'/'.$file);
        }
        foreach (['src', 'config', 'database', 'resources'] as $directory) {
            File::copyDirectory($source.'/'.$directory, $root.'/'.$directory);
        }

        $manager = app(AddonManager::class);
        $scan = $manager->discovery->scan();
        $entry = collect($scan['manifests'])->first(fn (array $candidate): bool => $candidate['manifest']['code'] === 'alta.backup-restore');
        $this->assertIsArray($entry);
        $manager->discover();
        $addon = app(AddonRegistry::class)->find('alta.backup-restore');
        $this->assertNotNull($addon);
        $this->assertSame('Alta\\BackupRestore\\BackupRestoreServiceProvider', $addon->service_provider);

        $manager->install($addon->code);
        $this->assertFalse($addon->refresh()->is_enabled);
        $this->assertFalse(app()->providerIsLoaded($addon->service_provider));
        $manager->bootEnabledAddons();
        $this->assertFalse(app()->providerIsLoaded($addon->service_provider));

        $beforeFiles = $this->filesystemSnapshot($root);
        $manager->enable($addon->code);
        $this->assertTrue(app()->providerIsLoaded($addon->service_provider));
        $events = DB::table('system_addon_events')->count();
        $manager->bootEnabledAddons();
        $manager->bootEnabledAddons();
        $this->assertSame($events, DB::table('system_addon_events')->count());
        $this->assertSame($beforeFiles, $this->filesystemSnapshot($root));

        $migration = require $root.'/database/migrations/2026_07_15_000001_create_backup_restore_foundation.php';
        $migration->up();
        $this->assertTrue(Schema::hasTable('alta_backup_restore_profiles'));
        $migration->down();
        $this->assertFalse(Schema::hasTable('alta_backup_restore_profiles'));

        $manager->disable($addon->code);
        $manager->bootEnabledAddons();
        $this->assertFalse($addon->refresh()->is_enabled);
        $manager->uninstall($addon->code);
        $this->assertFalse($addon->refresh()->is_installed);
    }

    private function writeExternalPackage(string $root, string $code, string $provider): void
    {
        File::ensureDirectoryExists($root.'/src');
        File::put($root.'/composer.json', json_encode([
            'name' => 'neutral-vendor/external-package',
            'autoload' => ['psr-4' => ['NeutralVendor\\Package\\' => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($root.'/module.json', json_encode([
            'code' => $code, 'type' => SystemAddon::TYPE_MODULE, 'name' => 'Neutral External Package',
            'version' => '1.0.0', 'vendor' => 'Neutral Vendor', 'enabled_by_default' => false,
            'service_provider' => $provider, 'dependencies' => [], 'settings_schema' => [],
            'permissions' => [], 'menu' => [], 'migrations' => [], 'seeders' => [], 'routes' => [],
            'compatibility' => ['laravel_version' => '>=12.0', 'php_version' => '>=8.3'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($root.'/src/NeutralProvider.php', <<<'PHP'
<?php
namespace NeutralVendor\Package;
use Illuminate\Support\ServiceProvider;
final class NeutralProvider extends ServiceProvider
{
    public function boot(): void { app()->instance('neutral.external-package.booted', true); }
}
PHP);
    }

    /** @return array<string, array{int,int}> */
    private function filesystemSnapshot(string $root): array
    {
        $snapshot = [];
        foreach (File::allFiles($root) as $file) {
            $snapshot[$file->getRelativePathname()] = [$file->getSize(), $file->getMTime()];
        }
        ksort($snapshot);

        return $snapshot;
    }
}
