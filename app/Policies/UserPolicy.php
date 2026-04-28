<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('user.view_any');
    }

    public function view(User $user, User $target): bool
    {
        return $user->can('user.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('user.manage');
    }

    public function update(User $user, User $target): bool
    {
        return $user->can('user.manage');
    }

    public function delete(User $user, User $target): bool
    {
        // Prevent deleting yourself even if you have user.manage.
        if ($user->id === $target->id) {
            return false;
        }

        return $user->can('user.manage');
    }
}
