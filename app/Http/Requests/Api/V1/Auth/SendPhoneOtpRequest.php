<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\BaseApiRequest;

class SendPhoneOtpRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 放寬格式(台灣 09xx / 國際 +886...),真實格式校驗交給 SmsDriver
            'phone' => ['required', 'string', 'regex:/^\+?[0-9\-\s]{8,20}$/'],
        ];
    }
}
