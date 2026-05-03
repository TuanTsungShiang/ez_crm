<?php

namespace App\Events\Webhooks;

use App\Models\Order;
use Illuminate\Queue\SerializesModels;

class OrderShipped implements WebhookEvent
{
    use SerializesModels;

    public function __construct(public Order $order) {}

    public function toWebhookPayload(): array
    {
        return [
            'event' => 'order.shipped',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'order_no' => $this->order->order_no,
                'status' => $this->order->status,
                'member_uuid' => $this->order->member->uuid,
                'shipped_at' => $this->order->shipped_at?->toIso8601String(),
            ],
        ];
    }
}
