<?php

namespace App\Policies;

use App\Models\User;

class NotificationOutboxPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->canAccessArea('sales');
    }

    public function view(User $user, mixed $record = null): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, mixed $record = null): bool
    {
        return false;
    }

    public function delete(User $user, mixed $record = null): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
