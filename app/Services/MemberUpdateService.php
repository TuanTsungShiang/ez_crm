<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MemberUpdateService
{
    public function update(Member $member, array $params): Member
    {
        return DB::transaction(function () use ($member, $params) {
            $this->updateMemberCore($member, $params);
            $this->updateProfile($member, $params);
            $this->syncTags($member, $params);

            return $member->fresh(['group', 'tags', 'profile', 'sns'])->loadCount('sns');
        });
    }

    private function updateMemberCore(Member $member, array $params): void
    {
        $fillable = ['name', 'nickname', 'email', 'phone', 'status'];

        foreach ($fillable as $field) {
            if (array_key_exists($field, $params)) {
                $member->{$field} = $params[$field];
            }
        }

        if (array_key_exists('group_id', $params)) {
            $member->member_group_id = $params['group_id'];
        }

        if (array_key_exists('password', $params) && $params['password'] !== null) {
            $member->password = Hash::make($params['password']);
        }

        // KYC: email / phone 變動時自動清空對應的 verified_at
        if ($member->isDirty('email')) {
            $member->email_verified_at = null;
        }
        if ($member->isDirty('phone')) {
            $member->phone_verified_at = null;
        }

        $member->save();
    }

    private function updateProfile(Member $member, array $params): void
    {
        if (!array_key_exists('profile', $params)) {
            return;
        }

        $profileData = $params['profile'] ?? [];

        $profile = $member->profile ?? new MemberProfile(['member_id' => $member->id]);

        foreach (['avatar', 'gender', 'birthday', 'bio', 'language', 'timezone'] as $field) {
            if (array_key_exists($field, $profileData)) {
                $profile->{$field} = $profileData[$field];
            }
        }

        $profile->member_id = $member->id;
        $profile->save();
    }

    private function syncTags(Member $member, array $params): void
    {
        if (!array_key_exists('tag_ids', $params)) {
            return;
        }

        $member->tags()->sync($params['tag_ids'] ?? []);
    }
}
