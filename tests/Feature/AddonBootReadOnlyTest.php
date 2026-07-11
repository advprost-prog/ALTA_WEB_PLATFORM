<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Support\Addons\AddonManager;
use App\Support\Addons\Marketplace\MarketplaceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class AddonBootReadOnlyTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_enabled_addon_boot_is_read_only_for_lifecycle_events(): void
    {
        $manager = app(AddonManager::class);
        $manager->discover();
        $manager->install('core.products');
        $manager->enable('core.products');
        $manager->install('core.theme-maker');
        $manager->enable('core.theme-maker');
        DB::table('system_addon_events')->delete();

        $manager->bootEnabledAddons();
        $manager->bootEnabledAddons();

        $this->assertSame(0, DB::table('system_addon_events')->count());
        $this->assertTrue(app()->bound('core.products.booted'));
        $this->assertTrue(app()->bound('core.theme-maker.booted'));
    }

    public function test_admin_get_requests_do_not_create_addon_events(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $before = DB::table('system_addon_events')->count();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->assertSame($before, DB::table('system_addon_events')->count());

        $this->actingAs($admin)->get('/admin/marketplace')->assertOk();
        $this->actingAs($admin)->get('/admin/marketplace')->assertOk();

        $this->assertSame($before, DB::table('system_addon_events')->count());
    }

    public function test_marketplace_resolve_is_read_only(): void
    {
        $before = DB::table('system_addon_events')->count();

        app(MarketplaceManager::class)->resolve();
        app(MarketplaceManager::class)->resolve();

        $this->assertSame($before, DB::table('system_addon_events')->count());
    }

    public function test_explicit_lifecycle_transition_logs_once_and_idempotent_repeat_does_not(): void
    {
        $manager = app(AddonManager::class);
        $manager->discover();
        DB::table('system_addon_events')->delete();

        $manager->install('demo.hello-module');
        $manager->install('demo.hello-module');

        $this->assertSame(1, DB::table('system_addon_events')->where('event', 'installed')->count());
    }

    public function test_promotion_gates_do_not_affect_login_authorization(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $manager = $this->createUserWithRole(UserRole::Manager);

        $this->assertTrue(Gate::forUser($admin)->allows('promote-addon-artifacts'));
        $this->assertTrue(Gate::forUser($admin)->allows('rollback-addon-artifacts'));
        $this->assertFalse(Gate::forUser($manager)->allows('promote-addon-artifacts'));
        $this->assertFalse(Gate::forUser($manager)->allows('rollback-addon-artifacts'));

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->assertAuthenticatedAs($admin);
    }
}
