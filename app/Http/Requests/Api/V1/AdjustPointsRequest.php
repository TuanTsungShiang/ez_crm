<?php

namespace App\Http\Requests\Api\V1;

use App\Models\PointTransaction;
use Illuminate\Foundation\Http\FormRequest;

class AdjustPointsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate checked in controller via Policy
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', 'string', 'max:200'],
            'type'   => ['required', 'string', 'in:' . implode(',', [
                PointTransaction::TYPE_EARN,
                PointTransaction::TYPE_SPEND,
                PointTransaction::TYPE_ADJUST,
                PointTransaction::TYPE_REFUND,
            ])],
        ];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key', '');
    }
}
