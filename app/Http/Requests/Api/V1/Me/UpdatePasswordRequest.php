<?php

namespace App\Http\Requests\Api\V1\Me;

use App\Http\Requests\Api\V1\BaseApiRequest;

class UpdatePasswordRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'different:current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.regex'     => '密碼須包含大寫字母與數字',
            'password.different' => '新密碼不可與目前密碼相同',
        ];
    }
}
