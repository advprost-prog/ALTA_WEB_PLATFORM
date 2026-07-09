<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Support\Addons\Marketplace\MarketplaceCatalog;
use App\Support\Addons\Marketplace\MarketplaceItem;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Marketplace\MarketplaceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class AddonMarketplaceTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_catalog_config_has_five_demo_items(): void
    {
        $items = config('addons-marketplace.items', []);

        $this->assertCount(5, $items);
        $codes = collect($items)->pluck('code')->all();
        $this->assertEqualsCanonicalizing(
            ['core.products', 'core.promotions', 'core.integrations', 'core.theme-maker', 'core.seo'],
            $codes,
        );
    }

    public function test_catalog_parses_valid_items_without_diagnostics(): void
    {
        $catalog = app(MarketplaceCatalog::class)->load();

        $this->assertCount(5, $catalog['items']);
        $this->assertSame([], $catalog['diagnostics']);

        foreach ($catalog['items'] as $item) {
            $this->assertInstanceOf(MarketplaceItem::class, $item);
            $this->assertTrue($item->isValid());
        }
    }

    public function test_missing_physical_files_resolve_to_missing_files_status(): void
    {
        $resolved = app(MarketplaceManager::class)->resolve();

        $this->assertCount(5, $resolved['rows']);

        foreach ($resolved['rows'] as $row) {
            if ($row['item']->code === 'core.theme-maker' || $row['item']->code === 'core.products') {
                $this->assertNotSame(MarketplaceStatus::MISSING_FILES, $row['status']);

                continue;
            }

            $this->assertSame(MarketplaceStatus::MISSING_FILES, $row['status']);
            $this->assertContains('discover', $row['actions']);
        }
    }

    public function test_invalid_catalog_item_is_flagged_without_throwing(): void
    {
        config(['addons-marketplace.items' => [
            [
                'code' => 'broken.item',
                'type' => 'module',
                'name' => 'Broken',
                'version' => '1.0.0',
                // vendor intentionally missing
            ],
        ]]);

        $resolved = app(MarketplaceManager::class)->resolve();

        $this->assertCount(1, $resolved['rows']);
        $row = $resolved['rows'][0];
        $this->assertSame(MarketplaceStatus::INVALID, $row['status']);
        $this->assertStringContainsString('Invalid marketplace item', $resolved['diagnostics'][0] ?? '');
        $this->assertSame([], $row['actions']);
    }

    public function test_empty_catalog_resolves_without_crashing(): void
    {
        config(['addons-marketplace.items' => []]);

        $resolved = app(MarketplaceManager::class)->resolve();

        $this->assertSame([], $resolved['rows']);
    }

    public function test_marketplace_lifecycle_install_enable_disable_uninstall(): void
    {
        $manager = app(MarketplaceManager::class);
        $manager->discover();

        $addon = $manager->install('demo.hello-module');
        $this->assertSame('installed', $addon->status);
        $this->assertTrue($addon->is_installed);

        $addon = $manager->enable('demo.hello-module');
        $this->assertSame('enabled', $addon->status);
        $this->assertTrue($addon->is_enabled);

        $addon = $manager->disable('demo.hello-module');
        $this->assertSame('disabled', $addon->status);
        $this->assertFalse($addon->is_enabled);

        $addon = $manager->uninstall('demo.hello-module');
        $this->assertSame('discovered', $addon->status);
        $this->assertFalse($addon->is_installed);
    }

    public function test_enable_is_blocked_when_dependency_is_missing(): void
    {
        $manager = app(MarketplaceManager::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/залежн|dependency/i');

        $manager->enable('core.promotions');
    }

    public function test_marketplace_cli_command_lists_items(): void
    {
        $this->artisan('addons:marketplace')
            ->assertSuccessful()
            ->expectsOutputToContain('core.products')
            ->expectsOutputToContain('missing_files');
    }

    public function test_admin_can_view_marketplace_page(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin))
            ->get('/admin/marketplace')
            ->assertOk()
            ->assertSee('Marketplace модулів')
            ->assertSee('core.products')
            ->assertSee('missing_files')
            ->assertSee('Всього позицій')
            ->assertSee('Фільтри')
            ->assertSee('Discover / rescan');
    }

    public function test_marketplace_page_does_not_crash_on_invalid_item(): void
    {
        config(['addons-marketplace.items' => [
            ['code' => 'broken.item', 'type' => 'module', 'name' => 'Broken', 'version' => '1.0.0'],
        ]]);

        $this->actingAs($this->createUserWithRole(UserRole::Admin))
            ->get('/admin/marketplace')
            ->assertOk()
            ->assertSee('Некоректний')
            ->assertSee('Фільтри');
    }
}
