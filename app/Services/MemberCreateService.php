<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MemberCreateService
{
    public function create(array $params): Member
    {
        return DB::transaction(function () use ($params) {
            $member = Member::create([
                'uuid'            => Str::uuid(),
                'name'            => $params['name'],
                'nickname'        => $params['nickname'] ?? null,
                'email'           => $params['email'] ?? null,
                'phone'           => $params['phone'] ?? null,
                'password'        => isset($params['password']) ? Hash::make($params['password']) : null,
                'status'          => $params['status'] ?? 1,
                'member_group_id' => $params['group_id'] ?? null,
            ]);

            $profile = $params['profile'] ?? [];
            MemberProfile::create([
                'member_id' => $member->id,
                'gender'    => $profile['gender'] ?? null,
                'birthday'  => $profile['birthday'] ?? null,
            ]);

            if (!empty($params['tag_ids'])) {
                $member->tags()->attach($params['tag_ids']);
            }

            return $member->load(['group', 'tags'])->loadCount('sns');
        });
    }
}
