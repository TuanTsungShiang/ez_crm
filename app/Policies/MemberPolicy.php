<?php

namespace App\Policies;

use App\Models\Member;
use App\Models\User;

class MemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('member.view_any');
    }

    public function view(User $user, Member $member): bool
    {
        return $user->can('member.view');
    }

    public function create(User $user): bool
    {
        return $user->can('member.create');
    }

    public function update(User $user, Member $member): bool
    {
        return $user->can('member.update');
    }

    public function delete(User $user, Member $member): bool
    {
        return $user->can('member.delete');
    }

    public function restore(User $user, Member $member): bool
    {
        return $user->can('member.restore');
    }

    public function forceDelete(User $user, Member $member): bool
    {
        return $user->can('member.delete');
    }

    public function viewPoints(User $user, Member $member): bool
    {
        return $user->can('points.view');
    }

    public function managePoints(User $user, Member $member): bool
    {
        return $user->can('points.manage');
    }
}
