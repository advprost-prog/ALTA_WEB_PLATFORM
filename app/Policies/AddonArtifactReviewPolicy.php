<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Authorization for the addon artifact quarantine review workflow (Phase 3.3).
 *
 * Only administrators may approve/reject/revoke quarantined artifacts. Other
 * users may view statuses but must not see or trigger review actions.
 *
 * This is intentionally minimal: no large RBAC module, just a clear gate and
 * a reusable helper.
 */
final class AddonArtifactReviewPolicy
{
    public static function canReviewAddonArtifacts(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->role instanceof UserRole) {
            return $user->role === UserRole::Admin;
        }

        return (string) $user->role === UserRole::Admin->value;
    }

    public static function authorize(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return Gate::allows('review-addon-artifacts', $user);
    }
}
