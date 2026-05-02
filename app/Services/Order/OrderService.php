<?php

namespace App\Services\Order;

use App\Exceptions\Coupon\CouponExpiredException;
use App\Exceptions\Coupon\InvalidCouponStateException;
use App\Exceptions\Order\InvalidOrderStateTransitionException;
use App\Exceptions\Order\RefundAmountExceedsPaidException;
use App\Models\Coupon;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderSettings;
use App\Models\Refund;
use App\Models\User;
use App\Services\Coupon\CouponService;
use App\Services\Points\PointService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single entry point for all Order state transitions.
 *
 * NEVER write to orders.status / money columns directly — always go through
 * this Service so order_status_histories is recorded and money totals stay
 * consistent inside one DB transaction.
 *
 * State machine (per ORDER_INTEGRATION_PLAN §4):
 *   pending  → paid             (T1: markPaid)
 *   pending  → cancelled        (T2: cancel)
 *   paid     → shipped          (T3: ship)
 *   paid     → cancelled        (T4: cancel + coupon rollback)
 *   shipped  → completed        (T5: complete + earn points)
 *   paid     → completed        (T8: complete, skip shipped)
 *   completed → refunded        (T6a: refund full)
 *   completed → partial_refunded(T6b: refund partial)
 *   partial_refunded → refunded (T6d: auto when refund_amount = paid_amount)
 *   partial_refunded → partial_refunded (T6c: additional partial)
 *   shipped  → cancelled        (T7: admin only)
 */
class OrderService
{
    public function __construct(
        private OrderNumberGenerator $generator,
        private PointService $pointService,
        private CouponService $couponService,
    ) {}

    // ── create ────────────────────────────────────────────────────────────

    /**
     * Create a new order atomically.
     *
     * Coupon priority engine:
     *  1. Resolve coupons by code, sorted by priority ASC, then category
     *  2. Enforce one coupon per category
     *  3. Apply discounts in order; stop if paid_amount would drop below min_charge_amount
     *  4. Coupons are NOT redeemed yet — that happens at markPaid (T1)
     *
     * Idempotency: same Idempotency-Key returns the original order unchanged.
     *
     * @param array{
     *   items: array<array{product_sku:string,product_name:string,unit_price:int,quantity:int,product_meta?:array}>,
     *   shipping_address: array,
     *   billing_address?: array,
     *   coupon_codes?: string[],
     * } $data
     */
    public function create(Member $member, array $data, string $idempotencyKey): Order
    {
        // Fast-fail idempotency check outside transaction
        if ($existing = Order::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($member, $data, $idempotencyKey) {
            // Resolve & validate coupons before computing totals
            $couponCodes = $data['coupon_codes'] ?? [];
            $appliedCoupons = $this->resolveCoupons($couponCodes, $member);

            // Compute subtotal from items
            $subtotal = collect($data['items'])->sum(
                fn ($item) => (int) $item['unit_price'] * (int) $item['quantity']
            );

            // Apply coupon discounts (priority engine, category limit)
            [$discountTotal, $couponPlan] = $this->applyCouponPriority(
                $appliedCoupons,
                $subtotal,
            );

            $paidAmount = $subtotal - $discountTotal;

            // Enforce minimum charge
            $settings = OrderSettings::current();
            if ($paidAmount < $settings->min_charge_amount && $paidAmount > 0) {
                $paidAmount = $settings->min_charge_amount;
            }

            // Generate order number
            $orderNo = $this->generator->next();

            // Insert order header
            try {
                $order = Order::create([
                    'order_no' => $orderNo,
                    'member_id' => $member->id,
                    'status' => Order::STATUS_PENDING,
                    'subtotal' => $subtotal,
                    'discount_total' => $discountTotal,
                    'paid_amount' => $paidAmount,
                    'refund_amount' => 0,
                    'points_earned' => 0,
                    'points_refunded' => 0,
                    'idempotency_key' => $idempotencyKey,
                    'created_by_actor_type' => Order::ACTOR_MEMBER,
                ]);
            } catch (QueryException $e) {
                // Idempotency TOCTOU: two concurrent requests with same key
                if (str_contains($e->getMessage(), '23000')) {
                    return Order::where('idempotency_key', $idempotencyKey)->firstOrFail();
                }
                throw $e;
            }

            // Insert line items (snapshot)
            foreach ($data['items'] as $item) {
                $order->items()->create([
                    'product_sku' => $item['product_sku'],
                    'product_name' => $item['product_name'],
                    'unit_price' => (int) $item['unit_price'],
                    'quantity' => (int) $item['quantity'],
                    'subtotal' => (int) $item['unit_price'] * (int) $item['quantity'],
                    'product_meta' => $item['product_meta'] ?? null,
                ]);
            }

            // Insert shipping address (snapshot)
            $order->addresses()->create(array_merge(
                ['type' => 'shipping'],
                $data['shipping_address'],
            ));

            // Insert billing address if provided, else copy from shipping
            $billing = $data['billing_address'] ?? $data['shipping_address'];
            $order->addresses()->create(array_merge(
                ['type' => 'billing'],
                $billing,
            ));

            // Attach coupons to order (snapshot discount_applied, apply_order)
            foreach ($couponPlan as $idx => $entry) {
                /** @var Coupon $coupon */
                ['coupon' => $coupon, 'discount' => $discount] = $entry;
                $order->coupons()->attach($coupon->id, [
                    'discount_applied' => $discount,
                    'apply_order' => $idx,
                ]);
            }

            // Record initial status history (null → pending)
            $this->recordTransition($order, null, Order::STATUS_PENDING, null);

            return $order->load(['items', 'addresses', 'coupons']);
        });
    }

