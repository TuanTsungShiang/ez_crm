<?php

namespace App\Events\Webhooks;

use App\Models\Coupon;
use App\Models\Member;
use Illuminate\Queue\SerializesModels;

class CouponRedeemed implements WebhookEvent
{
    use SerializesModels;

    public function __construct(
        public Coupon $coupon,
        public Member $member,
    ) {}

    public function toWebhookPayload(): array
    {
        $batch = $this->coupon->batch;

        return [
            'event' => 'coupon.redeemed',
            'occurred_at' => $this->coupon->redeemed_at->toIso8601String(),
            'data' => [
                'coupon_code' => $this->coupon->code,
                'batch_name' => $batch->name,
                'type' => $batch->type,
                'value' => $batch->value,
                'member_uuid' => $this->member->uuid,
                'redeemed_at' => $this->coupon->redeemed_at->toIso8601String(),
            ],
        ];
    }
}
