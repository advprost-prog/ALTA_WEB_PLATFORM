<?php

namespace Tests\Feature;

use Alta\BackupRestore\Contracts\HostRestoreBridge;
use Alta\BackupRestore\Filament\Pages\BackupRestoreDashboard;
use Alta\BackupRestore\Filament\Resources\ProfileResource;
use Alta\BackupRestore\Filament\Resources\ScheduleResource;
use Alta\BackupRestore\Models\Artifact;
use Alta\BackupRestore\Models\BackupRun;
use Alta\BackupRestore\Models\Profile;
use Alta\BackupRestore\Services\HostRestoreBridgeProxy;
use App\Models\SystemAddon;
use App\Providers\Filament\AdminPanelProvider;
use App\Support\Addons\AddonManager;
use App\Support\Addons\AddonRegistry;
use App\Support\Addons\FilamentAddonComponents;
use Filament\Facades\Filament;
use Filament\Panel;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class ExternalAddonPackageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $integrationRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->integrationRoot = base_path('modules/ExternalContract');
        config()->set('addons-registry.live_roots.modules_path', base_path('modules'));
        config()->set('addons-registry.live_roots.extensions_path', base_path('extensions'));
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
        $source = $this->backupRestoreAddonRoot();
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
        $this->assertInstanceOf(HostRestoreBridgeProxy::class, app(HostRestoreBridge::class));
        $components = app(FilamentAddonComponents::class)->enabled();
        $this->assertContains(BackupRestoreDashboard::class, $components['pages']);
        $this->assertContains(ProfileResource::class, $components['resources']);
        $this->assertContains(ScheduleResource::class, $components['resources']);
        $configuredPanel = (new AdminPanelProvider(app()))->panel(Panel::make());
        $this->assertContains(BackupRestoreDashboard::class, $configuredPanel->getPages());
        $this->assertContains(ProfileResource::class, $configuredPanel->getResources());
        $this->assertContains(ScheduleResource::class, $configuredPanel->getResources());
        $panel = Filament::getPanel('admin');
        Route::name('filament.admin.')->prefix('admin')->group(function () use ($components, $panel): void {
            foreach ($components['pages'] as $page) {
                $page::registerRoutes($panel);
            }
            foreach ($components['resources'] as $resource) {
                $resource::registerRoutes($panel);
            }
        });
        app('router')->getRoutes()->refreshNameLookups();
        $routeNames = collect(app('router')->getRoutes())->map(fn ($route) => $route->getName())->filter()->values()->all();
        $this->assertContains('filament.admin.pages.backup-restore-dashboard', $routeNames);
        $this->assertSame('/admin/backup-restore-dashboard', parse_url(route('filament.admin.pages.backup-restore-dashboard'), PHP_URL_PATH));
        $this->assertTrue(Route::has('filament.admin.resources.profiles.index'));
        $this->assertTrue(Route::has('filament.admin.resources.schedules.index'));

        $migrations = [];
        foreach (['2026_07_15_000001_create_backup_restore_foundation.php', '2026_07_17_000002_add_file_limits_to_backup_profiles.php', '2026_07_18_000003_create_restore_planning_tables.php', '2026_07_18_000004_create_files_restore_execution_tables.php', '2026_07_18_000005_create_postgresql_staging_verifications.php'] as $file) {
            $migrations[] = require $root.'/database/migrations/'.$file;
            $migrations[array_key_last($migrations)]->up();
        }
        $this->assertTrue(Schema::hasTable('alta_backup_restore_profiles'));
        $profile = Profile::create(['name' => 'Lifecycle guard', 'status' => 'active', 'include_database' => false, 'include_files' => true, 'source_roots' => ['public_uploads'], 'destination' => 'local']);
        $preservedRun = BackupRun::create(['profile_id' => $profile->id, 'status' => 'completed', 'trigger_type' => 'manual']);
        $preservedArtifact = Artifact::create(['backup_run_id' => $preservedRun->id, 'status' => 'available', 'disk' => 'local', 'relative_path' => 'addons/alta.backup-restore/artifacts/v060.zip', 'original_filename' => 'v060.zip']);
        File::ensureDirectoryExists(storage_path('app/private/addons/alta.backup-restore/artifacts'));
        File::put(storage_path('app/private/'.$preservedArtifact->relative_path), 'v060-artifact');
        $v1Migration = require $root.'/database/migrations/2026_07_19_000006_complete_v1_operations.php';
        $v1Migration->up();
        $migrations[] = $v1Migration;
        $this->assertDatabaseHas('alta_backup_restore_artifacts', ['id' => $preservedArtifact->id]);
        $this->assertFileExists(storage_path('app/private/'.$preservedArtifact->relative_path));
        $run = BackupRun::create(['profile_id' => $profile->id, 'status' => 'running', 'trigger_type' => 'manual']);
        try {
            $manager->disable($addon->code);
            $this->fail('Active backup must block addon disable.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('backup_conflicting_operation', $exception->getMessage());
        }
        try {
            $addon->version = '9.9.9';
            $addon->save();
            $this->fail('Active backup must block addon code update.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('backup_conflicting_operation', $exception->getMessage());
            $addon->refresh();
        }
        $run->forceFill(['status' => 'completed'])->save();

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }
        $this->assertFalse(Schema::hasTable('alta_backup_restore_profiles'));

        $manager->disable($addon->code);
        $manager->bootEnabledAddons();
        $this->assertFalse($addon->refresh()->is_enabled);
        $this->assertSame(['pages' => [], 'resources' => []], app(FilamentAddonComponents::class)->enabled());
        $manager->enable($addon->code);
        $this->assertContains(BackupRestoreDashboard::class, app(FilamentAddonComponents::class)->enabled()['pages']);
        $manager->disable($addon->code);
        $manager->uninstall($addon->code);
        $this->assertFalse($addon->refresh()->is_installed);
    }

    public function test_real_backup_restore_addon_path_rejects_invalid_package(): void
    {
        $root = sys_get_temp_dir().'/invalid-backup-addon-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($root);
        File::put($root.'/module.json', json_encode([
            'code' => 'wrong.addon',
            'service_provider' => 'Wrong\\Provider',
        ], JSON_THROW_ON_ERROR));
        File::put($root.'/composer.json', json_encode([
            'name' => 'wrong/addon',
            'autoload' => ['psr-4' => ['Wrong\\' => 'src/']],
        ], JSON_THROW_ON_ERROR));
        foreach (['src', 'config', 'database', 'resources'] as $directory) {
            File::ensureDirectoryExists($root.'/'.$directory);
        }

        $previous = getenv('ALTA_BACKUP_RESTORE_ADDON_PATH');
        putenv('ALTA_BACKUP_RESTORE_ADDON_PATH='.$root);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('unexpected addon identity');
            $this->backupRestoreAddonRoot();
        } finally {
            $previous === false
                ? putenv('ALTA_BACKUP_RESTORE_ADDON_PATH')
                : putenv('ALTA_BACKUP_RESTORE_ADDON_PATH='.$previous);
            File::deleteDirectory($root);
        }
    }

    private function backupRestoreAddonRoot(): string
    {
        $configured = getenv('ALTA_BACKUP_RESTORE_ADDON_PATH');
        if ($configured === false || trim($configured) === '') {
            $this->markTestSkipped('Set ALTA_BACKUP_RESTORE_ADDON_PATH to run the real Backup & Restore addon integration gate.');
        }

        $configured = rtrim(trim($configured), DIRECTORY_SEPARATOR);
        $normalized = str_replace('\\', '/', $configured);
        if ($configured === '' || in_array('..', explode('/', $normalized), true) || is_link($configured)) {
            throw new InvalidArgumentException('ALTA_BACKUP_RESTORE_ADDON_PATH must be a real, non-symlink directory.');
        }
        $root = realpath($configured);
        if ($root === false || ! is_dir($root) || ! is_readable($root)) {
            throw new InvalidArgumentException('ALTA_BACKUP_RESTORE_ADDON_PATH does not resolve to a readable directory.');
        }

        foreach (['module.json', 'composer.json'] as $file) {
            $path = $root.'/'.$file;
            if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
                throw new InvalidArgumentException("Backup & Restore package {$file} must be a readable regular file.");
            }
        }
        foreach (['src', 'config', 'database', 'resources'] as $directory) {
            $path = $root.'/'.$directory;
            $real = realpath($path);
            if ($real === false || is_link($path) || ! is_dir($real)
                || ! str_starts_with($real.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)) {
                throw new InvalidArgumentException("Backup & Restore package {$directory} must remain inside the addon root.");
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($iterator as $entry) {
                $entryReal = $entry->getRealPath();
                if ($entry->isLink() || $entryReal === false
                    || ! str_starts_with($entryReal, $root.DIRECTORY_SEPARATOR)
                    || (! $entry->isDir() && ! $entry->isFile())
                    || ($entry->isFile() && ! $entry->isReadable())) {
                    throw new InvalidArgumentException("Backup & Restore package {$directory} contains an unsafe entry.");
                }
            }
        }

        $module = json_decode((string) file_get_contents($root.'/module.json'), true, 512, JSON_THROW_ON_ERROR);
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($module['code'] ?? null) !== 'alta.backup-restore'
            || ($module['service_provider'] ?? null) !== 'Alta\\BackupRestore\\BackupRestoreServiceProvider') {
            throw new InvalidArgumentException('ALTA_BACKUP_RESTORE_ADDON_PATH has an unexpected addon identity.');
        }
        if (($composer['name'] ?? null) !== 'alta/addon-backup-restore'
            || ($composer['autoload']['psr-4']['Alta\\BackupRestore\\'] ?? null) !== 'src/') {
            throw new InvalidArgumentException('ALTA_BACKUP_RESTORE_ADDON_PATH has an invalid Composer package contract.');
        }

        return $root;
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
