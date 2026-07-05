<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AiRun;
use App\Models\User;

class AiRunPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Manager, UserRole::ContentManager], true);
    }

    public function view(User $user, AiRun $aiRun): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, AiRun $aiRun): bool
    {
        return false;
    }

    public function delete(User $user, AiRun $aiRun): bool
    {
        return false;
    }
}
