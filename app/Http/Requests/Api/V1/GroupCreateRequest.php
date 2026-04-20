<?php

namespace App\Http\Requests\Api\V1;

class GroupCreateRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:100', 'unique:member_groups,name'],
            'description' => ['nullable', 'string'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ];
    }
}
