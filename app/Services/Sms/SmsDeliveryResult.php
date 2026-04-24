<?php

namespace App\Services\Sms;

/**
 * Driver 回傳的結果。Manager 用這個寫入 notification_deliveries。
 */
class SmsDeliveryResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?float $creditsUsed = null,
        public readonly ?string $errorMessage = null,
        public readonly array $rawResponse = [],
    ) {}

    public static function ok(?string $providerMessageId = null, ?float $creditsUsed = null, array $raw = []): self
    {
        return new self(
            success: true,
            providerMessageId: $providerMessageId,
            creditsUsed: $creditsUsed,
            rawResponse: $raw,
        );
    }

    public static function fail(string $errorMessage, array $raw = []): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            rawResponse: $raw,
        );
    }
}
