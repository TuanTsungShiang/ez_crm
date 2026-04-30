<?php

namespace App\Services\Points;

use App\Events\Webhooks\PointAdjusted;
use App\Exceptions\Points\InsufficientPointsException;
use App\Models\Member;
use App\Models\PointTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Single entry point for every change to members.points.
 *
 * Atomic guarantees (per POINTS_INTEGRATION_PLAN §5.1):
 *   - lockForUpdate on the member row prevents concurrent over-deduction
 *   - point_transactions row + members.points cache write share one commit
 *   - idempotency_key (DB UNIQUE) blocks double-execute on retry; replays
 *     return the original transaction without rewriting state
 *
 * Direct writes to point_transactions or members.points bypass these
 * guarantees and are forbidden — see model docblocks.
 */
class PointService
{
    private const VALID_TYPES = [
        PointTransaction::TYPE_EARN,
        PointTransaction::TYPE_SPEND,
        PointTransaction::TYPE_ADJUST,
        PointTransaction::TYPE_EXPIRE,
        PointTransaction::TYPE_REFUND,
    ];

    public function adjust(
        Member $member,
        int $amount,
        string $reason,
        string $type,
        string $idempotencyKey,
        ?Model $source = null,
    ): PointTransaction {
        if ($amount === 0) {
            throw new InvalidArgumentException('Amount must be non-zero');
        }

        if (! in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException("Invalid transaction type: {$type}");
        }

        // Fast-fail outside the transaction — a replay must not hold a row
        // lock against the member while we look up the prior result.
        if ($existing = PointTransaction::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($member, $amount, $reason, $type, $idempotencyKey, $source) {
            $locked = Member::lockForUpdate()->findOrFail($member->id);

            $newBalance = $locked->points + $amount;
            if ($newBalance < 0) {
                throw new InsufficientPointsException($locked->id, $locked->points, $amount);
            }

            $actorId   = auth()->id();
            $actorType = $actorId ? PointTransaction::ACTOR_USER : PointTransaction::ACTOR_SYSTEM;

            $tx = PointTransaction::create([
                'member_id'       => $locked->id,
                'amount'          => $amount,
                'balance_after'   => $newBalance,
                'type'            => $type,
                'reason'          => $reason,
                'idempotency_key' => $idempotencyKey,
                'actor_id'        => $actorId,
                'actor_type'      => $actorType,
                'source_type'     => $source ? $source::class : null,
                'source_id'       => $source?->getKey(),
            ]);

            $locked->update(['points' => $newBalance]);

            event(new PointAdjusted($locked, $tx));

            return $tx;
        });
    }
}