    // ── Coupon priority engine ─────────────────────────────────────────────

    /**
     * Resolve coupon codes to Coupon models for this member.
     * Throws CouponExpiredException / InvalidCouponStateException on invalid codes.
     *
     * @return Collection<int, Coupon>
     */
    private function resolveCoupons(array $codes, Member $member): Collection
    {
        if (empty($codes)) {
            return collect();
        }

        return collect($codes)->map(function (string $code) use ($member) {
            return $this->couponService->verify(strtoupper($code), $member);
        });
    }

    /**
     * Apply coupons in priority order (ASC), enforcing one-per-category limit.
     * Returns [total_discount, plan] where plan is an array of {coupon, discount}.
     *
     * Stops applying if the next coupon would push paid_amount below min_charge_amount.
     *
     * @param  Collection<int, Coupon>  $coupons
     * @return array{int, array<int, array{coupon: Coupon, discount: int}>}
     */
    private function applyCouponPriority(Collection $coupons, int $subtotal): array
    {
        if ($coupons->isEmpty()) {
            return [0, []];
        }

        $settings = OrderSettings::current();
        $remaining = $subtotal;
        $totalDiscount = 0;
        $plan = [];
        $usedCategories = [];

        // Sort by priority ASC (lower number = applied first)
        $sorted = $coupons->sortBy(fn (Coupon $c) => [$c->priority, $c->id]);

        foreach ($sorted as $coupon) {
            $category = $coupon->category ?? 'discount';

            // One coupon per category rule
            if (in_array($category, $usedCategories, true)) {
                continue;
            }

            $discount = $this->computeCouponDiscount($coupon, $remaining);

            // Don't apply if it would push below min_charge_amount
            $afterDiscount = $remaining - $discount;
            if ($afterDiscount < $settings->min_charge_amount && $afterDiscount > 0) {
                // Cap discount so paid_amount = min_charge_amount
                $discount = $remaining - $settings->min_charge_amount;
            }

            if ($discount <= 0) {
                continue;
            }

            $plan[] = ['coupon' => $coupon, 'discount' => $discount];
            $usedCategories[] = $category;
            $remaining -= $discount;
            $totalDiscount += $discount;
        }

        return [$totalDiscount, $plan];
    }

    private function computeCouponDiscount(Coupon $coupon, int $currentAmount): int
    {
        return match ($coupon->batch->type) {
            'discount_amount' => min((int) $coupon->batch->value, $currentAmount),
            'discount_percent' => (int) floor($currentAmount * $coupon->batch->value / 100),
            default => 0,  // 'points' type coupons don't reduce order total
        };
    }

    // ── Status transitions ─────────────────────────────────────────────────

