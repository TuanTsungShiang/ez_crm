<?php

namespace App\Events\Webhooks;

use App\Models\Order;
use Illuminate\Queue\SerializesModels;

class OrderPaid implements WebhookEvent
{
    use SerializesModels;

    public function __construct(public Order $order) {}

    public function toWebhookPayload(): array
    {
        return [
            'event' => 'order.paid',
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'order_no' => $this->order->order_no,
                'status' => $this->order->status,
                'member_uuid' => $this->order->member->uuid,
                'paid_amount' => $this->order->paid_amount,
                'payment_method' => $this->order->payment_method,
                'ecpay_trade_no' => $this->order->ecpay_trade_no,
                'paid_at' => $this->order->paid_at?->toIso8601String(),
            ],
        ];
    }
}
