<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait HandlesCommerceAuthorization
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->canAccessArea($this->area());
    }

    public function view(User $user, mixed $record = null): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, mixed $record = null): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, mixed $record = null): bool
    {
        return $user->canDeleteCommerceRecords();
    }

    public function deleteAny(User $user): bool
    {
        return $this->delete($user);
    }

    public function forceDelete(User $user, mixed $record = null): bool
    {
        return $this->delete($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->delete($user);
    }

    public function restore(User $user, mixed $record = null): bool
    {
        return $this->delete($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->delete($user);
    }

    protected function area(): string
    {
        return property_exists($this, 'area') ? $this->area : 'catalog';
    }
}
