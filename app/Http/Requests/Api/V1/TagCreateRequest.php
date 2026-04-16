<?php

namespace App\Http\Requests\Api\V1;

class TagCreateRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:100', 'unique:tags,name'],
            'color'       => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'color.regex' => 'color 格式必須為 hex（例：#FF5733）',
        ];
    }
}
