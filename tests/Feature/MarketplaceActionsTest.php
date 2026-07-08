<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Marketplace;
use App\Models\SystemAddon;
use App\Support\Addons\Marketplace\MarketplaceManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class MarketplaceActionsTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    private function marketplace(): Testable
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::test(Marketplace::class);
    }

    public function test_admin_can_open_marketplace_page(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin))
            ->get('/admin/marketplace')
            ->assertOk()
            ->assertSee('Marketplace модулів')
            ->assertSee('core.theme-maker');
    }

    public function test_rendered_html_has_install_addon_wire_click(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->actingAs($admin);

        $this->marketplace()->call('rescan');

        $this->get('/admin/marketplace')
            ->assertOk()
            // Raw HTML must contain a valid, non-broken Livewire expression.
            ->assertSee('wire:click="installAddon(\'core.theme-maker\')"', false)
            ->assertSee('wire:click="toggleDetails(\'core.theme-maker\')"', false)
            // There must be NO leftover @js directive literals.
            ->assertDontSee('@js(');
    }

    public function test_rendered_html_has_no_broken_wire_click_attribute(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->actingAs($admin);

        $this->marketplace()->call('rescan');

        $html = $this->get('/admin/marketplace')->getContent();

        // The button attribute must not be split by an unescaped double quote.
        $this->assertStringNotContainsString('wire:click="installAddon("', $html);
        $this->assertStringNotContainsString('@js($item->code)', $html);
    }

    public function test_rescan_discovers_core_theme_maker(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('rescan')
            ->assertOk();

        $this->assertDatabaseHas('system_addons', [
            'code' => 'core.theme-maker',
            'status' => 'discovered',
        ]);
    }

    public function test_install_addon_action_transitions_to_installed(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('rescan')
            ->call('installAddon', 'core.theme-maker')
            ->assertOk()
            ->assertHasNoErrors()
            // The re-rendered UI must reflect the new status badge.
            ->assertSee('Встановлено');

        $this->assertDatabaseHas('system_addons', [
            'code' => 'core.theme-maker',
            'status' => 'installed',
        ]);

        $resolved = app(MarketplaceManager::class)->resolve();
        $row = collect($resolved['rows'])->first(fn ($r) => $r['item']->code === 'core.theme-maker');
        $this->assertSame('installed', $row['status']);
    }

    public function test_enable_addon_action_transitions_to_enabled(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('rescan')
            ->call('installAddon', 'core.theme-maker')
            ->call('enableAddon', 'core.theme-maker')
            ->assertOk()
            ->assertHasNoErrors();

        $this->assertDatabaseHas('system_addons', [
            'code' => 'core.theme-maker',
            'status' => 'enabled',
        ]);
    }

    public function test_disable_addon_action_transitions_to_disabled(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('rescan')
            ->call('installAddon', 'core.theme-maker')
            ->call('enableAddon', 'core.theme-maker')
            ->call('disableAddon', 'core.theme-maker')
            ->assertOk()
            ->assertHasNoErrors();

        $this->assertDatabaseHas('system_addons', [
            'code' => 'core.theme-maker',
            'status' => 'disabled',
        ]);
    }

    public function test_uninstall_addon_action_soft_removes_back_to_discovered(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('rescan')
            ->call('installAddon', 'core.theme-maker')
            ->call('enableAddon', 'core.theme-maker')
            ->call('disableAddon', 'core.theme-maker')
            ->call('uninstallAddon', 'core.theme-maker')
            ->assertOk()
            ->assertHasNoErrors();

        $this->assertDatabaseHas('system_addons', [
            'code' => 'core.theme-maker',
            'status' => 'discovered',
        ]);

        $addon = SystemAddon::where('code', 'core.theme-maker')->firstOrFail();
        $this->assertFalse($addon->is_installed);
        $this->assertFalse($addon->is_enabled);
    }

    public function test_toggle_details_expands_and_collapses_item(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('toggleDetails', 'core.theme-maker')
            ->assertSet('expandedCode', 'core.theme-maker')
            ->assertSee('Manifest:')
            ->call('toggleDetails', 'core.theme-maker')
            ->assertSet('expandedCode', null);
    }

    public function test_reset_filters_clears_all_filters(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->set('filterType', 'extension')
            ->set('filterStatus', 'discovered')
            ->set('filterCategory', 'Дизайн')
            ->set('filterVendor', 'Core')
            ->set('filterFeatured', '1')
            ->call('resetFilters')
            ->assertSet('filterType', null)
            ->assertSet('filterStatus', null)
            ->assertSet('filterCategory', null)
            ->assertSet('filterVendor', null)
            ->assertSet('filterFeatured', null);
    }

    public function test_failed_action_does_not_crash_page(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        // core.promotions depends on core.products which is not installed/enabled,
        // so enableAddon() must throw a RuntimeException handled by the page (no exception page).
        $this->marketplace()
            ->call('rescan')
            ->call('enableAddon', 'core.promotions')
            ->assertOk();

        // The blocked addon must NOT be enabled; its status stays untouched.
        $resolved = app(MarketplaceManager::class)->resolve();
        $row = collect($resolved['rows'])->first(fn ($r) => $r['item']->code === 'core.promotions');

        $this->assertNotNull($row);
        $this->assertNotSame('enabled', $row['status']);
    }
}
