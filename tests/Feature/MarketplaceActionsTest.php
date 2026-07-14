<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Marketplace;
use App\Models\SystemAddon;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Registry\RegistryCatalog;
use App\Support\Addons\Registry\RegistryClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

    public function test_registry_diagnostics_and_conditional_refresh_survive_full_livewire_reload(): void
    {
        Cache::flush();
        config(['addons-registry' => array_replace_recursive(config('addons-registry'), [
            'enabled' => true, 'url' => 'https://registry.example.test/api/v1/registry',
            'allowed_hosts' => ['registry.example.test'], 'cache_ttl' => 0,
        ])]);
        $document = ['registry' => ['name' => 'ALTA', 'version' => 'build-1', 'application_version' => '1.0.0', 'build_version' => 'build-1', 'schema_version' => '1', 'generated_at' => '1970-01-01T00:00:00+00:00'], 'items' => []];
        Http::fake(fn ($request) => $request->hasHeader('If-None-Match')
            ? Http::response('', 304, ['ETag' => '"one"'])
            : Http::response($document, 200, ['Content-Type' => 'application/json', 'ETag' => '"one"']));
        foreach ([RegistryClient::class, RegistryCatalog::class, MarketplaceManager::class] as $service) {
            app()->forgetInstance($service);
        }
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()->call('refreshRegistry')->assertHasNoErrors();
        $this->marketplace()->call('refreshRegistry')->assertHasNoErrors()->assertSee('Registry: fresh')->assertSee('build-1')->assertSee('1.0.0');

        $this->assertSame(304, app(RegistryCatalog::class)->snapshot()['last_http_status']);
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

    public function test_update_addon_action_updates_version(): void
    {
        $originalItems = config('addons-marketplace.items');
        $updatedItems = array_map(function ($item) {
            if ($item['code'] === 'core.theme-maker') {
                $item['version'] = '0.3.0';
            }

            return $item;
        }, $originalItems);
        config(['addons-marketplace.items' => $updatedItems]);

        try {
            $this->actingAs($this->createUserWithRole(UserRole::Admin));

            $this->marketplace()
                ->call('rescan')
                ->call('installAddon', 'core.theme-maker')
                ->call('updateAddon', 'core.theme-maker')
                ->assertOk()
                ->assertHasNoErrors()
                ->assertSee('0.3.0')
                ->assertSee('Актуальна');

            $this->assertDatabaseHas('system_addons', [
                'code' => 'core.theme-maker',
                'version' => '0.3.0',
                'status' => 'installed',
            ]);
        } finally {
            config(['addons-marketplace.items' => $originalItems]);
        }
    }

    public function test_update_preserves_enabled_status(): void
    {
        $originalItems = config('addons-marketplace.items');
        $updatedItems = array_map(function ($item) {
            if ($item['code'] === 'core.theme-maker') {
                $item['version'] = '0.3.0';
            }

            return $item;
        }, $originalItems);
        config(['addons-marketplace.items' => $updatedItems]);

        try {
            $this->actingAs($this->createUserWithRole(UserRole::Admin));

            $this->marketplace()
                ->call('rescan')
                ->call('installAddon', 'core.theme-maker')
                ->call('enableAddon', 'core.theme-maker')
                ->call('updateAddon', 'core.theme-maker')
                ->assertOk()
                ->assertHasNoErrors();

            $this->assertDatabaseHas('system_addons', [
                'code' => 'core.theme-maker',
                'version' => '0.3.0',
                'status' => 'enabled',
            ]);
        } finally {
            config(['addons-marketplace.items' => $originalItems]);
        }
    }

    public function test_update_preserves_disabled_status(): void
    {
        $originalItems = config('addons-marketplace.items');
        $updatedItems = array_map(function ($item) {
            if ($item['code'] === 'core.theme-maker') {
                $item['version'] = '0.3.0';
            }

            return $item;
        }, $originalItems);
        config(['addons-marketplace.items' => $updatedItems]);

        try {
            $this->actingAs($this->createUserWithRole(UserRole::Admin));

            $this->marketplace()
                ->call('rescan')
                ->call('installAddon', 'core.theme-maker')
                ->call('enableAddon', 'core.theme-maker')
                ->call('disableAddon', 'core.theme-maker')
                ->call('updateAddon', 'core.theme-maker')
                ->assertOk()
                ->assertHasNoErrors();

            $this->assertDatabaseHas('system_addons', [
                'code' => 'core.theme-maker',
                'version' => '0.3.0',
                'status' => 'disabled',
            ]);
        } finally {
            config(['addons-marketplace.items' => $originalItems]);
        }
    }

    public function test_update_blocked_when_not_installed(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('rescan')
            ->call('updateAddon', 'core.theme-maker')
            ->assertOk();

        $this->assertDatabaseHas('system_addons', [
            'code' => 'core.theme-maker',
            'version' => '0.2.0',
        ]);
    }

    public function test_update_blocked_when_no_update_available(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('rescan')
            ->call('installAddon', 'core.theme-maker')
            ->call('updateAddon', 'core.theme-maker')
            ->assertOk();

        $this->assertDatabaseHas('system_addons', [
            'code' => 'core.theme-maker',
            'version' => '0.2.0',
        ]);
    }

    public function test_rendered_html_has_update_addon_wire_click_when_update_available(): void
    {
        $originalItems = config('addons-marketplace.items');
        $updatedItems = array_map(function ($item) {
            if ($item['code'] === 'core.theme-maker') {
                $item['version'] = '0.3.0';
            }

            return $item;
        }, $originalItems);
        config(['addons-marketplace.items' => $updatedItems]);

        try {
            $admin = $this->createUserWithRole(UserRole::Admin);
            $this->actingAs($admin);

            $this->marketplace()
                ->call('rescan')
                ->call('installAddon', 'core.theme-maker');

            $this->get('/admin/marketplace')
                ->assertOk()
                ->assertSee('wire:click="updateAddon(\'core.theme-maker\')"', false)
                ->assertDontSee('wire:click="installAddon(\'core.theme-maker\')"', false);
        } finally {
            config(['addons-marketplace.items' => $originalItems]);
        }
    }

    public function test_marketplace_page_shows_installed_and_available_versions(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('rescan')
            ->call('installAddon', 'core.theme-maker');

        $this->get('/admin/marketplace')
            ->assertOk()
            ->assertSee('<dt>Встановлено</dt><dd>0.2.0</dd>', false)
            ->assertSee('<dt>Доступно</dt><dd>0.2.0</dd>', false);
    }

    public function test_marketplace_page_shows_different_available_version_when_update_available(): void
    {
        $originalItems = config('addons-marketplace.items');
        $updatedItems = array_map(function ($item) {
            if ($item['code'] === 'core.theme-maker') {
                $item['version'] = '0.3.0';
            }

            return $item;
        }, $originalItems);
        config(['addons-marketplace.items' => $updatedItems]);

        try {
            $this->actingAs($this->createUserWithRole(UserRole::Admin));

            $this->marketplace()
                ->call('rescan')
                ->call('installAddon', 'core.theme-maker');

            $this->get('/admin/marketplace')
                ->assertOk()
                ->assertSee('<dt>Встановлено</dt><dd>0.2.0</dd>', false)
                ->assertSee('<dt>Доступно</dt><dd>0.3.0</dd>', false)
                ->assertSee('Доступне оновлення', false);
        } finally {
            config(['addons-marketplace.items' => $originalItems]);
        }
    }

    public function test_marketplace_page_shows_dependency_block_for_core_promotions(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('rescan');

        $this->get('/admin/marketplace')
            ->assertOk()
            ->assertSee('Залежності:', false)
            ->assertSee('core.products', false)
            ->assertSee('Залежність [core.products] не встановлено.', false);
    }

    public function test_marketplace_page_shows_install_dependencies_button_when_installable(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('rescan');

        $this->get('/admin/marketplace')
            ->assertOk()
            ->assertSee('wire:click="installDependencies(\'core.promotions\')"', false);
    }

    public function test_install_dependencies_action_installs_core_products(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('rescan')
            ->call('installDependencies', 'core.promotions')
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee('Встановлено');

        $this->assertDatabaseHas('system_addons', [
            'code' => 'core.products',
            'status' => 'installed',
        ]);
    }

    public function test_install_dependencies_does_not_throw_fk_error_when_parent_missing(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('rescan')
            ->call('installDependencies', 'core.promotions')
            ->assertOk()
            ->assertHasNoErrors();

        $this->assertDatabaseHas('system_addons', [
            'code' => 'core.products',
            'status' => 'installed',
        ]);

        $this->assertDatabaseMissing('system_addons', [
            'code' => 'core.promotions',
        ]);
    }

    public function test_enable_blocked_when_dependency_unresolved(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $this->marketplace()
            ->call('rescan')
            ->call('enableAddon', 'core.promotions')
            ->assertOk();

        $resolved = app(MarketplaceManager::class)->resolve();
        $row = collect($resolved['rows'])->first(fn ($r) => $r['item']->code === 'core.promotions');

        $this->assertNotNull($row);
        $this->assertNotSame('enabled', $row['status']);
    }
}
