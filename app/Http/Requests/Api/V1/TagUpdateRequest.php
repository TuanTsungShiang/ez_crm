<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class TagUpdateRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $tagId = $this->route('tag')?->id;

        return [
            'name'        => ['sometimes', 'string', 'max:100', Rule::unique('tags', 'name')->ignore($tagId)],
            'color'       => ['sometimes', 'nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'color.regex' => 'color 格式必須為 hex（例：#FF5733）',
        ];
    }
}
