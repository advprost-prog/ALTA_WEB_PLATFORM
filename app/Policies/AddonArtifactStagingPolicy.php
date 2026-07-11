<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

final class AddonArtifactStagingPolicy
{
    public static function canManage(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
