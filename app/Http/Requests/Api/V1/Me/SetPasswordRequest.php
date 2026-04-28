<?php

namespace App\Http\Requests\Api\V1\Me;

use App\Http\Requests\Api\V1\BaseApiRequest;

/**
 * For OAuth-only members setting their first real password.
 * Unlike UpdatePasswordRequest this does NOT require current_password
 * (the user has never had one — it was a Str::random placeholder).
 */
class SetPasswordRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.regex' => '密碼須包含大寫字母與數字',
        ];
    }
}
