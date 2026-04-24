<?php

namespace App\Services\Sms;

use App\Models\NotificationDelivery;
use App\Services\Sms\Contracts\SmsDriver;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * 面向應用層的 SMS 入口:挑 driver、送訊息、寫 audit 紀錄。
 *
 * Phase 8.0 骨架:直接 send + 寫 notification_deliveries 一筆。
 * Phase 8.3 以後會在這裡加 LINE/FCM fallback(按 SMS_INTEGRATION_PLAN v0.1 §「多通道 Fallback」)。
 */
class SmsManager
{
    private ?SmsDriver $driver = null;

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    public function send(SmsMessage $message): NotificationDelivery
    {
        $driver = $this->driver();

        $delivery = NotificationDelivery::create([
            'channel'    => 'sms',
            'driver'     => $driver->name(),
            'member_id'  => $message->memberId,
            'to_address' => $message->to,
            'content'    => $message->content,
            'purpose'    => $message->purpose,
            'status'     => 'queued',
        ]);

        $result = $driver->send($message);

        if ($result->success) {
            $delivery->update([
                'status'              => 'sent',
                'provider_message_id' => $result->providerMessageId,
                'credits_used'        => $result->creditsUsed,
                'sent_at'             => now(),
            ]);
        } else {
            $delivery->update([
                'status'        => 'failed',
                'error_message' => $result->errorMessage,
            ]);
        }

        return $delivery->fresh();
    }

    public function driver(?string $name = null): SmsDriver
    {
        if ($name === null && $this->driver !== null) {
            return $this->driver;
        }

        $name ??= $this->config->get('sms.default');
        $class = $this->config->get("sms.drivers.{$name}.class");

        if (! $class) {
            throw new RuntimeException("SMS driver [{$name}] not configured");
        }

        $instance = $this->container->make($class);

        if (! $instance instanceof SmsDriver) {
            throw new RuntimeException("SMS driver [{$name}] does not implement SmsDriver");
        }

        // 快取預設 driver 避免重複 instantiate;但外部指定 name 時不覆寫
        if ($name === $this->config->get('sms.default')) {
            $this->driver = $instance;
        }

        return $instance;
    }

    /** 測試用:強制換 driver(例如 feature test 塞 NullDriver)。 */
    public function setDriver(SmsDriver $driver): void
    {
        $this->driver = $driver;
    }
}
