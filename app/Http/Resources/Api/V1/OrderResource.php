<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderCoupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_no' => $this->order_no,
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'paid_amount' => $this->paid_amount,
            'refund_amount' => $this->refund_amount,
            'points_earned' => $this->points_earned,
            'payment_method' => $this->payment_method,

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_sku' => $item->product_sku,
                'product_name' => $item->product_name,
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'subtotal' => $item->subtotal,
            ])),

            'shipping_address' => $this->whenLoaded('shippingAddress', fn () => $this->shippingAddress ? [
                'recipient_name' => $this->shippingAddress->recipient_name,
                'phone' => $this->shippingAddress->phone,
                'postal_code' => $this->shippingAddress->postal_code,
                'city' => $this->shippingAddress->city,
                'district' => $this->shippingAddress->district,
                'address_line' => $this->shippingAddress->address_line,
            ] : null),

            'coupons_applied' => $this->whenLoaded('coupons', fn () => $this->coupons->map(function ($c) {
                /** @var Coupon&object{pivot: OrderCoupon} $c */
                return [
                    'code' => $c->code,
                    'discount_applied' => $c->pivot->discount_applied,
                ];
            })),

            'paid_at' => $this->paid_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
