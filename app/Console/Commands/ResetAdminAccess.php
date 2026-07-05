<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Panel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class ResetAdminAccess extends Command
{
    protected $signature = 'alta:reset-admin-access';

    protected $description = 'Create or repair Alta-Trade demo users and verify admin authentication.';

    public function handle(): int
    {
        $this->info('Alta-Trade admin access reset');
        $this->line('DB_CONNECTION: ' . DB::connection()->getName());
        $this->line('DB_DATABASE: ' . DB::getDatabaseName());
        $this->line('Auth provider model: ' . (string) config('auth.providers.users.model'));
        $this->line('Web guard provider: ' . (string) config('auth.guards.web.provider'));
        $this->newLine();

        foreach ($this->demoUsers() as $email => $payload) {
            $user = User::query()->firstOrNew(['email' => $email]);

            $attributes = [
                'name' => $payload['name'],
                'email' => $email,
                'password' => Hash::make('password'),
            ];

            if (Schema::hasColumn('users', 'role')) {
                $attributes['role'] = $payload['role'];
            }

            if (Schema::hasColumn('users', 'email_verified_at')) {
                $attributes['email_verified_at'] = now();
            }

            User::unguarded(fn () => $user->forceFill($attributes)->save());
            $user->refresh();

            $this->line(sprintf(
                '%s | role=%s | password_ok=%s',
                $user->email,
                $this->roleForOutput($user),
                Hash::check('password', $user->password) ? 'true' : 'false',
            ));
        }

        $this->newLine();

        foreach (['office@alta-trade.com.ua', 'admin@alta-trade.test'] as $email) {
            $this->verifyAuthentication($email);
        }

        Auth::logout();

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{name: string, role: UserRole}>
     */
    private function demoUsers(): array
    {
        return [
            'office@alta-trade.com.ua' => [
                'name' => 'Alta Office',
                'role' => UserRole::Admin,
            ],
            'admin@alta-trade.test' => [
                'name' => 'Alta Admin',
                'role' => UserRole::Admin,
            ],
            'manager@alta-trade.test' => [
                'name' => 'Sales Manager',
                'role' => UserRole::Manager,
            ],
            'content@alta-trade.test' => [
                'name' => 'Content Manager',
                'role' => UserRole::ContentManager,
            ],
        ];
    }

    private function verifyAuthentication(string $email): void
    {
        Auth::logout();

        $attempt = Auth::attempt([
            'email' => $email,
            'password' => 'password',
        ]);

        $this->line(sprintf('Auth::attempt(%s): %s', $email, $attempt ? 'true' : 'false'));

        $user = User::where('email', $email)->first();

        if (! $attempt) {
            $this->warn('Auth attempt diagnostic for ' . $email);
            $this->line('user_exists: ' . ($user ? 'true' : 'false'));
            $this->line('hash_check: ' . ($user && Hash::check('password', $user->password) ? 'true' : 'false'));
            $this->line('auth_provider_model: ' . (string) config('auth.providers.users.model'));
            $this->line('guard_provider: ' . (string) config('auth.guards.web.provider'));
            $this->line('password_hash_prefix: ' . ($user ? substr((string) $user->password, 0, 7) : '-'));
            $this->line('db_email: ' . ($user?->email ?? '-'));

            return;
        }

        $panelAccess = $user ? $this->canAccessAdminPanel($user) : false;
        $this->line(sprintf('canAccessPanel(%s): %s', $email, $panelAccess ? 'true' : 'false'));
    }

    private function canAccessAdminPanel(User $user): bool
    {
        try {
            /** @var Panel $panel */
            $panel = filament()->getPanel('admin');

            return $user->canAccessPanel($panel);
        } catch (\Throwable $exception) {
            $this->warn('Unable to resolve Filament admin panel: ' . $exception->getMessage());

            return false;
        }
    }

    private function roleForOutput(User $user): string
    {
        $role = $user->role;

        if ($role instanceof UserRole) {
            return $role->value;
        }

        return (string) $role;
    }
}
