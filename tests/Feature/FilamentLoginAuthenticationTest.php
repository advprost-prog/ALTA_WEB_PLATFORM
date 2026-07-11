<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Policies\AddonArtifactPromotionPolicy;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class FilamentLoginAuthenticationTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_admin_can_login_to_filament_with_valid_password(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'password')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($admin);

        $this->get('/admin')->assertOk();
    }

    public function test_login_rejects_invalid_password(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'not-the-password')
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        $this->assertGuest();
    }

    public function test_non_admin_role_can_login_but_access_is_limited_by_policies(): void
    {
        $contentManager = $this->createUserWithRole(UserRole::ContentManager);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->set('data.email', $contentManager->email)
            ->set('data.password', 'password')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($contentManager);

        $this->get('/admin/products')->assertOk();
        $this->get('/admin/orders')->assertForbidden();
    }

    public function test_phase_3_5b_promotion_gates_do_not_affect_login(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $manager = $this->createUserWithRole(UserRole::Manager);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertTrue(Gate::forUser($admin)->allows('promote-addon-artifacts'));
        $this->assertTrue(Gate::forUser($admin)->allows('rollback-addon-artifacts'));
        $this->assertFalse(Gate::forUser($manager)->allows('promote-addon-artifacts'));
        $this->assertFalse(Gate::forUser($manager)->allows('rollback-addon-artifacts'));

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'password')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($admin);
        $this->assertTrue(AddonArtifactPromotionPolicy::canPromote($admin));
    }
}
