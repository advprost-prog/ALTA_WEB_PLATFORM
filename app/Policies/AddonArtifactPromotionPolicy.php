<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

final class AddonArtifactPromotionPolicy
{
    public static function canPromote(User $user): bool
    {
        return self::isPrivilegedRole($user);
    }

    public static function canRollback(User $user): bool
    {
        return self::isPrivilegedRole($user);
    }

    private static function isPrivilegedRole(User $user): bool
    {
        try {
            $role = $user->role;
        } catch (\Throwable) {
            $role = $user->getRawOriginal('role');
        }

        if ($role instanceof UserRole) {
            return $role === UserRole::Admin;
        }

        return in_array((string) $role, [UserRole::Admin->value, 'super_admin'], true);
    }
}
