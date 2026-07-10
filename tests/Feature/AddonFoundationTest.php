<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\SystemAddons\SystemAddonResource;
use App\Support\Addons\AddonDiscovery;
use App\Support\Addons\AddonHealthCheck;
use App\Support\Addons\AddonHookRegistry;
use App\Support\Addons\AddonManager;
use App\Support\Addons\AddonRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class AddonFoundationTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    private string $testModulesPath;

    private string $testExtensionsPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep local addon CLI/lifecycle tests independent of a remote registry.
        Config::set('addons-registry.enabled', false);

        $this->testModulesPath = base_path('modules/TestSuite');
        $this->testExtensionsPath = base_path('extensions/TestSuite');
        File::deleteDirectory($this->testModulesPath);
        File::deleteDirectory($this->testExtensionsPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testModulesPath);
        File::deleteDirectory($this->testExtensionsPath);

        parent::tearDown();
    }

    public function test_discovery_finds_local_module_and_extension_manifests(): void
    {
        $this->artisan('addons:discover')
            ->assertExitCode(0);

        $this->assertDatabaseHas('system_addons', [
            'code' => 'demo.hello-module',
            'type' => 'module',
            'status' => 'discovered',
        ]);
        $this->assertDatabaseHas('system_addons', [
            'code' => 'demo.admin-widget',
            'type' => 'extension',
            'status' => 'discovered',
        ]);
    }

    public function test_invalid_manifest_is_reported_without_crashing(): void
    {
        $this->writeModuleManifest('InvalidModule', '{ invalid json');

        $scan = app(AddonDiscovery::class)->scan();

        $this->assertNotEmpty($scan['invalid']);
        $this->assertSame([], $scan['duplicates']);

        $this->artisan('addons:doctor')
            ->expectsOutputToContain('addon_invalid_manifest')
            ->assertExitCode(1);
    }

    public function test_duplicate_addon_code_is_reported_as_diagnostic(): void
    {
        $this->writeModuleManifest('DuplicateA', $this->manifestJson([
            'code' => 'demo.duplicate',
            'name' => 'Duplicate A',
        ]));
        $this->writeModuleManifest('DuplicateB', $this->manifestJson([
            'code' => 'demo.duplicate',
            'name' => 'Duplicate B',
        ]));

        $diagnostics = app(AddonHealthCheck::class)->diagnostics();

        $this->assertContains('addon_duplicate_code', collect($diagnostics['issues'])->pluck('code')->all());
    }

    public function test_local_addon_lifecycle_installs_enables_disables_and_uninstalls(): void
    {
        app(AddonManager::class)->discover();

        $this->artisan('addons:install demo.hello-module')
            ->assertExitCode(0);
        $this->assertDatabaseHas('system_addons', [
            'code' => 'demo.hello-module',
            'status' => 'installed',
            'is_installed' => true,
            'is_enabled' => false,
        ]);

        $this->artisan('addons:enable demo.hello-module')
            ->assertExitCode(0);
        $this->assertDatabaseHas('system_addons', [
            'code' => 'demo.hello-module',
            'status' => 'enabled',
            'is_installed' => true,
            'is_enabled' => true,
        ]);

        $this->artisan('addons:disable demo.hello-module')
            ->assertExitCode(0);
        $this->assertDatabaseHas('system_addons', [
            'code' => 'demo.hello-module',
            'status' => 'disabled',
            'is_enabled' => false,
        ]);

        $this->artisan('addons:uninstall demo.hello-module')
            ->assertExitCode(0);
        $this->assertDatabaseHas('system_addons', [
            'code' => 'demo.hello-module',
            'status' => 'discovered',
            'is_installed' => false,
            'is_enabled' => false,
        ]);
    }

    public function test_disabled_addon_does_not_register_hooks_and_enabled_addon_does(): void
    {
        app(AddonManager::class)->discover();
        app(AddonManager::class)->install('demo.admin-widget');
        app(AddonHookRegistry::class)->flushAddon('demo.admin-widget');

        $this->assertSame([], app(AddonHookRegistry::class)->get('admin.dashboard.widgets'));

        app(AddonManager::class)->enable('demo.admin-widget');

        $this->assertCount(1, app(AddonHookRegistry::class)->get('admin.dashboard.widgets'));
        $this->assertCount(1, app(AddonHookRegistry::class)->get('admin.navigation.items'));
    }

    public function test_disabling_addon_flushes_registered_hooks(): void
    {
        app(AddonManager::class)->discover();
        app(AddonManager::class)->install('demo.admin-widget');
        app(AddonManager::class)->enable('demo.admin-widget');

        $this->assertNotEmpty(app(AddonHookRegistry::class)->get('admin.dashboard.widgets'));

        app(AddonManager::class)->disable('demo.admin-widget');

        $this->assertSame([], app(AddonHookRegistry::class)->get('admin.dashboard.widgets'));
        $this->assertSame([], app(AddonHookRegistry::class)->get('admin.navigation.items'));
    }

    public function test_untrusted_manifest_hook_handler_is_skipped(): void
    {
        $this->writeExtensionManifest('UnsafeHook', $this->extensionManifestJson([
            'code' => 'tests.unsafe-hook',
            'name' => 'Unsafe Hook',
            'hooks' => [[
                'name' => 'admin.dashboard.widgets',
                'handler' => UnsafeExternalHookHandler::class,
                'priority' => 99,
            ]],
        ]));

        app(AddonManager::class)->discover();
        app(AddonManager::class)->install('tests.unsafe-hook');
        app(AddonManager::class)->enable('tests.unsafe-hook');

        $this->assertSame([], app(AddonHookRegistry::class)->get('admin.dashboard.widgets'));
        $this->assertSame([], app(AddonHookRegistry::class)->run('admin.dashboard.widgets', 'payload'));
    }

    public function test_missing_service_provider_fails_addon_with_last_error_without_application_crash(): void
    {
        $this->writeExtensionManifest('MissingProvider', $this->extensionManifestJson([
            'code' => 'tests.missing-provider',
            'name' => 'Missing Provider',
            'service_provider' => 'Extensions\\TestSuite\\MissingProvider\\MissingProvider',
        ]));

        app(AddonManager::class)->discover();
        app(AddonManager::class)->install('tests.missing-provider');
        app(AddonManager::class)->enable('tests.missing-provider');

        $this->assertDatabaseHas('system_addons', [
            'code' => 'tests.missing-provider',
            'status' => 'failed',
            'is_enabled' => false,
        ]);

        $this->assertStringContainsString(
            'Service provider class not found',
            (string) app(AddonRegistry::class)->find('tests.missing-provider')?->last_error,
        );

        $diagnostics = app(AddonHealthCheck::class)->diagnostics();

        $this->assertContains('addon_failed_status', collect($diagnostics['issues'])->pluck('code')->all());
        $this->assertContains('addon_service_provider_missing', collect($diagnostics['issues'])->pluck('code')->all());
    }

    public function test_provider_exception_updates_last_error_and_does_not_break_application_boot(): void
    {
        $path = $this->testModulesPath.'/CrashingProvider';
        File::ensureDirectoryExists($path.'/src');
        File::put($path.'/src/CrashingProvider.php', <<<'PHP'
<?php

namespace Modules\TestSuite\CrashingProvider;

use Illuminate\Support\ServiceProvider;

class CrashingProvider extends ServiceProvider
{
    public function register(): void
    {
        throw new \RuntimeException('Provider exploded during register');
    }
}
PHP);
        File::put($path.'/module.json', $this->manifestJson([
            'code' => 'tests.crashing-provider',
            'name' => 'Crashing Provider',
            'service_provider' => 'Modules\\TestSuite\\CrashingProvider\\CrashingProvider',
            'routes' => [
                'web' => 'routes/web.php',
            ],
        ]));
        File::ensureDirectoryExists($path.'/routes');
        File::put($path.'/routes/web.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/addons/test-suite/crashing-provider', fn (): string => 'ok');
PHP);

        app(AddonManager::class)->discover();
        app(AddonManager::class)->install('tests.crashing-provider');
        app(AddonManager::class)->enable('tests.crashing-provider');

        $addon = app(AddonRegistry::class)->find('tests.crashing-provider');

        $this->assertNotNull($addon);
        $this->assertSame('failed', $addon->status);
        $this->assertFalse($addon->is_enabled);
        $this->assertStringContainsString('Addon boot failed', (string) $addon->last_error);
        $this->assertStringNotContainsString(base_path(), (string) $addon->last_error);

        $this->assertDatabaseMissing('system_addon_events', [
            'addon_code' => 'tests.crashing-provider',
            'event' => 'service_provider_registered',
        ]);

        $this->get('/addons/test-suite/crashing-provider')->assertNotFound();
    }

    public function test_enabled_addon_with_missing_manifest_is_marked_failed_with_last_error(): void
    {
        app(AddonManager::class)->discover();
        app(AddonManager::class)->install('demo.hello-module');
        app(AddonManager::class)->enable('demo.hello-module');

        $manifestPath = base_path('modules/Demo/HelloModule/module.json');
        $backupPath = $manifestPath.'.bak';
        rename($manifestPath, $backupPath);

        try {
            app(AddonManager::class)->bootEnabledAddons();

            $addon = app(AddonRegistry::class)->find('demo.hello-module');

            $this->assertNotNull($addon);
            $this->assertSame('failed', $addon->status);
            $this->assertFalse($addon->is_enabled);
            $this->assertSame('Manifest not found', $addon->last_error);

            $diagnostics = app(AddonHealthCheck::class)->diagnostics();
            $this->assertContains('addon_failed_status', collect($diagnostics['issues'])->pluck('code')->all());
        } finally {
            rename($backupPath, $manifestPath);
        }
    }

    public function test_failed_addon_can_be_disabled_cleanly(): void
    {
        $this->writeExtensionManifest('MissingProviderToDisable', $this->extensionManifestJson([
            'code' => 'tests.missing-provider-disable',
            'name' => 'Missing Provider Disable',
            'service_provider' => 'Extensions\\TestSuite\\MissingProviderToDisable\\MissingProvider',
        ]));

        app(AddonManager::class)->discover();
        app(AddonManager::class)->install('tests.missing-provider-disable');
        app(AddonManager::class)->enable('tests.missing-provider-disable');

        $this->artisan('addons:disable tests.missing-provider-disable')
            ->assertExitCode(0);

        $this->assertDatabaseHas('system_addons', [
            'code' => 'tests.missing-provider-disable',
            'status' => 'disabled',
            'is_enabled' => false,
            'last_error' => null,
        ]);
    }

    public function test_addon_settings_and_enabled_permissions_are_available(): void
    {
        app(AddonManager::class)->discover();
        app(AddonManager::class)->install('demo.hello-module');
        app(AddonManager::class)->enable('demo.hello-module');

        app(AddonRegistry::class)->setSetting('demo.hello-module', 'greeting', ['value' => 'Hello']);

        $this->assertSame(['greeting' => ['value' => 'Hello']], app(AddonRegistry::class)->settings('demo.hello-module'));
        $this->assertContains('demo.hello-module.view', collect(app(AddonRegistry::class)->permissions())->pluck('code')->all());
    }

    public function test_addon_admin_page_is_accessible_for_admin(): void
    {
        app(AddonManager::class)->discover();

        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin)
            ->get(SystemAddonResource::getUrl())
            ->assertOk()
            ->assertSee('Discover / rescan')
            ->assertSee('demo.hello-module');
    }

    public function test_enabled_addon_admin_routes_require_authentication(): void
    {
        $path = $this->testModulesPath.'/GuardedAdminRoute';
        File::ensureDirectoryExists($path.'/routes');
        File::put($path.'/routes/admin.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/addons/test-suite/guarded-admin-route', fn (): string => 'guarded');
PHP);
        File::put($path.'/module.json', $this->manifestJson([
            'code' => 'tests.guarded-admin-route',
            'name' => 'Guarded Admin Route',
            'routes' => [
                'admin' => 'routes/admin.php',
            ],
        ]));

        app(AddonManager::class)->discover();
        app(AddonManager::class)->install('tests.guarded-admin-route');
        app(AddonManager::class)->enable('tests.guarded-admin-route');

        $this->get('/admin/addons/test-suite/guarded-admin-route')
            ->assertRedirect('/admin/login');
    }

    public function test_cli_commands_report_basic_addon_state(): void
    {
        $this->artisan('addons:discover')
            ->expectsOutputToContain('Addon discovery complete.')
            ->assertExitCode(0);

        $this->artisan('addons:list')
            ->expectsOutputToContain('demo.hello-module')
            ->assertExitCode(0);

        $this->artisan('addons:doctor')
            ->expectsOutputToContain('Addon doctor')
            ->assertExitCode(0);

        $this->artisan('addons:install unknown.addon')
            ->expectsOutputToContain('Addon [unknown.addon] was not found')
            ->assertExitCode(1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function manifestJson(array $overrides = []): string
    {
        return json_encode(array_merge([
            'code' => 'tests.sample-module',
            'type' => 'module',
            'name' => 'Tests Sample Module',
            'description' => 'Test module.',
            'version' => '0.1.0',
            'vendor' => 'Tests',
            'author' => 'Tests',
            'enabled_by_default' => false,
            'service_provider' => null,
            'dependencies' => [],
            'permissions' => [],
            'menu' => [],
            'settings_schema' => [],
            'migrations' => [],
            'seeders' => [],
            'routes' => [],
            'compatibility' => [
                'app_min_version' => null,
                'app_max_version' => null,
                'laravel_version' => '>=12.0',
                'php_version' => '>=8.3',
            ],
        ], $overrides), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function extensionManifestJson(array $overrides = []): string
    {
        return json_encode(array_merge([
            'code' => 'tests.sample-extension',
            'type' => 'extension',
            'name' => 'Tests Sample Extension',
            'description' => 'Test extension.',
            'version' => '0.1.0',
            'vendor' => 'Tests',
            'author' => 'Tests',
            'enabled_by_default' => false,
            'service_provider' => null,
            'dependencies' => [],
            'hooks' => [],
            'settings_schema' => [],
            'compatibility' => [
                'app_min_version' => null,
                'app_max_version' => null,
                'laravel_version' => '>=12.0',
                'php_version' => '>=8.3',
            ],
        ], $overrides), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function writeModuleManifest(string $name, string $contents): void
    {
        $path = $this->testModulesPath.'/'.$name;
        File::ensureDirectoryExists($path);
        File::put($path.'/module.json', $contents);
    }

    private function writeExtensionManifest(string $name, string $contents): void
    {
        $path = $this->testExtensionsPath.'/'.$name;
        File::ensureDirectoryExists($path);
        File::put($path.'/extension.json', $contents);
    }
}

class UnsafeExternalHookHandler
{
    public function __invoke(mixed $payload): string
    {
        return 'unsafe:'.(string) $payload;
    }
}
