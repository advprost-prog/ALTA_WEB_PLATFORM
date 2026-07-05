<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Services\Admin\AdminUserProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Feature\Concerns\CreatesCommerceData;
use Tests\TestCase;

class AdminGovernanceTest extends TestCase
{
    use CreatesCommerceData;
    use RefreshDatabase;

    public function test_primary_admin_is_provisioned_by_seed_without_resetting_existing_password(): void
    {
        $existingPassword = 'existing-secret-password';

        User::create([
            'name' => 'Олександр',
            'email' => AdminUserProvisioner::PRIMARY_ADMIN_EMAIL,
            'password' => $existingPassword,
            'role' => UserRole::ContentManager,
            'email_verified_at' => null,
        ]);

        $this->seed();

        $user = User::where('email', AdminUserProvisioner::PRIMARY_ADMIN_EMAIL)->firstOrFail();

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue(Hash::check($existingPassword, $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_admin_governance_command_repairs_and_reports_primary_admin(): void
    {
        User::create([
            'name' => 'Олександр',
            'email' => AdminUserProvisioner::PRIMARY_ADMIN_EMAIL,
            'password' => 'existing-secret-password',
            'role' => UserRole::ContentManager,
        ]);

        $this->artisan('alta:admin-governance-check')
            ->expectsOutputToContain('primary_admin_existed_before: yes')
            ->expectsOutputToContain('primary_admin_old_role: content_manager')
            ->expectsOutputToContain('primary_admin_role: admin')
            ->expectsOutputToContain('primary_admin_is_admin: yes')
            ->expectsOutputToContain('primary_admin_can_access_panel: yes')
            ->expectsOutputToContain('ai_settings_page_class: yes')
            ->expectsOutputToContain('user_resource_class: yes')
            ->expectsOutputToContain('ai_suggestion_resource_class: yes')
            ->assertExitCode(0);
    }

    public function test_user_management_is_admin_only(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin))
            ->get('/admin/users')
            ->assertOk();

        auth()->logout();
        $this->flushSession();

        $this->actingAs($this->createUserWithRole(UserRole::Manager))
            ->get('/admin/users')
            ->assertForbidden();

        auth()->logout();
        $this->flushSession();

        $this->actingAs($this->createUserWithRole(UserRole::ContentManager))
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_admin_can_create_user_from_user_resource(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Manager',
                'email' => 'new-manager@example.test',
                'role' => UserRole::Manager->value,
                'password' => 'password',
                'passwordConfirmation' => 'password',
                'email_verified_at' => now(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'new-manager@example.test')->firstOrFail();

        $this->assertSame(UserRole::Manager, $user->role);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_admin_can_change_user_role_and_blank_password_does_not_change_hash(): void
    {
        $this->actingAs($this->createUserWithRole(UserRole::Admin));

        $user = User::create([
            'name' => 'Role Target',
            'email' => 'role-target@example.test',
            'password' => 'existing-password',
            'role' => UserRole::Manager,
        ]);
        $passwordHash = $user->password;

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm([
                'name' => 'Role Target Updated',
                'email' => 'role-target@example.test',
                'role' => UserRole::ContentManager->value,
                'password' => '',
                'passwordConfirmation' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame(UserRole::ContentManager, $user->role);
        $this->assertSame('Role Target Updated', $user->name);
        $this->assertSame($passwordHash, $user->password);
    }

    public function test_last_admin_cannot_be_demoted_or_deleted(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm([
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => UserRole::Manager->value,
            ])
            ->call('save')
            ->assertHasFormErrors(['role']);

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
        $this->assertFalse($admin->can('delete', $admin));
    }
}
