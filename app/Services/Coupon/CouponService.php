<?php

namespace App\Services\Coupon;

use App\Events\Webhooks\CouponRedeemed;
use App\Exceptions\Coupon\CouponExpiredException;
use App\Exceptions\Coupon\InvalidCouponStateException;
use App\Models\Coupon;
use App\Models\CouponBatch;
use App\Models\Member;
use App\Models\User;
use App\Services\Points\PointService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single entry point for all coupon state transitions.
 *
 * State machine (per COUPON_INTEGRATION_PLAN §4):
 *   created  → redeemed  (via redeem)
 *   created  → expired   (detected at redeem-time when batch.expires_at is past)
 *   redeemed → cancelled (via cancel)
 *
 * Concurrency safety: redeem and cancel use lockForUpdate inside DB::transaction.
 * Two simultaneous redeem requests for the same code: only the first succeeds,
 * the second finds status='redeemed' and throws InvalidCouponStateException.
 */
class CouponService
{
    public function __construct(private PointService $pointService) {}

    // ── Batch creation ─────────────────────────────────────────────────────

    /**
     * Create a batch and bulk-insert `quantity` unique coupon codes.
     * Code format: EZCRM-XXXX-XXXX (alphanumeric, uppercase)
     */
    public function createBatch(array $data, ?User $creator = null): CouponBatch
    {
        return DB::transaction(function () use ($data, $creator) {
            $batch = CouponBatch::create([
                'uuid' => (string) Str::uuid(),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'],
                'value' => (int) $data['value'],
                'quantity' => (int) $data['quantity'],
                'created_by' => $creator?->id,
                'starts_at' => $data['starts_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            $rows = [];
            $attempts = 0;
            while (count($rows) < $batch->quantity) {
                $code = $this->generateCode();
                if (! Coupon::where('code', $code)->exists()) {
                    $rows[] = [
                        'code' => $code,
                        'batch_id' => $batch->id,
                        'status' => Coupon::STATUS_CREATED,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                // Safety: avoid infinite loop on tiny keyspace (shouldn't happen)
                if (++$attempts > $batch->quantity * 10) {
                    break;
                }
            }

            Coupon::insert($rows);

            return $batch;
        });
    }

    // ── Read-only verify ───────────────────────────────────────────────────

    /**
     * Verify coupon validity without changing state.
     * Throws if the coupon cannot be redeemed.
     */
    public function verify(string $code, ?Member $member = null): Coupon
    {
        $coupon = Coupon::where('code', strtoupper($code))->with('batch')->firstOrFail();

        $this->assertRedeemable($coupon, $member);

        return $coupon;
    }

    // ── State transitions ──────────────────────────────────────────────────

    /**
     * Atomically redeem a coupon for a member.
     * Uses lockForUpdate to prevent concurrent double-redemption.
     */
    public function redeem(string $code, Member $member, ?User $actor = null): Coupon
    {
        // Fast-fail expiry check outside the transaction: if the batch is expired,
        // mark the coupon and throw without holding a row lock.
        $pre = Coupon::where('code', strtoupper($code))->with('batch')->firstOrFail();
        if ($pre->batch->isExpired() && $pre->isCreated()) {
            $pre->update(['status' => Coupon::STATUS_EXPIRED]);
            throw new CouponExpiredException($pre->code);
        }

        return DB::transaction(function () use ($code, $member) {
            $coupon = Coupon::where('code', strtoupper($code))
                ->with('batch')
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertRedeemable($coupon, $member);

            // Apply coupon effect
            if ($coupon->batch->type === CouponBatch::TYPE_POINTS) {
                $this->pointService->adjust(
                    $member,
                    (int) $coupon->batch->value,
                    "優惠券 {$coupon->code} 兌換",
                    'earn',
                    "coupon-redeem-{$coupon->id}",
                );
            }

            $coupon->update([
                'status' => Coupon::STATUS_REDEEMED,
                'redeemed_by' => $member->id,
                'redeemed_at' => now(),
            ]);

            event(new CouponRedeemed($coupon->fresh(), $member));

            return $coupon->fresh();
        });
    }

    /**
     * Cancel a redeemed coupon (admin action).
     * Reverses Points if the batch type is 'points'.
     */
    public function cancel(string $code, User $cancelledBy): Coupon
    {
        return DB::transaction(function () use ($code, $cancelledBy) {
            $coupon = Coupon::where('code', strtoupper($code))
                ->with('batch', 'redeemedBy')
                ->lockForUpdate()
                ->firstOrFail();

            if (! $coupon->isRedeemed()) {
                throw new InvalidCouponStateException($coupon->status, 'cancel');
            }

            // Reverse Points if applicable
            if ($coupon->batch->type === CouponBatch::TYPE_POINTS && $coupon->redeemedBy) {
                $this->pointService->adjust(
                    $coupon->redeemedBy,
                    -(int) $coupon->batch->value,
                    "優惠券 {$coupon->code} 取消核銷退回",
                    'refund',
                    "coupon-cancel-{$coupon->id}",
                );
            }

            $coupon->update([
                'status' => Coupon::STATUS_CANCELLED,
                'cancelled_by' => $cancelledBy->id,
                'cancelled_at' => now(),
            ]);

            return $coupon->fresh();
        });
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function assertRedeemable(Coupon $coupon, ?Member $member): void
    {
        // Check batch expiry first (more specific error)
        if ($coupon->batch->isExpired()) {
            // Mark expired in DB if still 'created'
            if ($coupon->isCreated()) {
                $coupon->update(['status' => Coupon::STATUS_EXPIRED]);
            }
            throw new CouponExpiredException($coupon->code);
        }

        // Check batch has started
        if (! $coupon->batch->hasStarted()) {
            throw new InvalidCouponStateException($coupon->status, 'redeem_before_start');
        }

        // Check state
        if (! $coupon->isCreated()) {
            throw new InvalidCouponStateException($coupon->status, 'redeem');
        }

        // Check member restriction
        if ($member && $coupon->member_id && $coupon->member_id !== $member->id) {
            throw new InvalidCouponStateException($coupon->status, 'redeem_wrong_member');
        }
    }

    private function generateCode(): string
    {
        $part1 = strtoupper(Str::random(4));
        $part2 = strtoupper(Str::random(4));

        return "EZCRM-{$part1}-{$part2}";
    }
}
