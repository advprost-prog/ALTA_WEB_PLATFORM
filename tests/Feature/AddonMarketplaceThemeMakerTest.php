<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Support\Addons\Marketplace\MarketplaceCatalog;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Marketplace\MarketplaceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class AddonMarketplaceThemeMakerTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    private function manager(): MarketplaceManager
    {
        return app(MarketplaceManager::class);
    }

    public function test_catalog_sees_core_theme_maker_with_valid_manifest(): void
    {
        $catalog = app(MarketplaceCatalog::class)->load();
        $item = collect($catalog['items'])->first(fn ($i) => $i->code === 'core.theme-maker');

        $this->assertNotNull($item);
        $this->assertTrue($item->isValid());
        $this->assertSame('extension', $item->type);
        $this->assertSame('Core', $item->vendor);
        $this->assertFileExists(base_path($item->path));
    }

    public function test_core_theme_maker_is_not_missing_files_when_manifest_exists(): void
    {
        $resolved = $this->manager()->resolve();
        $row = collect($resolved['rows'])->first(fn ($r) => $r['item']->code === 'core.theme-maker');

        $this->assertNotNull($row);
        $this->assertNotSame(MarketplaceStatus::MISSING_FILES, $row['status']);
    }

    public function test_discover_finds_core_theme_maker(): void
    {
        $this->manager()->discover();

        $this->assertDatabaseHas('system_addons', [
            'code' => 'core.theme-maker',
            'type' => 'extension',
            'status' => 'discovered',
        ]);
    }

    public function test_core_theme_maker_full_lifecycle(): void
    {
        $manager = $this->manager();
        $manager->discover();

        $addon = $manager->install('core.theme-maker');
        $this->assertSame('installed', $addon->status);

        $addon = $manager->enable('core.theme-maker');
        $this->assertSame('enabled', $addon->status);
        $this->assertTrue(app()->bound('core.theme-maker.booted'));

        $addon = $manager->disable('core.theme-maker');
        $this->assertSame('disabled', $addon->status);

        $addon = $manager->uninstall('core.theme-maker');
        $this->assertSame('discovered', $addon->status);
        $this->assertFalse($addon->is_installed);
    }

    public function test_provider_marker_absent_when_not_enabled(): void
    {
        $this->manager()->discover();
        $this->manager()->install('core.theme-maker');

        $this->assertFalse(app()->bound('core.theme-maker.booted'));
    }

    public function test_marketplace_page_renders_with_real_addon(): void
    {
        $this->manager()->discover();
        $this->manager()->install('core.theme-maker');
        $this->manager()->enable('core.theme-maker');

        $this->actingAs($this->createUserWithRole(UserRole::Admin))
            ->get('/admin/marketplace')
            ->assertOk()
            ->assertSee('core.theme-maker')
            ->assertSee('Розширення')
            ->assertSee('Увімкнено');
    }

    public function test_broken_items_still_do_not_crash_page(): void
    {
        config(['addons-marketplace.items' => [
            ['code' => 'broken.item', 'type' => 'module', 'name' => 'Broken', 'version' => '1.0.0'],
            [
                'code' => 'core.theme-maker',
                'type' => 'extension',
                'vendor' => 'Core',
                'name' => 'Theme Maker',
                'version' => '0.1.0',
                'description' => 'Demo.',
                'category' => 'Дизайн',
                'path' => 'extensions/Core/ThemeMaker/extension.json',
                'dependencies' => [],
                'tags' => ['theme', 'demo'],
                'is_featured' => true,
                'sort_order' => 40,
            ],
        ]]);

        $this->actingAs($this->createUserWithRole(UserRole::Admin))
            ->get('/admin/marketplace')
            ->assertOk()
            ->assertSee('Некоректний')
            ->assertDontSee('core.theme-maker');
    }
}
