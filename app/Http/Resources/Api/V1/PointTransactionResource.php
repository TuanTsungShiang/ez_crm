<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PointTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PointTransaction */
class PointTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'amount'         => $this->amount,
            'balance_after'  => $this->balance_after,
            'type'           => $this->type,
            'reason'         => $this->reason,
            'idempotency_key'=> $this->idempotency_key,
            'actor'          => [
                'id'   => $this->actor_id,
                'type' => $this->actor_type,
                'name' => $this->actor?->name,
            ],
            'source_type'    => $this->source_type,
            'source_id'      => $this->source_id,
            'expires_at'     => $this->expires_at?->toIso8601String(),
            'created_at'     => $this->created_at->toIso8601String(),
        ];
    }
}
