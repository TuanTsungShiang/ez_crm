<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiCode;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderSettings;
use App\Models\User;
use App\Services\Order\OrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderTest extends TestCase
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

    private function makeAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function makeViewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole('viewer');

        return $user;
    }

    private function makeCustomerSupport(): User
    {
        $user = User::factory()->create();
        $user->assignRole('customer_support');

        return $user;
    }

    private function makeMember(): Member
    {
        return Member::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Member',
            'email' => 'member'.uniqid().'@example.com',
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function makeOrder(Member $member): Order
    {
        return app(OrderService::class)->create(
            $member,
            [
                'items' => [['product_sku' => 'SKU-001', 'product_name' => '商品 A', 'unit_price' => 1000, 'quantity' => 2]],
                'shipping_address' => ['recipient_name' => '王小明', 'phone' => '0912345678', 'postal_code' => '100', 'city' => '台北市', 'district' => '中正區', 'address_line' => '測試路 1 號'],
            ],
            (string) Str::uuid(),
        );
    }

    private function baseOrderPayload(Member $member): array
    {
        return [
            'member_uuid' => $member->uuid,
            'items' => [['product_sku' => 'SKU-001', 'product_name' => '商品 A', 'unit_price' => 1000, 'quantity' => 2]],
            'shipping_address' => ['recipient_name' => '王小明', 'phone' => '0912345678', 'postal_code' => '100', 'city' => '台北市', 'district' => '中正區', 'address_line' => '測試路 1 號'],
        ];
    }

    // ── GET /admin/orders (index) ─────────────────────────────────────────

    public function test_admin_can_list_all_orders(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember();
        $this->makeOrder($member);
        $this->makeOrder($member);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/orders');

        $response->assertOk()
            ->assertJsonPath('data.meta.total', 2);
    }

    public function test_list_requires_order_view_any_permission(): void
    {
        $viewer = $this->makeViewer();
        // viewer has order.view_any
        $this->actingAs($viewer)
            ->getJson('/api/v1/admin/orders')
            ->assertOk();

        // user with no roles
        $nobody = User::factory()->create();
        $this->actingAs($nobody)
            ->getJson('/api/v1/admin/orders')
            ->assertForbidden();
    }

    public function test_can_filter_by_status(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember();
        $order = $this->makeOrder($member);
        app(OrderService::class)->cancel($order, $admin);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/orders?status=cancelled');

        $response->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    // ── POST /admin/orders (store) ────────────────────────────────────────

    public function test_admin_can_create_order_for_member(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember();

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/admin/orders', $this->baseOrderPayload($member), [
                'Idempotency-Key' => (string) Str::uuid(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.order.status', Order::STATUS_PENDING)
            ->assertJsonStructure(['data' => ['ecpay_payment_html']]);
    }

    public function test_admin_can_create_order_and_mark_paid_offline(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember();

        $payload = array_merge($this->baseOrderPayload($member), ['mark_as_paid_offline' => true]);

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/admin/orders', $payload, [
                'Idempotency-Key' => (string) Str::uuid(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.order.status', Order::STATUS_PAID)
            ->assertJsonMissing(['ecpay_payment_html']);
    }

    public function test_store_requires_order_create_permission(): void
    {
        $support = $this->makeCustomerSupport(); // no order.create
        $member = $this->makeMember();

        $this->actingAs($support)
            ->postJson('/api/v1/admin/orders', $this->baseOrderPayload($member), [
                'Idempotency-Key' => (string) Str::uuid(),
            ])
            ->assertForbidden();
    }

    // ── POST /admin/orders/{id}/ship ──────────────────────────────────────

    public function test_admin_can_ship_paid_order(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember();
        $order = $this->makeOrder($member);
        app(OrderService::class)->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/admin/orders/{$order->id}/ship");

        $response->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_SHIPPED);
    }

    public function test_ship_pending_order_returns_d001(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember();
        $order = $this->makeOrder($member);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/orders/{$order->id}/ship")
            ->assertStatus(422)
            ->assertJsonPath('code', ApiCode::INVALID_ORDER_STATE_TRANSITION);
    }

    // ── POST /admin/orders/{id}/complete ──────────────────────────────────

    public function test_admin_can_complete_paid_order_and_points_earned(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember();
        $order = $this->makeOrder($member);
        app(OrderService::class)->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/admin/orders/{$order->id}/complete");

        $response->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_COMPLETED)
            ->assertJsonPath('data.points_earned', 20); // 2000 × 1% = 20
    }

    // ── POST /admin/orders/{id}/cancel ────────────────────────────────────

    public function test_admin_can_cancel_pending_order(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember();
        $order = $this->makeOrder($member);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", ['reason' => '客服取消'])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_CANCELLED);
    }

    public function test_cancel_requires_order_cancel_permission(): void
    {
        $viewer = $this->makeViewer(); // viewer has no order.cancel
        $member = $this->makeMember();
        $order = $this->makeOrder($member);

        $this->actingAs($viewer)
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel")
            ->assertForbidden();
    }

    // ── POST /admin/orders/{id}/refund ────────────────────────────────────

    public function test_admin_can_refund_completed_order(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember();
        $order = $this->makeOrder($member);
        $svc = app(OrderService::class);
        $svc->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);
        $svc->complete($order->fresh(), $admin);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/admin/orders/{$order->id}/refund", [
                'amount' => 500,
                'reason' => '部分退款',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.order.status', Order::STATUS_PARTIAL_REFUNDED)
            ->assertJsonPath('data.refund.amount', 500);
    }

    public function test_refund_requires_order_refund_permission(): void
    {
        $support = $this->makeCustomerSupport(); // no order.refund
        $member = $this->makeMember();
        $order = $this->makeOrder($member);

        $this->actingAs($support)
            ->postJson("/api/v1/admin/orders/{$order->id}/refund", [
                'amount' => 100, 'reason' => 'test',
            ])
            ->assertForbidden();
    }

    public function test_refund_exceeds_paid_returns_d004(): void
    {
        $admin = $this->makeAdmin();
        $member = $this->makeMember();
        $order = $this->makeOrder($member);
        $svc = app(OrderService::class);
        $svc->markPaid($order, Order::PAYMENT_METHOD_OFFLINE);
        $svc->complete($order->fresh(), $admin);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/orders/{$order->id}/refund", [
                'amount' => 9999,
                'reason' => '超額退款',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', ApiCode::REFUND_AMOUNT_EXCEEDS_PAID);
    }
}
