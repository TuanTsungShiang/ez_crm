<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\BaseApiRequest;

class AdminRefundRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
