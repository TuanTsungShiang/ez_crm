<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('role.view_any');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('role.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('role.manage');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('role.manage');
    }

    public function delete(User $user, Role $role): bool
    {
        // Prevent deleting baseline roles even with role.manage.
        // Custom roles (added via UI) can be deleted.
        $baselineRoles = ['super_admin', 'admin', 'customer_support', 'marketing', 'viewer'];
        if (in_array($role->name, $baselineRoles, true)) {
            return false;
        }

        return $user->can('role.manage');
    }
}
