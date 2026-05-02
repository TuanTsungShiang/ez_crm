<?php

namespace App\Http\Requests\Api\V1;

use App\Models\CouponBatch;
use Illuminate\Foundation\Http\FormRequest;

class CreateCouponBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', 'string', 'in:'.implode(',', [
                CouponBatch::TYPE_DISCOUNT_AMOUNT,
                CouponBatch::TYPE_DISCOUNT_PERCENT,
                CouponBatch::TYPE_POINTS,
            ])],
            'value' => ['required', 'integer', 'min:1', 'max:1000000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }
}
