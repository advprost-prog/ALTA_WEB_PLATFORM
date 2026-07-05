<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, User $model): bool
    {
        return $user->isAdmin()
            && ! $user->is($model)
            && ! $this->isLastAdmin($model);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function changeRole(User $user, User $model, UserRole|string $role): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        $role = $role instanceof UserRole ? $role : UserRole::tryFrom((string) $role);

        if ($role === UserRole::Admin) {
            return true;
        }

        return ! $this->isLastAdmin($model);
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isAdmin();
    }

    private function isLastAdmin(User $model): bool
    {
        if ($model->role !== UserRole::Admin) {
            return false;
        }

        return User::query()
            ->where('role', UserRole::Admin->value)
            ->whereKeyNot($model->getKey())
            ->doesntExist();
    }
}
