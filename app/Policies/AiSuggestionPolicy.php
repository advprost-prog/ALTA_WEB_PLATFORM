<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AiSuggestion;
use App\Models\User;

class AiSuggestionPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (! $user->isAdmin()) {
            return null;
        }

        return in_array($ability, ['viewAny', 'view', 'create'], true) ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Manager, UserRole::ContentManager], true);
    }

    public function view(User $user, AiSuggestion $aiSuggestion): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, AiSuggestion $aiSuggestion): bool
    {
        return $this->edit($user, $aiSuggestion);
    }

    public function edit(User $user, AiSuggestion $aiSuggestion): bool
    {
        if (! $aiSuggestion->canBeEdited()) {
            return false;
        }

        if ($user->role === UserRole::Admin) {
            return true;
        }

        if ($user->role === UserRole::Manager) {
            return ! in_array($aiSuggestion->field, ['attributes', 'gtin_candidates'], true);
        }

        return $user->role === UserRole::ContentManager && $aiSuggestion->isContentField();
    }

    public function action(User $user, AiSuggestion $aiSuggestion): bool
    {
        return $this->apply($user, $aiSuggestion) || $this->reject($user, $aiSuggestion);
    }

    public function apply(User $user, AiSuggestion $aiSuggestion): bool
    {
        if (! $aiSuggestion->canBeAppliedAutomatically()) {
            return false;
        }

        return $user->role === UserRole::Admin
            || $user->role === UserRole::Manager
            || ($user->role === UserRole::ContentManager && $aiSuggestion->isContentField());
    }

    public function reject(User $user, AiSuggestion $aiSuggestion): bool
    {
        if (! $aiSuggestion->canBeRejected()) {
            return false;
        }

        return $user->role === UserRole::Admin
            || $user->role === UserRole::Manager
            || ($user->role === UserRole::ContentManager && $aiSuggestion->isContentField());
    }

    public function delete(User $user, AiSuggestion $aiSuggestion): bool
    {
        return false;
    }
}
