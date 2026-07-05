<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccessResetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_admin_access_repairs_office_and_demo_admin_authentication(): void
    {
        $this->artisan('alta:reset-admin-access')
            ->expectsOutputToContain('Auth::attempt(office@alta-trade.com.ua): true')
            ->expectsOutputToContain('Auth::attempt(admin@alta-trade.test): true')
            ->expectsOutputToContain('canAccessPanel(office@alta-trade.com.ua): true')
            ->expectsOutputToContain('canAccessPanel(admin@alta-trade.test): true')
            ->assertExitCode(0);

        $panel = filament()->getPanel('admin');

        foreach ([
            'office@alta-trade.com.ua',
            'admin@alta-trade.test',
        ] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $this->assertSame(UserRole::Admin, $user->role);
            $this->assertTrue(Hash::check('password', $user->password), $email . ' password hash is invalid.');

            Auth::logout();
            $this->assertTrue(Auth::attempt(['email' => $email, 'password' => 'password']), $email . ' Auth::attempt failed.');
            $this->assertTrue($user->fresh()->canAccessPanel($panel), $email . ' cannot access Filament admin panel.');
        }
    }
}
