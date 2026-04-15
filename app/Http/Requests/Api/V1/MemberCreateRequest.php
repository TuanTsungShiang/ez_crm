<?php

namespace App\Http\Requests\Api\V1;

class MemberCreateRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:100'],
            'nickname'         => ['nullable', 'string', 'max:100'],
            'email'            => ['nullable', 'email', 'max:191', 'unique:members,email', 'required_without:phone'],
            'phone'            => ['nullable', 'string', 'max:20', 'unique:members,phone', 'required_without:email'],
            'password'         => ['nullable', 'string', 'min:8'],
            'status'           => ['nullable', 'integer', 'in:0,1,2'],
            'group_id'         => ['nullable', 'integer', 'exists:member_groups,id'],
            'tag_ids'          => ['nullable', 'array'],
            'tag_ids.*'        => ['integer', 'exists:tags,id'],
            'profile.gender'   => ['nullable', 'integer', 'in:0,1,2'],
            'profile.birthday' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required_without' => 'email 與 phone 至少需填一個',
            'phone.required_without' => 'email 與 phone 至少需填一個',
        ];
    }
}
