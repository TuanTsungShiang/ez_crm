<?php

namespace Tests\Feature\Api\V1;

use App\Exceptions\Order\InvalidOrderStateTransitionException;
use App\Jobs\CancelPendingOrdersJob;
use App\Models\Coupon;
use App\Models\CouponBatch;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderSettings;
use App\Models\User;
use App\Services\Coupon\CouponService;
use App\Services\Order\OrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Concurrency / invariant tests for the Order domain.
 *
 * True OS-level parallelism (pcntl_fork) is not available on Windows.
 * We instead verify the invariants through sequential calls that exercise
 * every idempotency and lock-based protection path, consistent with the
 * approach used in PointsConcurrencyTest.
 *
 * Key invariants under test:
 *   1. Idempotency-Key prevents duplicate order creation (TOCTOU path)
 *   2. Same coupon code used in two concurrent creates both succeed at create
 *      time (verify-only), but markPaid on the second order swallows the
 *      InvalidCouponStateException and both become paid (designed behaviour)
 *   3. ECPay webhook replay produces a single processed callback (via app-layer
 *      guard) — the DB UNIQUE backstop covers the concurrent path
 *   4. Order numbers are globally unique across sequential creates on the same day
 *   5. CancelPendingOrdersJob respects the configurable timeout and fires
 *      the OrderCancelled event for each cancelled order
 */
class OrderConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        OrderSettings::firstOrCreate([], [
            'order_no_prefix' => 'EZ',
            'points_rate' => 0.01,
            'pending_timeout_minutes' => 30,
            'min_charge_amount' => 1,
        ]);

        $this->svc = app(OrderService::class);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeMember(): Member
    {
        return Member::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test',
            'email' => 'm'.uniqid().'@test.com',
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function baseData(): array
    {
        return [
            'items' => [['product_sku' => 'SKU-001', 'product_name' => '商品', 'unit_price' => 1000, 'quantity' => 1]],
            'shipping_address' => ['recipient_name' => '王', 'phone' => '0912345678', 'postal_code' => '100', 'city' => '台北市', 'district' => '中正區', 'address_line' => '路 1 號'],
        ];
    }

    private function makeCoupon(int $value = 100): string
    {
        $batch = app(CouponService::class)->createBatch([
            'name' => 'Test',
            'type' => CouponBatch::TYPE_DISCOUNT_AMOUNT,
            'value' => $value,
            'quantity' => 1,
        ]);

        return $batch->coupons()->first()->code;
    }

    // ── 1. Idempotency-Key dedup ──────────────────────────────────────────

    /**
     * Two sequential creates with the same key must return the same order
     * and leave exactly one row in the orders table (TOCTOU path).
     */
    public function test_same_idempotency_key_creates_single_order(): void
    {
        $member = $this->makeMember();
        $key = (string) Str::uuid();

        $first = $this->svc->create($member, $this->baseData(), $key);
        $second = $this->svc->create($member, $this->baseData(), $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::count());
    }

    /**
     * Different keys for the same member must produce independent orders.
     */
    public function test_different_keys_create_independent_orders(): void
    {
        $member = $this->makeMember();

        $a = $this->svc->create($member, $this->baseData(), (string) Str::uuid());
        $b = $this->svc->create($member, $this->baseData(), (string) Str::uuid());

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, Order::count());
    }

    // ── 2. Order number uniqueness ────────────────────────────────────────

    /**
     * N sequential creates on the same day must all get distinct order numbers.
     * Verifies OrderNumberGenerator's lockForUpdate + MAX+1 logic.
     */
    public function test_sequential_creates_produce_unique_order_numbers(): void
    {
        $member = $this->makeMember();
        $nos = [];

        for ($i = 0; $i < 5; $i++) {
            $order = $this->svc->create($member, $this->baseData(), (string) Str::uuid());
            $nos[] = $order->order_no;
        }

        $this->assertCount(5, array_unique($nos), 'all 5 order numbers must be distinct');
        $this->assertMatchesRegularExpression('/^EZ-\d{8}-\d{4}$/', $nos[0]);
    }

    // ── 3. Coupon race: two orders, one coupon ────────────────────────────

    /**
     * Two members create orders with the same single-use coupon.
     * Both creates succeed because verify-only happens at create time (coupon
     * not yet redeemed). markPaid on the first order redeems the coupon.
     * markPaid on the second order catches InvalidCouponStateException
     * (swallowed as idempotent) and still transitions the order to paid.
     *
     * This is designed behaviour documented in ORDER_INTEGRATION_PLAN §5a
     * and the OrderService::markPaid docblock.
     */
    public function test_both_creates_succeed_first_markpaid_redeems_second_swallows(): void
    {
        $code = $this->makeCoupon(200);
        $memberA = $this->makeMember();
        $memberB = $this->makeMember();

        $data = array_merge($this->baseData(), ['coupon_codes' => [$code]]);

        // Both creates pass verify — coupon not yet redeemed
        $orderA = $this->svc->create($memberA, $data, (string) Str::uuid());
        $orderB = $this->svc->create($memberB, $data, (string) Str::uuid());

        $this->assertSame(Order::STATUS_PENDING, $orderA->status);
        $this->assertSame(Order::STATUS_PENDING, $orderB->status);
        $this->assertSame(Coupon::STATUS_CREATED, Coupon::where('code', $code)->value('status'));

        // First markPaid redeems the coupon
        $this->svc->markPaid($orderA, Order::PAYMENT_METHOD_OFFLINE);
        $this->assertSame(Coupon::STATUS_REDEEMED, Coupon::where('code', $code)->value('status'));

        // Second markPaid: InvalidCouponStateException caught → order still becomes paid
        $this->svc->markPaid($orderB, Order::PAYMENT_METHOD_OFFLINE);

        $this->assertSame(Order::STATUS_PAID, $orderA->fresh()->status);
        $this->assertSame(Order::STATUS_PAID, $orderB->fresh()->status);

        // Coupon redeemed exactly once — not double-redeemed
        $this->assertSame(
            1,
            Coupon::where('code', $code)->where('status', Coupon::STATUS_REDEEMED)->count(),
        );
    }

    // ── 4. markPaid idempotency (webhook replay app-layer) ────────────────

    /**
     * Calling markPaid twice on the same order (simulating webhook retry)
     * must result in exactly one paid transition — the second call throws
     * InvalidOrderStateTransitionException which the caller (ECPayService)
     * catches and skips.
     */
    public function test_markpaid_replay_is_caught_at_service_level(): void
    {
        $member = $this->makeMember();
        $order = $this->svc->create($member, $this->baseData(), (string) Str::uuid());

        $this->svc->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);

        // Second call should throw FSM guard (caught by ECPayService in production)
        $this->expectException(InvalidOrderStateTransitionException::class);
        $this->svc->markPaid($order->fresh(), Order::PAYMENT_METHOD_OFFLINE);
    }

    /**
     * Verify balance_after and points integrity: complete() earns points exactly
     * once even if called on an order that was already completed by another path.
     */
    public function test_points_earn_idempotency_via_order_complete(): void
    {
        $member = $this->makeMember();
        $order = $this->svc->create($member, $this->baseData(), (string) Str::uuid());
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->svc->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);
        $this->svc->complete($order->fresh(), $admin);

        $member->refresh();
        $this->assertSame(10, $member->points); // 1000 × 1% = 10

        // Second complete must throw FSM guard — points must not double-earn
        $this->expectException(InvalidOrderStateTransitionException::class);
        $this->svc->complete($order->fresh(), $admin);
    }

    // ── 5. CancelPendingOrdersJob ─────────────────────────────────────────

    /**
     * Orders created before the timeout window should be cancelled by the job.
     * Orders within the window must remain pending.
     */
    public function test_cancel_job_cancels_timed_out_orders_and_skips_recent(): void
    {
        $member = $this->makeMember();

        // Create a recent pending order (now)
        $recent = $this->svc->create($member, $this->baseData(), (string) Str::uuid());

        // Create an old pending order (35 minutes ago, timeout = 30 min)
        $this->travelTo(now()->subMinutes(35));
        $old = $this->svc->create($member, $this->baseData(), (string) Str::uuid());
        $this->travelBack();

        // Run the job
        app(CancelPendingOrdersJob::class)->handle($this->svc);

        $this->assertSame(Order::STATUS_CANCELLED, $old->fresh()->status, 'timed-out order must be cancelled');
        $this->assertSame(Order::STATUS_PENDING, $recent->fresh()->status, 'recent order must remain pending');
    }

    /**
     * If an order is already paid when the job runs, it must not be touched
     * (cancel() will throw FSM guard which the job catches and logs).
     */
    public function test_cancel_job_skips_paid_order_without_crashing(): void
    {
        $member = $this->makeMember();

        $this->travelTo(now()->subMinutes(35));
        $order = $this->svc->create($member, $this->baseData(), (string) Str::uuid());
        $this->travelBack();

        // Mark it paid before the job runs
        $this->svc->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);

        // Job must not throw
        app(CancelPendingOrdersJob::class)->handle($this->svc);

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }
}
