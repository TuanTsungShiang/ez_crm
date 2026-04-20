<?php

namespace App\Http\Requests\Api\V1\Me;

use App\Http\Requests\Api\V1\BaseApiRequest;
use Illuminate\Validation\Rule;

class UpdateMeRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $memberId = $this->user()?->id;

        return [
            'name'     => ['sometimes', 'string', 'max:100'],
            'nickname' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone'    => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('members', 'phone')->ignore($memberId)],
        ];
    }
}
