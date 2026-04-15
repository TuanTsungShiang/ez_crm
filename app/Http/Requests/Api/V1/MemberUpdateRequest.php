<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class MemberUpdateRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $memberId = $this->route('member')?->id;

        return [
            'name'             => ['sometimes', 'string', 'max:100'],
            'nickname'         => ['sometimes', 'nullable', 'string', 'max:100'],
            'email'            => ['sometimes', 'nullable', 'email', 'max:191', Rule::unique('members', 'email')->ignore($memberId)],
            'phone'            => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('members', 'phone')->ignore($memberId)],
            'password'         => ['sometimes', 'nullable', 'string', 'min:8'],
            'status'           => ['sometimes', 'integer', 'in:0,1,2'],
            'group_id'         => ['sometimes', 'nullable', 'integer', 'exists:member_groups,id'],
            'tag_ids'          => ['sometimes', 'array'],
            'tag_ids.*'        => ['integer', 'exists:tags,id'],
            'profile'          => ['sometimes', 'array'],
            'profile.avatar'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.gender'   => ['sometimes', 'nullable', 'integer', 'in:0,1,2'],
            'profile.birthday' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'profile.bio'      => ['sometimes', 'nullable', 'string'],
            'profile.language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'profile.timezone' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
