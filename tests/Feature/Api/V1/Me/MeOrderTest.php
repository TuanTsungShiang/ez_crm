<?php

namespace Tests\Feature\Api\V1\Me;

use App\Enums\ApiCode;
use App\Models\CouponBatch;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderSettings;
use App\Services\Coupon\CouponService;
use App\Services\Order\OrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeOrderTest extends TestCase
{
    use RefreshDatabase;

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
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeMember(array $overrides = []): Member
    {
        return Member::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Member',
            'email' => 'member'.uniqid().'@example.com',
            'status' => Member::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ], $overrides));
    }

    private function actingAsMember(Member $member): static
    {
        Sanctum::actingAs($member, [], 'member');

        return $this;
    }

    private function baseItems(): array
    {
        return [
            [
                'product_sku' => 'SKU-001',
                'product_name' => '商品 A',
                'unit_price' => 1000,
                'quantity' => 2,
            ],
        ];
    }

    private function baseShippingAddress(): array
    {
        return [
            'recipient_name' => '王小明',
            'phone' => '0912345678',
            'postal_code' => '100',
            'city' => '台北市',
            'district' => '中正區',
            'address_line' => '測試路 1 號',
        ];
    }

    private function makeOrder(Member $member): Order
    {
        return app(OrderService::class)->create(
            $member,
            [
                'items' => $this->baseItems(),
                'shipping_address' => $this->baseShippingAddress(),
            ],
            (string) Str::uuid(),
        );
    }

    private function makeCouponCode(array $opts = []): string
    {
        $batch = app(CouponService::class)->createBatch(array_merge([
            'name' => 'Test Coupon',
            'type' => CouponBatch::TYPE_DISCOUNT_AMOUNT,
            'value' => 300,
            'quantity' => 5,
        ], $opts));

        return $batch->coupons()->first()->code;
    }

    // ── POST /me/orders (store) ───────────────────────────────────────────

    public function test_member_can_create_order_and_receives_ecpay_form(): void
    {
        $member = $this->makeMember();
        $this->actingAsMember($member);

        $response = $this->postJson('/api/v1/me/orders', [
            'items' => $this->baseItems(),
            'shipping_address' => $this->baseShippingAddress(),
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $response->assertCreated()
            ->assertJsonPath('code', ApiCode::CREATED)
            ->assertJsonPath('data.order.status', Order::STATUS_PENDING)
            ->assertJsonPath('data.order.paid_amount', 2000)
            ->assertJsonStructure(['data' => ['order' => ['order_no', 'status', 'paid_amount'], 'ecpay_payment_html']]);

        $this->assertStringContainsString('<form', $response->json('data.ecpay_payment_html'));
        $this->assertSame(1, Order::count());
    }

    public function test_create_order_idempotency_same_key_returns_same_order(): void
    {
        $member = $this->makeMember();
        $this->actingAsMember($member);

        $key = (string) Str::uuid();

        $first = $this->postJson('/api/v1/me/orders', [
            'items' => $this->baseItems(),
            'shipping_address' => $this->baseShippingAddress(),
        ], ['Idempotency-Key' => $key]);

        $second = $this->postJson('/api/v1/me/orders', [
            'items' => $this->baseItems(),
            'shipping_address' => $this->baseShippingAddress(),
        ], ['Idempotency-Key' => $key]);

        $this->assertSame(
            $first->json('data.order.order_no'),
            $second->json('data.order.order_no'),
        );
        $this->assertSame(1, Order::count());
    }

    public function test_create_order_missing_idempotency_key_returns_422(): void
    {
        $member = $this->makeMember();
        $this->actingAsMember($member);

        $this->postJson('/api/v1/me/orders', [
            'items' => $this->baseItems(),
            'shipping_address' => $this->baseShippingAddress(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', ApiCode::MISSING_FIELD);
    }

    public function test_create_order_validates_items_required(): void
    {
        $member = $this->makeMember();
        $this->actingAsMember($member);

        $this->postJson('/api/v1/me/orders', [
            'shipping_address' => $this->baseShippingAddress(),
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422);
    }

    public function test_create_order_validates_shipping_address_required(): void
    {
        $member = $this->makeMember();
        $this->actingAsMember($member);

        $this->postJson('/api/v1/me/orders', [
            'items' => $this->baseItems(),
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422);
    }

    public function test_create_order_with_coupon_applies_discount(): void
    {
        $member = $this->makeMember();
        $this->actingAsMember($member);
        $code = $this->makeCouponCode(['value' => 300]);

        $response = $this->postJson('/api/v1/me/orders', [
            'items' => $this->baseItems(),
            'shipping_address' => $this->baseShippingAddress(),
            'coupon_codes' => [$code],
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $response->assertCreated()
            ->assertJsonPath('data.order.discount_total', 300)
            ->assertJsonPath('data.order.paid_amount', 1700);
    }

    public function test_create_order_requires_auth(): void
    {
        $this->postJson('/api/v1/me/orders', [
            'items' => $this->baseItems(),
            'shipping_address' => $this->baseShippingAddress(),
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnauthorized();
    }

    // ── GET /me/orders (index) ────────────────────────────────────────────

    public function test_member_can_list_own_orders(): void
    {
        $member = $this->makeMember();
        $this->makeOrder($member);
        $this->makeOrder($member);

        $this->actingAsMember($member);

        $response = $this->getJson('/api/v1/me/orders');

        $response->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonCount(2, 'data.data');
    }

    public function test_member_cannot_see_other_members_orders(): void
    {
        $memberA = $this->makeMember();
        $memberB = $this->makeMember();

        $this->makeOrder($memberA);
        $this->makeOrder($memberA);

        $this->actingAsMember($memberB);

        $response = $this->getJson('/api/v1/me/orders');
        $response->assertOk()
            ->assertJsonPath('data.meta.total', 0);
    }

    public function test_list_orders_requires_auth(): void
    {
        $this->getJson('/api/v1/me/orders')->assertUnauthorized();
    }

    // ── GET /me/orders/{order_no} (show) ──────────────────────────────────

    public function test_member_can_view_own_order_detail(): void
    {
        $member = $this->makeMember();
        $order = $this->makeOrder($member);

        $this->actingAsMember($member);

        $response = $this->getJson("/api/v1/me/orders/{$order->order_no}");

        $response->assertOk()
            ->assertJsonPath('data.order_no', $order->order_no)
            ->assertJsonPath('data.status', Order::STATUS_PENDING)
            ->assertJsonStructure(['data' => ['items', 'shipping_address']]);
    }

    public function test_member_cannot_view_other_members_order(): void
    {
        $memberA = $this->makeMember();
        $memberB = $this->makeMember();
        $order = $this->makeOrder($memberA);

        $this->actingAsMember($memberB);

        $this->getJson("/api/v1/me/orders/{$order->order_no}")
            ->assertNotFound();
    }

    // ── POST /me/orders/{order_no}/cancel ─────────────────────────────────

    public function test_member_can_cancel_pending_order(): void
    {
        $member = $this->makeMember();
        $order = $this->makeOrder($member);

        $this->actingAsMember($member);

        $response = $this->postJson("/api/v1/me/orders/{$order->order_no}/cancel");

        $response->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_CANCELLED);

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_cancel_paid_order_returns_d001(): void
    {
        $member = $this->makeMember();
        $order = $this->makeOrder($member);
        app(OrderService::class)->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);

        $this->actingAsMember($member);

        $this->postJson("/api/v1/me/orders/{$order->order_no}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('code', ApiCode::INVALID_ORDER_STATE_TRANSITION);
    }

    public function test_cancel_other_members_order_returns_404(): void
    {
        $memberA = $this->makeMember();
        $memberB = $this->makeMember();
        $order = $this->makeOrder($memberA);

        $this->actingAsMember($memberB);

        $this->postJson("/api/v1/me/orders/{$order->order_no}/cancel")
            ->assertNotFound();
    }

    // ── POST /me/orders/{order_no}/repay ──────────────────────────────────

    public function test_member_can_repay_pending_order_and_receives_ecpay_form(): void
    {
        $member = $this->makeMember();
        $order = $this->makeOrder($member);

        $this->actingAsMember($member);

        $response = $this->postJson("/api/v1/me/orders/{$order->order_no}/repay");

        $response->assertOk()
            ->assertJsonPath('data.order.order_no', $order->order_no)
            ->assertJsonStructure(['data' => ['ecpay_payment_html']]);

        $this->assertStringContainsString('<form', $response->json('data.ecpay_payment_html'));
    }

    public function test_repay_paid_order_returns_d001(): void
    {
        $member = $this->makeMember();
        $order = $this->makeOrder($member);
        app(OrderService::class)->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);

        $this->actingAsMember($member);

        $this->postJson("/api/v1/me/orders/{$order->order_no}/repay")
            ->assertStatus(422)
            ->assertJsonPath('code', ApiCode::INVALID_ORDER_STATE_TRANSITION);
    }

    public function test_repay_other_members_order_returns_404(): void
    {
        $memberA = $this->makeMember();
        $memberB = $this->makeMember();
        $order = $this->makeOrder($memberA);

        $this->actingAsMember($memberB);

        $this->postJson("/api/v1/me/orders/{$order->order_no}/repay")
            ->assertNotFound();
    }
}
