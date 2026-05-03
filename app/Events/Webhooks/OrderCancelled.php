<?php

namespace App\Events\Webhooks;

use App\Models\Order;
use Illuminate\Queue\SerializesModels;

class OrderCancelled implements WebhookEvent
{
    use SerializesModels;

    public function __construct(
        public Order $order,
        public ?string $reason = null,
    ) {}

    public function toWebhookPayload(): array
    {
        return [
            'event' => 'order.cancelled',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'order_no' => $this->order->order_no,
                'status' => $this->order->status,
                'member_uuid' => $this->order->member->uuid,
                'reason' => $this->reason,
                'cancelled_at' => $this->order->cancelled_at?->toIso8601String(),
            ],
        ];
    }
}
