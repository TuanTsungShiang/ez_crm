<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class GroupUpdateRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $groupId = $this->route('group')?->id;

        return [
            'name'        => ['sometimes', 'string', 'max:100', Rule::unique('member_groups', 'name')->ignore($groupId)],
            'description' => ['sometimes', 'nullable', 'string'],
            'sort_order'  => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
