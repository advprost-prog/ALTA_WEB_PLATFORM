<?php

namespace App\Policies;

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Policies\Concerns\HandlesCommerceAuthorization;

class NotificationTemplatePolicy
{
    use HandlesCommerceAuthorization;

    protected string $area = 'settings';

    public function before(User $user, string $ability): ?bool
    {
        if (! $user->isAdmin()) {
            return null;
        }

        return str_contains(strtolower($ability), 'delete') ? null : true;
    }

    public function delete(User $user, mixed $record = null): bool
    {
        if ($record instanceof NotificationTemplate && $record->is_system) {
            return false;
        }

        return $user->canDeleteCommerceRecords();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
