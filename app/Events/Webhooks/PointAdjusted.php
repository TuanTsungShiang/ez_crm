<?php

namespace App\Events\Webhooks;

use App\Models\Member;
use App\Models\PointTransaction;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by PointService::adjust after a successful balance change.
 *
 * Actor info is read off the transaction row (rather than passing a User
 * separately) so that re-firing this event from a stored transaction —
 * e.g. webhook retry, replay tooling — produces the same payload without
 * needing a live auth context.
 */
class PointAdjusted implements WebhookEvent
{
    use SerializesModels;

    public function __construct(
        public Member $member,
        public PointTransaction $transaction,
    ) {}

    public function toWebhookPayload(): array
    {
        $actor = $this->transaction->actor;

        return [
            'event'       => 'points.adjusted',
            'occurred_at' => $this->transaction->created_at->toIso8601String(),
            'data' => [
                'member_uuid'   => $this->member->uuid,
                'amount'        => (int) $this->transaction->amount,
                'balance_after' => (int) $this->transaction->balance_after,
                'type'          => $this->transaction->type,
                'reason'        => $this->transaction->reason,
                'actor' => [
                    'id'   => $this->transaction->actor_id,
                    'type' => $this->transaction->actor_type,
                    'name' => $actor?->name,
                ],
            ],
        ];
    }
}
