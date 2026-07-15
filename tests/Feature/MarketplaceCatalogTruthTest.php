<?php

namespace Tests\Feature;

use App\Models\SystemAddon;
use App\Support\Addons\Marketplace\AddonCatalogAuditService;
use App\Support\Addons\Marketplace\MarketplaceCatalog;
use App\Support\Addons\Marketplace\MarketplaceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class MarketplaceCatalogTruthTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_local_entry_has_explicit_evidence_classification(): void
    {
        $rows = app(AddonCatalogAuditService::class)->audit();
        $this->assertSame(['core.integrations', 'core.products', 'core.promotions', 'core.seo', 'core.theme-maker'], array_column($rows, 'code'));
        $this->assertSame('test_fixture', collect($rows)->firstWhere('code', 'core.products')['classification']);
        $this->assertSame('test_fixture', collect($rows)->firstWhere('code', 'core.theme-maker')['classification']);
        foreach (['core.promotions', 'core.integrations', 'core.seo'] as $code) {
            $this->assertSame('placeholder_unimplemented', collect($rows)->firstWhere('code', $code)['classification']);
            $this->assertFalse(collect($rows)->firstWhere('code', $code)['manifest_exists']);
        }
    }

    public function test_production_policy_hides_development_fixtures_and_placeholders_server_side(): void
    {
        config(['addons-marketplace.show_development' => false, 'addons-registry.enabled' => false]);
        Cache::flush();
        app()->forgetInstance(MarketplaceCatalog::class);
        app()->forgetInstance(MarketplaceManager::class);

        $this->assertSame([], app(MarketplaceCatalog::class)->load()['items']);
        $resolved = app(MarketplaceManager::class)->resolve();
        $this->assertSame([], $resolved['rows']);
        $this->assertSame(0, $resolved['registry_item_count']);
    }

    public function test_testing_policy_keeps_explicit_lifecycle_fixtures_available(): void
    {
        config(['addons-marketplace.show_development' => true]);
        $items = app(MarketplaceCatalog::class)->load()['items'];
        $this->assertCount(5, $items);
        $this->assertContains('fixture', array_column($items, 'implementationState'));
        $this->assertContains('placeholder', array_column($items, 'implementationState'));
    }

    public function test_installed_production_addon_is_projected_separately_without_remote_fabrication(): void
    {
        config(['addons-marketplace.show_development' => false, 'addons-registry.enabled' => false]);
        SystemAddon::query()->create([
            'code' => 'vendor.production', 'type' => 'module', 'name' => 'Production Module', 'vendor' => 'Vendor',
            'version' => '1.2.0', 'source' => 'local', 'status' => 'disabled', 'is_installed' => true, 'is_enabled' => false,
            'manifest_path' => 'modules/Vendor/Production/module.json', 'metadata' => ['manifest' => ['dependencies' => []]],
        ]);
        app()->forgetInstance(MarketplaceCatalog::class);
        app()->forgetInstance(MarketplaceManager::class);

        $resolved = app(MarketplaceManager::class)->resolve();
        $this->assertSame(0, $resolved['registry_item_count']);
        $this->assertCount(1, $resolved['rows']);
        $this->assertSame('installed', $resolved['rows'][0]['source']);
        $this->assertSame('vendor.production', $resolved['rows'][0]['item']->code);
    }
}
