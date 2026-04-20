<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\BaseApiRequest;

class RegisterRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:100'],
            'email'       => ['required', 'email', 'unique:members,email', 'max:255'],
            'password'    => ['required', 'confirmed', 'min:8', 'regex:/[A-Z]/', 'regex:/[0-9]/'],
            'phone'       => ['nullable', 'string', 'max:20'],
            'agree_terms' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.regex'       => '密碼須包含大寫字母與數字',
            'agree_terms.accepted' => '請同意服務條款',
        ];
    }
}
