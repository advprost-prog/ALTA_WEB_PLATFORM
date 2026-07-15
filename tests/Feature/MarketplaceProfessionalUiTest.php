<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Marketplace;
use App\Models\SystemAddon;
use App\Support\Addons\Marketplace\MarketplaceCatalog;
use App\Support\Addons\Marketplace\MarketplaceManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

final class MarketplaceProfessionalUiTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['addons-marketplace.show_development' => false, 'addons-registry.enabled' => true]);
        app()->forgetInstance(MarketplaceCatalog::class);
        app()->forgetInstance(MarketplaceManager::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->createUserWithRole(UserRole::Admin));
    }

    public function test_empty_remote_catalog_has_professional_state_without_local_fixture_leakage(): void
    {
        config(['addons-registry.enabled' => false]);
        $page = Livewire::test(Marketplace::class)
            ->assertSet('activeTab', 'marketplace')
            ->assertSee('У Marketplace поки немає опублікованих модулів')
            ->assertSee('ще не містить опублікованих релізів')
            ->assertDontSee('core.theme-maker')
            ->assertDontSee('core.promotions')
            ->assertDontSee('Demo extension')
            ->assertDontSee('not_applicable')
            ->assertDontSee('deps: satisfied')
            ->assertDontSee('Discover / rescan');

        $this->assertSame(0, substr_count($page->html(), 'fi-badge'));
    }

    public function test_installed_tab_shows_only_real_installed_record_with_ukrainian_actions_and_details(): void
    {
        SystemAddon::query()->create([
            'code' => 'vendor.production', 'type' => 'module', 'name' => 'Production Module', 'vendor' => 'Vendor',
            'version' => '1.2.0', 'source' => 'local', 'status' => 'disabled', 'is_installed' => true, 'is_enabled' => false,
            'manifest_path' => 'modules/Vendor/Production/module.json', 'metadata' => ['manifest' => ['dependencies' => []]],
        ]);
        app()->forgetInstance(MarketplaceManager::class);

        Livewire::test(Marketplace::class)
            ->call('setMarketplaceTab', 'installed')
            ->assertSee('Production Module')
            ->assertSee('Увімкнути')
            ->assertSee('Деталі')
            ->assertDontSee('modules/Vendor/Production/module.json')
            ->assertDontSee('core.theme-maker')
            ->call('toggleDetails', 'vendor.production')
            ->assertSee('Технічні деталі')
            ->assertSee('module.json');
    }

    public function test_development_tab_is_not_available_when_policy_is_disabled(): void
    {
        Livewire::test(Marketplace::class)
            ->assertDontSee('Для розробки')
            ->call('setMarketplaceTab', 'development')
            ->assertNotFound();
    }
}
