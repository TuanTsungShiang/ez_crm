<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\BaseApiRequest;

class ResetPasswordRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'code'     => ['required', 'string', 'size:6'],
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
