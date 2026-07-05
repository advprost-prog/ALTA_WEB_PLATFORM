<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Filament\Pages\AiSettingsPage;
use App\Filament\Pages\AiThemeStudioPage;
use App\Filament\Resources\AiSuggestions\AiSuggestionResource;
use App\Filament\Resources\StorefrontThemes\StorefrontThemeResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\AiSetting;
use App\Models\AiSuggestion;
use App\Models\User;
use App\Services\Admin\AdminUserProvisioner;
use Illuminate\Console\Command;

class AdminGovernanceCheck extends Command
{
    protected $signature = 'alta:admin-governance-check';

    protected $description = 'Repair and report Alta-Trade admin, AI settings, AI suggestions, and user governance state.';

    public function handle(AdminUserProvisioner $provisioner): int
    {
        $summary = $provisioner->provision();
        $primary = User::query()->where('email', AdminUserProvisioner::PRIMARY_ADMIN_EMAIL)->first();
        $primarySummary = $summary[AdminUserProvisioner::PRIMARY_ADMIN_EMAIL] ?? [];
        $adminCount = User::query()->where('role', UserRole::Admin->value)->count();

        $this->info('Alta-Trade admin governance');
        $this->line('primary_admin_email: ' . AdminUserProvisioner::PRIMARY_ADMIN_EMAIL);
        $this->line('primary_admin_existed_before: ' . $this->yesNo((bool) ($primarySummary['existed_before'] ?? false)));
        $this->line('primary_admin_old_role: ' . (($primarySummary['old_role'] ?? null) ?: '-'));
        $this->line('primary_admin_role: ' . ($this->roleForOutput($primary) ?: '-'));
        $this->line('primary_admin_is_admin: ' . $this->yesNo($primary?->isAdmin() ?? false));
        $this->line('primary_admin_can_access_panel: ' . $this->yesNo($primary ? $this->canAccessAdminPanel($primary) : false));
        $this->line('primary_admin_email_verified: ' . $this->yesNo(filled($primary?->email_verified_at)));
        $this->line('admin_users_count: ' . $adminCount);
        $this->line('ai_settings_page_class: ' . $this->exists(AiSettingsPage::class));
        $this->line('user_resource_class: ' . $this->exists(UserResource::class));
        $this->line('ai_suggestion_resource_class: ' . $this->exists(AiSuggestionResource::class));
        $this->line('storefront_theme_resource_class: ' . $this->exists(StorefrontThemeResource::class));
        $this->line('ai_theme_studio_page_class: ' . $this->exists(AiThemeStudioPage::class));
        $this->line('pending_ai_suggestions_count: ' . AiSuggestion::query()->where('status', AiSuggestion::STATUS_PENDING)->count());
        $this->line('unsupported_apply_suggestions_count: ' . $this->unsupportedApplySuggestionsCount());
        $this->line('ai_setting_record_exists: ' . $this->yesNo(AiSetting::query()->exists()));
        $this->line('ai_settings_visible_for_admin: ' . $this->yesNo($this->aiSettingsVisibleForAdmin()));

        return self::SUCCESS;
    }

    private function unsupportedApplySuggestionsCount(): int
    {
        return AiSuggestion::query()
            ->whereIn('status', [AiSuggestion::STATUS_PENDING, AiSuggestion::STATUS_ACCEPTED])
            ->get()
            ->filter(fn (AiSuggestion $suggestion): bool => ! $suggestion->canBeAppliedAutomatically())
            ->count();
    }

    private function aiSettingsVisibleForAdmin(): bool
    {
        $admin = User::query()->where('role', UserRole::Admin->value)->first();

        if (! $admin) {
            return false;
        }

        $guard = auth()->guard();
        $previousUser = $guard->user();
        $guard->setUser($admin);

        try {
            return AiSettingsPage::canAccess();
        } finally {
            if ($previousUser) {
                $guard->setUser($previousUser);
            } else {
                $guard->forgetUser();
            }
        }
    }

    private function canAccessAdminPanel(User $user): bool
    {
        try {
            return $user->canAccessPanel(filament()->getPanel('admin'));
        } catch (\Throwable) {
            return false;
        }
    }

    private function roleForOutput(?User $user): ?string
    {
        if (! $user) {
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

        return $role ? (string) $role : null;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    /**
     * @param  class-string  $class
     */
    private function exists(string $class): string
    {
        return class_exists($class) ? 'yes' : 'no';
    }
}
