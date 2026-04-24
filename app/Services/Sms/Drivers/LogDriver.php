<?php

namespace App\Services\Sms\Drivers;

use App\Services\Sms\Contracts\SmsDriver;
use App\Services\Sms\SmsDeliveryResult;
use App\Services\Sms\SmsMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Dev / staging 預設 driver:把 SMS 內容印到 laravel.log,不發真簡訊。
 * 零成本,開發時可以在 log 看到 OTP 碼直接貼進前端測流程。
 */
class LogDriver implements SmsDriver
{
    public function send(SmsMessage $message): SmsDeliveryResult
    {
        $id = 'log-' . Str::ulid();

        Log::info('[SMS:log] delivered', [
            'id'         => $id,
            'to'         => $message->to,
            'purpose'    => $message->purpose,
            'member_id'  => $message->memberId,
            'content'    => $message->content,
        ]);

        return SmsDeliveryResult::ok(providerMessageId: $id, creditsUsed: 0.0);
    }

    public function name(): string
    {
        return 'log';
    }
}
