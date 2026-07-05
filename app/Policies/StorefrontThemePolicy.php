<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\StorefrontTheme;
use App\Models\User;

class StorefrontThemePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Manager;
    }

    public function view(User $user, StorefrontTheme $storefrontTheme): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StorefrontTheme $storefrontTheme): bool
    {
        return false;
    }

    public function delete(User $user, StorefrontTheme $storefrontTheme): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function activate(User $user, StorefrontTheme $storefrontTheme): bool
    {
        return false;
    }

    public function preview(User $user, StorefrontTheme $storefrontTheme): bool
    {
        return $this->viewAny($user);
    }
}
