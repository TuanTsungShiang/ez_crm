<?php

namespace App\Services\Sms;

/**
 * SMS 發送請求的值物件。
 * Driver 接到這個物件後負責把 content 送到 to,回傳 SmsDeliveryResult。
 */
class SmsMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $content,
        public readonly string $purpose,
        public readonly ?int $memberId = null,
    ) {}
}
