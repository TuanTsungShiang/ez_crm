<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Coupon */
class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'status' => $this->status,
            'batch' => [
                'uuid' => $this->batch->uuid,
                'name' => $this->batch->name,
                'type' => $this->batch->type,
                'value' => $this->batch->value,
                'expires_at' => $this->batch->expires_at?->toIso8601String(),
            ],
            'member_uuid' => $this->member?->uuid,
            'redeemed_at' => $this->redeemed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
