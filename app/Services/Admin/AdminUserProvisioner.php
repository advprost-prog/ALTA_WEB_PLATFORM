<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AdminUserProvisioner
{
    public const PRIMARY_ADMIN_EMAIL = 'o.lykhobaba@alta-trade.com.ua';

    /**
     * @var array<string, array{name: string, role: UserRole, force_password: bool}>
     */
    private const DEMO_USERS = [
        'office@alta-trade.com.ua' => [
            'name' => 'Alta Office',
            'role' => UserRole::Admin,
            'force_password' => false,
        ],
        'admin@alta-trade.test' => [
            'name' => 'Alta Admin',
            'role' => UserRole::Admin,
            'force_password' => true,
        ],
        'manager@alta-trade.test' => [
            'name' => 'Sales Manager',
            'role' => UserRole::Manager,
            'force_password' => true,
        ],
        'content@alta-trade.test' => [
            'name' => 'Content Manager',
            'role' => UserRole::ContentManager,
            'force_password' => true,
        ],
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function provision(): array
    {
        return $this->provisionPrimaryAdmin() + $this->provisionDemoUsers();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function provisionPrimaryAdmin(): array
    {
        return [
            self::PRIMARY_ADMIN_EMAIL => $this->ensureUser(
                email: self::PRIMARY_ADMIN_EMAIL,
                name: 'Олександр Лихобаба',
                role: UserRole::Admin,
                forcePassword: false,
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function provisionDemoUsers(): array
    {
        $provisioned = [];

        foreach (self::DEMO_USERS as $email => $user) {
            $provisioned[$email] = $this->ensureUser(
                email: $email,
                name: $user['name'],
                role: $user['role'],
                forcePassword: $user['force_password'],
            );
        }

        return $provisioned;
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureUser(string $email, string $name, UserRole $role, bool $forcePassword): array
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $existed = $user->exists;
        $oldRole = $this->roleForOutput($user);
        $passwordWasChanged = ! $existed || $forcePassword;

        $attributes = [
            'name' => $user->name ?: $name,
            'email' => $email,
            'role' => $role,
        ];

        if ($passwordWasChanged) {
            $attributes['password'] = Hash::make('password');
        }

        if (Schema::hasColumn('users', 'email_verified_at') && blank($user->email_verified_at)) {
            $attributes['email_verified_at'] = now();
        }

        User::unguarded(fn () => $user->forceFill($attributes)->save());
        $user->refresh();

        return [
            'existed_before' => $existed,
            'old_role' => $oldRole,
            'current_role' => $this->roleForOutput($user),
            'is_admin' => $user->isAdmin(),
            'can_access_panel' => $this->canAccessAdminPanel($user),
            'password_changed' => $passwordWasChanged,
            'email_verified' => filled($user->email_verified_at),
        ];
    }

    private function canAccessAdminPanel(User $user): bool
    {
        try {
            return $user->canAccessPanel(filament()->getPanel('admin'));
        } catch (\Throwable) {
            return false;
        }
    }

    private function roleForOutput(User $user): ?string
    {
        if (! $user->exists) {
            return null;
        }

        try {
            $role = $user->role;
        } catch (\Throwable) {
            return (string) $user->getRawOriginal('role');
        }

        if ($role instanceof UserRole) {
            return $role->value;
        }

        return (string) $role;
    }
}
