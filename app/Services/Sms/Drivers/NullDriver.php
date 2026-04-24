<?php

namespace App\Services\Sms\Drivers;

use App\Services\Sms\Contracts\SmsDriver;
use App\Services\Sms\SmsDeliveryResult;
use App\Services\Sms\SmsMessage;
use Illuminate\Support\Str;

/**
 * 測試用 driver:不輸出任何東西,永遠回 success。
 * 適合 feature test 跑大量 case 時避免噴 log。
 */
class NullDriver implements SmsDriver
{
    public function send(SmsMessage $message): SmsDeliveryResult
    {
        return SmsDeliveryResult::ok(
            providerMessageId: 'null-' . Str::ulid(),
            creditsUsed: 0.0,
        );
    }

    public function name(): string
    {
        return 'null';
    }
}
