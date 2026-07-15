<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Marketplace;
use App\Support\Addons\Registry\AddonRecoveryHealthService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

final class MarketplaceRecoveryOperationsTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('addons');
        Cache::flush();
        config([
            'addons-registry.enabled' => false,
            'addons-registry.recovery_health.enabled' => true,
            'addons-registry.recovery_health.cache_ttl' => 60,
            'addons-registry.recovery_health.stale_after' => 30,
            'addons-registry.recovery_health.max_operations' => 100,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_operations_section_renders_healthy_without_sensitive_paths(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        Livewire::test(Marketplace::class)
            ->call('setMarketplaceTab', 'operations')
            ->assertSee(__('marketplace.operations.heading'))
            ->assertSee(__('marketplace.operations.status'))
            ->assertSee('healthy')
            ->assertDontSee(Storage::disk('addons')->path(''));
    }

    public function test_operations_surface_renders_english_and_ukrainian_translations(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));
        app()->setLocale('en');
        Livewire::test(Marketplace::class)
            ->call('setMarketplaceTab', 'operations')
            ->assertSee('Operations / Recovery')
            ->assertSee('Backup retention');

        app()->setLocale('uk');
        Livewire::test(Marketplace::class)
            ->call('setMarketplaceTab', 'operations')
            ->assertSee('Операції та відновлення')
            ->assertSee('Зберігання backups');
    }

    public function test_recovery_dry_run_is_read_only_and_safe_action_recovers_after_reinspection(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));
        $id = '11111111-1111-4111-8111-111111111111';
        $path = 'addons/install-journal/alta.ui/'.$id.'.json';
        Storage::disk('addons')->put($path, json_encode([
            'schema_version' => 1, 'operation_id' => $id, 'code' => 'alta.ui', 'operation_type' => 'install',
            'state' => 'prepared', 'previous_version' => null, 'target_version' => '2.0.0', 'started_at' => now()->subMinutes(5)->toIso8601String(),
        ]));
        $before = Storage::disk('addons')->get($path);

        Livewire::test(Marketplace::class)
            ->call('setMarketplaceTab', 'operations')
            ->assertSee('prepared_no_mutation')
            ->call('recoveryDryRun', $id)
            ->assertHasNoErrors();
        $this->assertSame($before, Storage::disk('addons')->get($path));

        Livewire::test(Marketplace::class)->call('runSafeRecovery', $id)->assertHasNoErrors();
        $this->assertSame($before, Storage::disk('addons')->get($path));
        $this->assertCount(1, Storage::disk('addons')->allFiles('addons/recovery-journal'));
    }

    public function test_non_admin_direct_mutation_action_is_forbidden(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Manager));

        Livewire::test(Marketplace::class)
            ->call('runSafeRecovery', '11111111-1111-4111-8111-111111111111')
            ->assertForbidden();
    }

    public function test_admin_can_mark_manual_intervention_with_reason_and_evidence_is_preserved(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));
        $id = '33333333-3333-4333-8333-333333333333';
        $path = 'addons/install-journal/alta.manual/'.$id.'.json';
        Storage::disk('addons')->put($path, json_encode([
            'schema_version' => 1, 'operation_id' => $id, 'code' => 'alta.manual', 'operation_type' => 'update',
            'state' => 'promoting', 'previous_version' => '1.0.0', 'target_version' => '2.0.0', 'started_at' => now()->subMinutes(5)->toIso8601String(),
        ]));
        $before = Storage::disk('addons')->get($path);

        Livewire::test(Marketplace::class)
            ->set('manualInterventionReason', 'Ambiguous live evidence requires operator review.')
            ->call('markManualIntervention', $id)
            ->assertHasNoErrors();

        $this->assertSame($before, Storage::disk('addons')->get($path));
        $this->assertCount(1, Storage::disk('addons')->allFiles('addons/recovery-journal/alta.manual'));
        $this->assertSame('manual_intervention_required', app(AddonRecoveryHealthService::class)->health(true)['status']);
    }
}
