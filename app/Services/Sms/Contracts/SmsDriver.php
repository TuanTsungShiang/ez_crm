<?php

namespace App\Services\Sms\Contracts;

use App\Services\Sms\SmsDeliveryResult;
use App\Services\Sms\SmsMessage;

/**
 * 所有 SMS driver 的共同介面。
 * Phase 8.0 骨架只要求 send();query() / balance() 等到 MitakeDriver 再加。
 */
interface SmsDriver
{
    public function send(SmsMessage $message): SmsDeliveryResult;

    /** 在 notification_deliveries 寫入 driver 欄位時用這個 slug。 */
    public function name(): string;
}
