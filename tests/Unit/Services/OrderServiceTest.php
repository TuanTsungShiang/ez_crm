<?php

namespace Tests\Unit\Services;

use App\Exceptions\Order\InvalidOrderStateTransitionException;
use App\Exceptions\Order\RefundAmountExceedsPaidException;
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

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Ensure order_settings seed row exists
        OrderSettings::firstOrCreate([], [
            'order_no_prefix' => 'EZ',
            'points_rate' => 0.01,
            'pending_timeout_minutes' => 30,
            'min_charge_amount' => 1,
        ]);

        $this->service = app(OrderService::class);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeMember(): Member
    {
        return Member::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Member',
            'email' => 'member'.uniqid().'@example.com',
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function baseOrderData(array $overrides = []): array
    {
        return array_merge([
            'items' => [
                [
                    'product_sku' => 'SKU-001',
                    'product_name' => '商品 A',
                    'unit_price' => 1000,
                    'quantity' => 2,
                ],
            ],
            'shipping_address' => [
                'recipient_name' => '王小明',
                'phone' => '0912345678',
                'postal_code' => '100',
                'city' => '台北市',
                'district' => '中正區',
                'address_line' => '測試路 1 號',
            ],
        ], $overrides);
    }

    private function makeKey(): string
    {
        return (string) Str::uuid();
    }

    private function makeCouponBatch(array $opts = []): CouponBatch
    {
        return app(CouponService::class)->createBatch(array_merge([
            'name' => 'Test Coupon',
            'type' => CouponBatch::TYPE_DISCOUNT_AMOUNT,
            'value' => 100,
            'quantity' => 5,
        ], $opts));
    }

    private function firstCode(CouponBatch $batch): string
    {
        return $batch->coupons()->first()->code;
    }

    // ── create ────────────────────────────────────────────────────────────

    public function test_create_returns_pending_order_with_correct_totals(): void
    {
        $member = $this->makeMember();

        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());

        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertSame(2000, $order->subtotal);     // 1000 × 2
        $this->assertSame(0, $order->discount_total);
        $this->assertSame(2000, $order->paid_amount);
        $this->assertMatchesRegularExpression('/^EZ-\d{8}-\d{4}$/', $order->order_no);
    }

    public function test_create_saves_line_items_and_addresses(): void
    {
        $member = $this->makeMember();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());

        $this->assertSame(1, $order->items->count());
        $this->assertSame('SKU-001', $order->items->first()->product_sku);
        $this->assertSame(2, $order->addresses->count());  // shipping + billing
    }

    public function test_create_writes_status_history(): void
    {
        $member = $this->makeMember();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());

        $history = $order->statusHistories()->first();
        $this->assertNull($history->from_status);
        $this->assertSame(Order::STATUS_PENDING, $history->to_status);
    }

    public function test_create_with_single_discount_coupon(): void
    {
        $member = $this->makeMember();
        $batch = $this->makeCouponBatch(['type' => 'discount_amount', 'value' => 300]);
        $code = $this->firstCode($batch);

        $order = $this->service->create(
            $member,
            $this->baseOrderData(['coupon_codes' => [$code]]),
            $this->makeKey(),
        );

        $this->assertSame(300, $order->discount_total);
        $this->assertSame(1700, $order->paid_amount);
        $this->assertSame(1, $order->coupons->count());

        // Coupon should NOT be redeemed yet (only at markPaid)
        $this->assertSame(
            Coupon::STATUS_CREATED,
            Coupon::where('code', $code)->value('status'),
        );
    }

    public function test_create_with_multiple_coupons_respects_priority(): void
    {
        $member = $this->makeMember();

        // Two discount coupons — priority 50 applied first, then 100
        $batch1 = $this->makeCouponBatch(['type' => 'discount_amount', 'value' => 200]);
        $batch2 = $this->makeCouponBatch(['type' => 'discount_amount', 'value' => 100]);
        $code1 = $this->firstCode($batch1);
        $code2 = $this->firstCode($batch2);

        // Set priority via DB so we can control order
        Coupon::where('code', $code1)->update(['priority' => 50]);
        Coupon::where('code', $code2)->update(['priority' => 100]);

        $order = $this->service->create(
            $member,
            $this->baseOrderData(['coupon_codes' => [$code2, $code1]]),  // reversed order
            $this->makeKey(),
        );

        // Both are same category 'discount' → only ONE should apply (one-per-category)
        $this->assertSame(1, $order->coupons->count());

        // Priority 50 (batch1, 200 off) should win
        $this->assertSame(200, $order->discount_total);
    }

    public function test_create_idempotency_replay_returns_same_order(): void
    {
        $member = $this->makeMember();
        $key = $this->makeKey();

        $first = $this->service->create($member, $this->baseOrderData(), $key);
        $second = $this->service->create($member, $this->baseOrderData(), $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::count());
    }

    // ── markPaid ──────────────────────────────────────────────────────────

    public function test_mark_paid_transitions_pending_to_paid(): void
    {
        $member = $this->makeMember();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());

        $paid = $this->service->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);

        $this->assertSame(Order::STATUS_PAID, $paid->status);
        $this->assertNotNull($paid->paid_at);
    }

    public function test_mark_paid_redeems_attached_coupons(): void
    {
        $member = $this->makeMember();
        $batch = $this->makeCouponBatch();
        $code = $this->firstCode($batch);

        $order = $this->service->create(
            $member,
            $this->baseOrderData(['coupon_codes' => [$code]]),
            $this->makeKey(),
        );

        $this->service->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);

        $this->assertSame(Coupon::STATUS_REDEEMED, Coupon::where('code', $code)->value('status'));
    }

    public function test_mark_paid_throws_for_non_pending_order(): void
    {
        $member = $this->makeMember();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());
        $this->service->cancel($order, null);

        $this->expectException(InvalidOrderStateTransitionException::class);
        $this->service->markPaid($order->fresh(), Order::PAYMENT_METHOD_OFFLINE);
    }

    // ── ship ──────────────────────────────────────────────────────────────

    public function test_ship_transitions_paid_to_shipped(): void
    {
        $member = $this->makeMember();
        $admin = $this->makeAdmin();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());
        $this->service->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);

        $shipped = $this->service->ship($order->fresh(), $admin);

        $this->assertSame(Order::STATUS_SHIPPED, $shipped->status);
        $this->assertNotNull($shipped->shipped_at);
    }

    public function test_ship_throws_for_non_paid_order(): void
    {
        $member = $this->makeMember();
        $admin = $this->makeAdmin();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());

        $this->expectException(InvalidOrderStateTransitionException::class);
        $this->service->ship($order, $admin);
    }

    // ── complete ──────────────────────────────────────────────────────────

    public function test_complete_shipped_to_completed_and_earns_points(): void
    {
        $member = $this->makeMember();
        $admin = $this->makeAdmin();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());
        $this->service->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);
        $this->service->ship($order->fresh(), $admin);

        $completed = $this->service->complete($order->fresh(), $admin);

        $this->assertSame(Order::STATUS_COMPLETED, $completed->status);
        // 2000 × 1% = 20 points
        $this->assertSame(20, $completed->points_earned);
        $member->refresh();
        $this->assertSame(20, $member->points);
    }

    public function test_complete_paid_to_completed_skip_shipped_t8(): void
    {
        $member = $this->makeMember();
        $admin = $this->makeAdmin();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());
        $this->service->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);

        $completed = $this->service->complete($order->fresh(), $admin);

        $this->assertSame(Order::STATUS_COMPLETED, $completed->status);
    }

    public function test_complete_points_idempotency_does_not_double_earn(): void
    {
        $member = $this->makeMember();
        $admin = $this->makeAdmin();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());
        $this->service->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);
        $this->service->complete($order->fresh(), $admin);

        // Second complete call on already-completed order should throw FSM
        $this->expectException(InvalidOrderStateTransitionException::class);
        $this->service->complete($order->fresh(), $admin);
    }

    // ── cancel ────────────────────────────────────────────────────────────

    public function test_cancel_pending_to_cancelled(): void
    {
        $member = $this->makeMember();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());

        $cancelled = $this->service->cancel($order, null);

        $this->assertSame(Order::STATUS_CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    public function test_cancel_throws_for_completed_order(): void
    {
        $member = $this->makeMember();
        $admin = $this->makeAdmin();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());
        $this->service->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);
        $this->service->complete($order->fresh(), $admin);

        $this->expectException(InvalidOrderStateTransitionException::class);
        $this->service->cancel($order->fresh(), $admin);
    }

    // ── refund ────────────────────────────────────────────────────────────

    public function test_refund_completed_to_refunded_and_reverses_points(): void
    {
        $member = $this->makeMember();
        $admin = $this->makeAdmin();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());
        $this->service->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);
        $this->service->complete($order->fresh(), $admin);

        $order->refresh();
        $refund = $this->service->refund($order, $order->paid_amount, '全額退款', $admin);

        $order->refresh();
        $this->assertSame(Order::STATUS_REFUNDED, $order->status);
        $this->assertSame($order->paid_amount, $order->refund_amount);

        $member->refresh();
        $this->assertSame(0, $member->points, 'all points must be reversed');
    }

    public function test_partial_refund_transitions_to_partial_refunded(): void
    {
        $member = $this->makeMember();
        $admin = $this->makeAdmin();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());
        $this->service->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);
        $this->service->complete($order->fresh(), $admin);

        $this->service->refund($order->fresh(), 500, '部分退款', $admin);

        $order->refresh();
        $this->assertSame(Order::STATUS_PARTIAL_REFUNDED, $order->status);
        $this->assertSame(500, $order->refund_amount);
    }

    public function test_refund_throws_when_amount_exceeds_paid(): void
    {
        $member = $this->makeMember();
        $admin = $this->makeAdmin();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());
        $this->service->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);
        $this->service->complete($order->fresh(), $admin);

        $this->expectException(RefundAmountExceedsPaidException::class);
        $this->service->refund($order->fresh(), 9999, '超額退款', $admin);
    }

    public function test_invalid_state_transition_throws_exception(): void
    {
        $member = $this->makeMember();
        $admin = $this->makeAdmin();
        $order = $this->service->create($member, $this->baseOrderData(), $this->makeKey());

        // pending → ship is invalid (must go pending → paid → shipped)
        $this->expectException(InvalidOrderStateTransitionException::class);
        $this->service->ship($order, $admin);
    }
}
