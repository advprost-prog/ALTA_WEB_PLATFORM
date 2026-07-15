<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Marketplace;
use App\Models\SystemAddon;
use App\Support\Addons\Marketplace\MarketplaceCatalog;
use App\Support\Addons\Marketplace\MarketplaceManager;
use App\Support\Addons\Registry\RegistryCatalog;
use App\Support\Addons\Registry\RegistryClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        $this->fakeConnectedRegistry([]);
        $page = Livewire::test(Marketplace::class)
            ->assertSet('activeTab', 'marketplace')
            ->assertSee('У Marketplace поки немає опублікованих модулів')
            ->assertSee('ще не містить доступних релізів')
            ->assertDontSee('core.theme-maker')
            ->assertDontSee('core.promotions')
            ->assertDontSee('Demo extension')
            ->assertDontSee('not_applicable')
            ->assertDontSee('deps: satisfied')
            ->assertDontSee('Discover / rescan');

        $this->assertSame(0, substr_count($page->html(), 'fi-badge'));
        $this->assertSame(1, substr_count($page->html(), 'У Marketplace поки немає опублікованих модулів'));
    }

    public function test_unavailable_registry_never_renders_connected_empty_copy_or_raw_state(): void
    {
        config(['addons-registry' => array_replace(config('addons-registry'), ['enabled' => true, 'url' => 'https://registry.example.test/catalog', 'allowed_hosts' => ['registry.example.test']])]);
        Cache::flush();
        Http::fake(['*' => Http::response([], 503)]);
        $this->forgetRegistryServices();

        Livewire::test(Marketplace::class)
            ->assertSee('Не вдалося підключитися до Marketplace. Каталог недоступний.')
            ->assertDontSee('У Marketplace поки немає опублікованих модулів')
            ->assertDontSee('unavailable');
    }

    public function test_html_challenge_has_safe_operator_diagnostic_without_raw_body(): void
    {
        config(['addons-registry' => array_replace(config('addons-registry'), ['enabled' => true, 'url' => 'https://registry.example.test/catalog', 'allowed_hosts' => ['registry.example.test']])]);
        Cache::flush();
        Http::fake(['*' => Http::response('<!DOCTYPE html><title>Browser verification secret-token</title>', 200, ['Content-Type' => 'text/html'])]);
        $this->forgetRegistryServices();

        Livewire::test(Marketplace::class)
            ->assertSee('Сервер Marketplace повернув непідтримувану відповідь')
            ->assertSee('замість каталогу повернув HTML')
            ->assertDontSee('html_challenge_response')
            ->assertDontSee('secret-token')
            ->call('toggleRegistryDetails')
            ->assertSee('Код діагностики: html_challenge_response')
            ->assertDontSee('secret-token');
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
            ->assertSee('—')
            ->assertDontSee('modules/Vendor/Production/module.json')
            ->assertDontSee('core.theme-maker')
            ->call('toggleDetails', 'vendor.production')
            ->assertSee('Технічні деталі')
            ->assertSee('дані Marketplace відсутні')
            ->assertSee('module.json');
    }

    public function test_operations_use_operator_copy_and_hide_unmanaged_machine_reason(): void
    {
        config(['addons-registry.enabled' => false]);
        Livewire::test(Marketplace::class)
            ->set('recoveryRemnants', [[
                'identifier' => 'staging/unmanaged-fixture', 'kind' => 'staging', 'reason' => 'stale_item_unmanaged',
                'eligible' => false,
            ]])
            ->call('setMarketplaceTab', 'operations')
            ->assertSee('система працює нормально')
            ->assertSee('Виявлено непідтверджені службові дані')
            ->assertSee('залишений без змін із міркувань безпеки')
            ->assertDontSee('stale_item_unmanaged')
            ->assertDontSee('healthy')
            ->assertDontSee('Зберігання backups')
            ->assertDontSee('Застарілі recovery-дані');
    }

    public function test_development_tab_is_not_available_when_policy_is_disabled(): void
    {
        Livewire::test(Marketplace::class)
            ->assertDontSee('Для розробки')
            ->call('setMarketplaceTab', 'development')
            ->assertNotFound();
    }

    public function test_layout_has_bounded_table_scrollable_tabs_and_mobile_kpis(): void
    {
        $markup = file_get_contents(resource_path('views/filament/pages/marketplace.blade.php'));

        $this->assertStringContainsString('overflow-x:auto', $markup);
        $this->assertStringContainsString('.marketplace-kpis{display:grid;grid-template-columns:repeat(4', $markup);
        $this->assertStringContainsString('@media(max-width:900px)', $markup);
        $this->assertStringContainsString('.marketplace-kpis{grid-template-columns:repeat(2', $markup);
        $this->assertStringContainsString('.marketplace-table-wrap table{min-width:760px}', $markup);
    }

    private function fakeConnectedRegistry(array $items): void
    {
        config(['addons-registry' => array_replace(config('addons-registry'), ['enabled' => true, 'url' => 'https://registry.example.test/catalog', 'allowed_hosts' => ['registry.example.test']])]);
        Cache::flush();
        Http::fake(['*' => Http::response([
            'registry' => ['name' => 'test', 'version' => 'build', 'application_version' => '1.0.0', 'build_version' => 'build', 'schema_version' => '1', 'generated_at' => now()->toIso8601String()],
            'items' => $items,
        ], 200, ['Content-Type' => 'application/json'])]);
        $this->forgetRegistryServices();
    }

    private function forgetRegistryServices(): void
    {
        foreach ([RegistryClient::class, RegistryCatalog::class, MarketplaceManager::class] as $service) {
            app()->forgetInstance($service);
        }
    }
}
