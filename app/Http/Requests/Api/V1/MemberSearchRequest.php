<?php

namespace App\Http\Requests\Api\V1;

class MemberSearchRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'keyword'      => ['nullable', 'string', 'max:100'],
            'status'       => ['nullable', 'integer', 'in:0,1,2'],
            'group_id'     => ['nullable', 'integer', 'exists:member_groups,id'],
            'tag_ids'      => ['nullable', 'array'],
            'tag_ids.*'    => ['integer', 'exists:tags,id'],
            'gender'       => ['nullable', 'integer', 'in:0,1,2'],
            'has_sns'      => ['nullable', 'boolean'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'per_page'     => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'         => ['nullable', 'integer', 'min:1'],
            'sort_by'      => ['nullable', 'string', 'in:created_at,last_login_at,name'],
            'sort_dir'     => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
