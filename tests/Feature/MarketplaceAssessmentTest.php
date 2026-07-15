<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\SystemAddon;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Registry\RegistryCatalog;
use App\Support\Addons\Registry\RegistryClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class MarketplaceAssessmentTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['platform.version' => '1.5.0', 'addons-registry' => array_replace_recursive(config('addons-registry'), [
            'enabled' => true, 'url' => 'https://registry.example.test/api/v1/registry', 'allowed_hosts' => ['registry.example.test'],
            'cache_ttl' => 3600, 'downloads' => ['enabled' => true, 'max_size' => 1000000],
        ])]);
    }

    public function test_local_remote_and_installed_versions_remain_separate_with_correct_version_states(): void
    {
        $this->installed('core.products', '1.0.0', true);
        $this->fakeRegistry([$this->item('core.products', '2.0.0')], build: '999.999.999-build');
        $assessment = $this->assessment('core.products');

        $this->assertSame('local_and_remote', $assessment['source']);
        $this->assertSame('1.0.0', $assessment['installedVersion']);
        $this->assertSame('1.0.0', $assessment['localCatalogVersion']);
        $this->assertSame('2.0.0', $assessment['remoteVersion']);
        $this->assertSame('update_available', $assessment['versionState']);
        $this->assertSame('compatible', $assessment['compatibility']['result']);
    }

    public function test_installed_version_never_becomes_available_version_without_remote_match(): void
    {
        $this->installed('core.products', '1.0.0', true);
        $this->fakeRegistry([]);

        $row = collect(app(MarketplaceManager::class)->resolve()['rows'])->first(fn (array $row): bool => $row['item']->code === 'core.products');
        $this->assertSame('1.0.0', $row['installed_version']);
        $this->assertSame('1.0.0', $row['local_catalog_version']);
        $this->assertNull($row['remote_version']);
        $this->assertNotSame('update_available', $row['update_status']);
    }

    public function test_local_only_items_cover_not_installed_enabled_and_disabled_without_registry_candidates(): void
    {
        $this->fakeRegistry([]);
        $initial = $this->assessment('core.theme-maker');
        $this->assertSame('local_only', $initial['source']);
        $this->assertSame('not_installed', $initial['runtimeState']);

        $this->installed('core.theme-maker', '0.2.0', true);
        $this->forgetServices();
        $this->assertSame('installed_enabled', $this->assessment('core.theme-maker')['runtimeState']);
        SystemAddon::where('code', 'core.theme-maker')->update(['status' => 'disabled', 'is_enabled' => false]);
        $this->forgetServices();
        $this->assertSame('installed_disabled', $this->assessment('core.theme-maker')['runtimeState']);
        $this->assertSame('not_applicable', $this->assessment('core.theme-maker')['registryState']);
    }

    #[DataProvider('versionCases')]
    public function test_equal_local_newer_malformed_and_leading_v_versions_are_deterministic(string $installed, string $remote, string $expected): void
    {
        $this->installed('core.products', $installed, false);
        $this->fakeRegistry([$this->item('core.products', $remote)]);
        $this->assertSame($expected, $this->assessment('core.products')['versionState']);
    }

    public static function versionCases(): array
    {
        return [['1.0.0', '1.0.0', 'up_to_date'], ['12.10.3', '2.0.0', 'local_newer'], ['legacy', '2.0.0', 'unknown'], ['v1.0.0', '1.1.0', 'update_available']];
    }

    public function test_remote_only_identity_conflict_and_candidate_specific_compatibility_fail_closed(): void
    {
        $this->fakeRegistry([
            $this->item('remote.only', '1.0.0'),
            $this->item('core.products', '2.0.0', vendor: 'Other'),
            $this->item('core.theme-maker', '1.0.0', type: 'module'),
            $this->item('remote.incompatible', '1.0.0', constraint: '^9.0'),
        ]);
        $remote = $this->assessment('remote.only');
        $conflict = $this->assessment('core.products');
        $incompatible = $this->assessment('remote.incompatible');
        $typeConflict = $this->assessment('core.theme-maker');

        $this->assertSame('remote_only', $remote['source']);
        $this->assertSame('not_installed', $remote['runtimeState']);
        $this->assertSame('source_conflict', $conflict['versionState']);
        $this->assertFalse($conflict['identity']['consistent']);
        $this->assertFalse($conflict['actions']['download']['allowed']);
        $this->assertSame('identity_conflict', $conflict['actions']['download']['reason_code']);
        $this->assertContains('type', $typeConflict['identity']['conflicting_fields']);
        $this->assertSame('incompatible', $incompatible['compatibility']['result']);
        $this->assertSame('platform_incompatible', $incompatible['actions']['download']['reason_code']);
        $this->assertDatabaseMissing('system_addons', ['code' => 'remote.only']);
        $this->actingAs($this->createUserWithRole(UserRole::Admin))->get('/admin/marketplace')->assertOk()
            ->assertSee('remote.only', false)
            ->assertDontSee(base64_encode('signature'), false)->assertDontSee('Live path:', false);
    }

    public function test_stale_remote_remains_visible_remote_actions_block_and_local_lifecycle_remains_available(): void
    {
        $this->installed('core.products', '1.0.0', true);
        Http::fakeSequence()->push($this->document([$this->item('core.products', '2.0.0')]), 200, ['Content-Type' => 'application/json'])->push([], 500);
        $this->forgetServices();
        app(RegistryCatalog::class)->refresh();
        app(RegistryCatalog::class)->refresh();

        $manager = app(MarketplaceManager::class);
        $assessment = $manager->assessment('core.products');
        $this->assertSame('offline', $assessment['registryState']);
        $this->assertSame('2.0.0', $assessment['remoteVersion']);
        $this->assertFalse($assessment['actions']['download']['allowed']);
        $this->assertSame('registry_not_fresh', $assessment['actions']['download']['reason_code']);
        $manager->disable('core.products');
        $this->assertDatabaseHas('system_addons', ['code' => 'core.products', 'is_enabled' => false, 'status' => 'disabled']);
    }

    public function test_local_dependency_candidate_remains_installable_when_registry_goes_offline(): void
    {
        $root = $this->item('remote.root', '1.0.0');
        $root['dependencies'] = [['code' => 'core.products', 'constraint' => '^1.0', 'required' => true]];
        Http::fakeSequence()->push($this->document([$root]), 200, ['Content-Type' => 'application/json'])->push([], 500);
        $this->forgetServices();
        app(RegistryCatalog::class)->refresh();
        app(RegistryCatalog::class)->refresh();

        $assessment = $this->assessment('remote.root');
        $this->assertSame('available_local', $assessment['dependencies']['nodes']['core.products']['state']);
        $this->assertSame('installable', $assessment['dependencies']['state']);
        $this->assertSame('offline', $assessment['registryState']);
    }

    private function assessment(string $code): array
    {
        return app(MarketplaceManager::class)->assessment($code) ?? [];
    }

    private function installed(string $code, string $version, bool $enabled): void
    {
        SystemAddon::create(['code' => $code, 'type' => 'module', 'name' => $code, 'vendor' => 'Core', 'version' => $version, 'source' => 'local', 'status' => $enabled ? 'enabled' : 'disabled', 'is_installed' => true, 'is_enabled' => $enabled]);
    }

    private function fakeRegistry(array $items, string $build = 'server-build'): void
    {
        Http::fake(['*' => Http::response($this->document($items, $build), 200, ['Content-Type' => 'application/json'])]);
        $this->forgetServices();
    }

    private function document(array $items, string $build = 'server-build'): array
    {
        return ['registry' => ['name' => 'ALTA', 'version' => $build, 'application_version' => '99.0.0', 'build_version' => $build, 'schema_version' => '1', 'generated_at' => '2026-07-14T00:00:00+00:00'], 'items' => $items];
    }

    private function item(string $code, string $version, string $vendor = 'Core', ?string $constraint = null, string $type = 'module'): array
    {
        return ['code' => $code, 'type' => $type, 'vendor' => $vendor, 'name' => $code, 'description' => '', 'version' => $version, 'category' => null, 'tags' => [], 'requires_platform' => $constraint, 'dependencies' => [], 'is_featured' => false, 'homepage_url' => null, 'documentation_url' => null, 'publisher' => ['public_id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Publisher'], 'published_at' => '2026-07-14T00:00:00+00:00', 'artifact' => ['url' => 'https://registry.example.test/api/v1/artifacts/11111111-1111-4111-8111-111111111111/download', 'type' => 'zip', 'sha256' => str_repeat('a', 64), 'size' => 100, 'signature' => ['type' => 'ed25519', 'value' => base64_encode('signature'), 'key_id' => 'key-1', 'payload_version' => 'raw-zip-v1']]];
    }

    private function forgetServices(): void
    {
        foreach ([RegistryClient::class, RegistryCatalog::class, MarketplaceManager::class] as $service) {
            app()->forgetInstance($service);
        }
    }
}
