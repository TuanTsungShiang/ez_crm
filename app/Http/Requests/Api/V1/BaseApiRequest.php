<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ApiCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class BaseApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function failedValidation(Validator $validator)
    {
        $code = $this->resolveValidationCode($validator);

        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'code'    => $code,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }

    private function resolveValidationCode(Validator $validator): string
    {
        $failed = $validator->failed();

        foreach ($failed as $field => $rules) {
            foreach (array_keys($rules) as $rule) {
                // Laravel failed() returns PascalCase rule names (e.g. "Required", "Unique")
                $normalized = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $rule));

                return ApiCode::fromValidationRule($normalized);
            }
        }

        return ApiCode::INVALID_FORMAT;
    }
}
