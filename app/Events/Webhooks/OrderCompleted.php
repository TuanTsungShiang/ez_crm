<?php

namespace App\Events\Webhooks;

use App\Models\Order;
use Illuminate\Queue\SerializesModels;

class OrderCompleted implements WebhookEvent
{
    use SerializesModels;

    public function __construct(public Order $order) {}

    public function toWebhookPayload(): array
    {
        return [
            'event' => 'order.completed',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'order_no' => $this->order->order_no,
                'status' => $this->order->status,
                'member_uuid' => $this->order->member->uuid,
                'paid_amount' => $this->order->paid_amount,
                'points_earned' => $this->order->points_earned,
                'completed_at' => $this->order->completed_at?->toIso8601String(),
            ],
        ];
    }
}