    /**
     * T1: pending → paid
     * Redeems all attached coupons using order-scoped idempotency keys.
     */
    public function markPaid(Order $order, string $paymentMethod, ?string $ecpayTradeNo = null): Order
    {
        return DB::transaction(function () use ($order, $paymentMethod, $ecpayTradeNo) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== Order::STATUS_PENDING) {
                throw new InvalidOrderStateTransitionException($locked->status, 'markPaid');
            }

            // Redeem all attached coupons atomically.
            // Double-redemption protection: CouponService::redeem uses lockForUpdate
            // + status='created' guard, so if markPaid is replayed the second call
            // throws InvalidCouponStateException which we catch and ignore (idempotent).
            foreach ($locked->coupons as $coupon) {
                try {
                    $this->couponService->redeem($coupon->code, $locked->member);
                } catch (InvalidCouponStateException) {
                    // Already redeemed (e.g. webhook replay) — idempotent, skip
                }
            }

            $locked->update([
                'status' => Order::STATUS_PAID,
                'payment_method' => $paymentMethod,
                'ecpay_trade_no' => $ecpayTradeNo,
                'paid_at' => now(),
            ]);

            $this->recordTransition($locked, Order::STATUS_PENDING, Order::STATUS_PAID, null);

            return $locked->fresh();
        });
    }

    /**
     * T3: paid → shipped
     */
    public function ship(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== Order::STATUS_PAID) {
                throw new InvalidOrderStateTransitionException($locked->status, 'ship');
            }

            $locked->update([
                'status' => Order::STATUS_SHIPPED,
                'shipped_at' => now(),
            ]);

            $this->recordTransition($locked, Order::STATUS_PAID, Order::STATUS_SHIPPED, $actor);

            return $locked->fresh();
        });
    }

    /**
     * T5 + T8: shipped → completed (or paid → completed for digital goods)
     * Triggers PointService to earn points.
     */
    public function complete(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            if (! in_array($locked->status, [Order::STATUS_SHIPPED, Order::STATUS_PAID], true)) {
                throw new InvalidOrderStateTransitionException($locked->status, 'complete');
            }

            $fromStatus = $locked->status;
            $settings = OrderSettings::current();
            $pointsToEarn = (int) floor($locked->paid_amount * $settings->points_rate);

            if ($pointsToEarn > 0) {
                $this->pointService->adjust(
                    $locked->member,
                    $pointsToEarn,
                    "訂單 {$locked->order_no} 完成",
                    'earn',
                    "order:{$locked->id}:earn",
                    $locked,
                );
            }

            $locked->update([
                'status' => Order::STATUS_COMPLETED,
                'completed_at' => now(),
                'points_earned' => $pointsToEarn,
            ]);

            $this->recordTransition($locked, $fromStatus, Order::STATUS_COMPLETED, $actor);

            return $locked->fresh();
        });
    }

    /**
     * T2 / T4 / T7: cancel
     * T4 (paid → cancelled): rolls back coupon redemptions.
     */
    public function cancel(Order $order, User|Member|null $actor, string $reason = ''): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            $cancelable = [Order::STATUS_PENDING, Order::STATUS_PAID, Order::STATUS_SHIPPED];
            if (! in_array($locked->status, $cancelable, true)) {
                throw new InvalidOrderStateTransitionException($locked->status, 'cancel');
            }

            $fromStatus = $locked->status;

            // T4 / T7: reverse coupon redemptions if order was paid
            if (in_array($fromStatus, [Order::STATUS_PAID, Order::STATUS_SHIPPED], true)) {
                $adminActor = ($actor instanceof User) ? $actor : null;
                foreach ($locked->coupons()->wherePivot('discount_applied', '>', 0)->get() as $coupon) {
                    if ($coupon->isRedeemed() && $adminActor) {
                        try {
                            $this->couponService->cancel($coupon->code, $adminActor);
                        } catch (InvalidCouponStateException) {
                            // Already cancelled — idempotent, skip
                        }
                    }
                }
            }

            $locked->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            $actorUser = ($actor instanceof User) ? $actor : null;
            $this->recordTransition(
                $locked, $fromStatus, Order::STATUS_CANCELLED, $actorUser,
                $reason ?: null
            );

            return $locked->fresh();
        });
    }

    /**
     * T6a / T6b / T6c: refund (full or partial)
     * Reverses Points proportionally.
     */
    public function refund(Order $order, int $amount, string $reason, User $admin): Refund
    {
        return DB::transaction(function () use ($order, $amount, $reason, $admin) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            $refundableStatuses = [Order::STATUS_COMPLETED, Order::STATUS_PARTIAL_REFUNDED];
            if (! in_array($locked->status, $refundableStatuses, true)) {
                throw new InvalidOrderStateTransitionException($locked->status, 'refund');
            }

            $remaining = $locked->paid_amount - $locked->refund_amount;
            if ($amount > $remaining) {
                throw new RefundAmountExceedsPaidException($amount, $remaining);
            }

            $isFull = ($amount === $remaining);
            $proportion = $locked->paid_amount > 0 ? $amount / $locked->paid_amount : 0;
            $pointsToRefund = (int) floor($locked->points_earned * $proportion);

            // Reverse Points
            $pointTx = null;
            if ($pointsToRefund > 0) {
                $pointTx = $this->pointService->adjust(
                    $locked->member,
                    -$pointsToRefund,
                    "訂單 {$locked->order_no} ".($isFull ? '全額' : '部分').'退款',
                    'refund',
                    "order:{$locked->id}:refund:".(string) Str::uuid(),
                    $locked,
                );
            }

            $refund = Refund::create([
                'order_id' => $locked->id,
                'amount' => $amount,
                'type' => $isFull ? 'full' : 'partial',
                'reason' => $reason,
                'processed_by' => $admin->id,
                'processed_at' => now(),
                'point_transaction_id' => $pointTx?->id,
            ]);

            $newRefundTotal = $locked->refund_amount + $amount;
            $newStatus = $isFull ? Order::STATUS_REFUNDED : Order::STATUS_PARTIAL_REFUNDED;
            $prevStatus = $locked->status;

            $locked->update([
                'status' => $newStatus,
                'refund_amount' => $newRefundTotal,
                'points_refunded' => $locked->points_refunded + $pointsToRefund,
            ]);

            $this->recordTransition($locked, $prevStatus, $newStatus, $admin, $reason);

            return $refund;
        });
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function recordTransition(
        Order $order,
        ?string $from,
        string $to,
        ?User $actor,
        ?string $reason = null,
    ): void {
        $order->statusHistories()->create([
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'actor_id' => $actor?->id,
            'actor_type' => $actor ? Order::ACTOR_USER : Order::ACTOR_SYSTEM,
        ]);
    }
}
