<?php

namespace App\Events\Webhooks;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Queue\SerializesModels;

class OrderRefunded implements WebhookEvent
{
    use SerializesModels;

    public function __construct(
        public Order $order,
        public Refund $refund,
    ) {}

    public function toWebhookPayload(): array
    {
        return [
            'event' => 'order.refunded',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'order_no' => $this->order->order_no,
                'status' => $this->order->status,
                'member_uuid' => $this->order->member->uuid,
                'refund_amount' => $this->refund->amount,
                'refund_type' => $this->refund->type,   // 'full' | 'partial'
                'total_refunded' => $this->order->refund_amount,
                'points_refunded' => $this->order->points_refunded,
                'reason' => $this->refund->reason,
            ],
        ];
    }
}
