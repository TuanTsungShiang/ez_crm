<?php

namespace App\Policies;

use App\Models\MemberGroup;
use App\Models\User;

class MemberGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('member_group.view_any');
    }

    public function view(User $user, MemberGroup $group): bool
    {
        return $user->can('member_group.view_any');
    }

    public function create(User $user): bool
    {
        return $user->can('member_group.manage');
    }

    public function update(User $user, MemberGroup $group): bool
    {
        return $user->can('member_group.manage');
    }

    public function delete(User $user, MemberGroup $group): bool
    {
        return $user->can('member_group.manage');
    }
}
