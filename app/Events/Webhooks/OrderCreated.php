<?php

namespace App\Events\Webhooks;

use App\Models\Order;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements WebhookEvent
{
    use SerializesModels;

    public function __construct(public Order $order) {}

    public function toWebhookPayload(): array
    {
        return [
            'event' => 'order.created',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'order_no' => $this->order->order_no,
                'status' => $this->order->status,
                'member_uuid' => $this->order->member->uuid,
                'subtotal' => $this->order->subtotal,
                'discount_total' => $this->order->discount_total,
                'paid_amount' => $this->order->paid_amount,
                'items_count' => $this->order->items()->count(),
                'created_at' => $this->order->created_at->toIso8601String(),
            ],
        ];
    }
}
