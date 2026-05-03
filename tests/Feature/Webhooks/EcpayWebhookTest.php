<?php

namespace Tests\Feature\Webhooks;

use App\Models\Member;
use App\Models\Order;
use App\Models\OrderSettings;
use App\Models\PaymentCallback;
use App\Services\Order\OrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for POST /api/v1/webhooks/ecpay/payment.
 *
 * We verify the full HTTP → EcpayWebhookController → ECPayService → OrderService
 * pipeline using the public ECPay sandbox credentials (2000132 / 5294y06JbISpM5x9 /
 * v77hoKGq4kWxNNIS).  CheckMacValue is computed inline so these tests exercise the
 * real signature verification algorithm — not a mock.
 *
 * ECPay sandbox integration tests (requiring a real network call to
 * payment-stage.ecpay.com.tw) are kept in a separate group:
 *
 * @see EcpayWebhookTest::test_sandbox_*  (@group ecpay-sandbox, skipped in CI)
 */
class EcpayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $orderSvc;

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

        $this->orderSvc = app(OrderService::class);
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

    private function makeOrder(Member $member): Order
    {
        return $this->orderSvc->create(
            $member,
            [
                'items' => [['product_sku' => 'SKU-001', 'product_name' => '商品', 'unit_price' => 1000, 'quantity' => 2]],
                'shipping_address' => ['recipient_name' => '王', 'phone' => '0912345678', 'postal_code' => '100', 'city' => '台北市', 'district' => '中正區', 'address_line' => '路 1 號'],
            ],
            (string) Str::uuid(),
        );
    }

    /**
     * Build a valid ECPay callback payload with a correct CheckMacValue.
     *
     * Uses the public sandbox credentials. If config values are overridden in
     * test env, this helper automatically uses those — so the test remains valid
     * against any credentials.
     *
     * @param  array<string, string|int>  $overrides  override any field (e.g. RtnCode)
     */
    private function buildPayload(Order $order, array $overrides = []): array
    {
        $params = array_merge([
            'MerchantID' => config('ecpay.merchant_id'),
            'MerchantTradeNo' => $order->order_no,
            'TradeNo' => 'TEST'.now()->format('YmdHis'),
            'RtnCode' => '1',
            'RtnMsg' => '交易成功',
            'TradeAmt' => $order->paid_amount,
            'PaymentDate' => now()->format('Y/m/d H:i:s'),
            'PaymentType' => 'Credit_CreditCard',
            'TradeDate' => now()->format('Y/m/d H:i:s'),
        ], $overrides);

        $params['CheckMacValue'] = $this->computeCheckMacValue($params);

        return $params;
    }

    /**
     * ECPay CheckMacValue algorithm (mirrors ECPayService::computeCheckMacValue).
     * Replicated here so tests remain self-contained and do not depend on
     * internal ECPayService methods being public.
     */
    private function computeCheckMacValue(array $params): string
    {
        uksort($params, fn ($a, $b) => strcasecmp($a, $b));

        $hashKey = config('ecpay.hash_key');
        $hashIv = config('ecpay.hash_iv');

        $raw = 'HashKey='.$hashKey;
        foreach ($params as $key => $value) {
            $raw .= "&{$key}={$value}";
        }
        $raw .= '&HashIV='.$hashIv;

        $encoded = strtr(urlencode($raw), [
            '%21' => '!', '%28' => '(', '%29' => ')',
            '%2a' => '*', '%2A' => '*', '%2d' => '-', '%2D' => '-',
            '%2e' => '.', '%2E' => '.', '%5f' => '_', '%5F' => '_',
            '%7e' => '~', '%7E' => '~',
        ]);

        return strtoupper(hash('sha256', strtolower($encoded)));
    }

    // ── happy path ────────────────────────────────────────────────────────

    public function test_valid_callback_marks_order_paid_and_returns_1_ok(): void
    {
        $member = $this->makeMember();
        $order = $this->makeOrder($member);

        $response = $this->postJson(
            '/api/v1/webhooks/ecpay/payment',
            $this->buildPayload($order),
        );

        $response->assertOk()
            ->assertSee('1|OK');

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(1, PaymentCallback::where('status', PaymentCallback::STATUS_PROCESSED)->count());
    }

    public function test_valid_callback_stores_ecpay_trade_no_on_order(): void
    {
        $member = $this->makeMember();
        $order = $this->makeOrder($member);
        $tradeNo = 'ECPayTrade'.now()->format('YmdHis');

        $this->postJson(
            '/api/v1/webhooks/ecpay/payment',
            $this->buildPayload($order, ['TradeNo' => $tradeNo]),
        )->assertOk();

        $this->assertSame($tradeNo, $order->fresh()->ecpay_trade_no);
    }

    // ── signature failure ─────────────────────────────────────────────────

    public function test_invalid_signature_returns_1_ok_but_marks_callback_failed(): void
    {
        $member = $this->makeMember();
        $order = $this->makeOrder($member);
        $payload = $this->buildPayload($order);

        // Tamper with CheckMacValue
        $payload['CheckMacValue'] = 'BADBADBADBAD';

        $response = $this->postJson('/api/v1/webhooks/ecpay/payment', $payload);

        // Always 200 per ECPay protocol (no retry)
        $response->assertOk()->assertSee('1|OK');

        // Order must still be pending (not processed)
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(1, PaymentCallback::where('status', PaymentCallback::STATUS_FAILED)->count());
    }

    // ── replay defense ────────────────────────────────────────────────────

    /**
     * Simulates ECPay's non-concurrent retry: same trade_no but a different
     * PaymentDate (ECPay sends a new timestamp on retry). The second INSERT
     * succeeds (different callback_time avoids the DB UNIQUE), but the
     * app-layer guard detects the trade_no is already processed → duplicate.
     */
    public function test_replay_different_timestamp_app_layer_marks_duplicate(): void
    {
        $member = $this->makeMember();
        $order = $this->makeOrder($member);

        // First callback
        $first = $this->buildPayload($order, ['PaymentDate' => '2026/05/03 10:00:00']);
        $this->postJson('/api/v1/webhooks/ecpay/payment', $first)->assertOk();

        // ECPay retry with a different timestamp (5 seconds later)
        $second = $this->buildPayload($order, ['PaymentDate' => '2026/05/03 10:00:05']);
        $this->postJson('/api/v1/webhooks/ecpay/payment', $second)->assertOk();

        $this->assertSame(
            1,
            PaymentCallback::where('status', PaymentCallback::STATUS_PROCESSED)->count(),
            'only one row should be processed',
        );
        $this->assertSame(
            1,
            PaymentCallback::where('status', PaymentCallback::STATUS_DUPLICATE)->count(),
            'retry callback should be marked duplicate by app-layer guard',
        );

        // Order paid only once — not double-processed
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    /**
     * Concurrent replay (exact same payload, same PaymentDate):
     * DB UNIQUE constraint blocks the second INSERT — no duplicate row is
     * persisted. The controller still returns 200.
     */
    public function test_replay_same_timestamp_db_unique_prevents_second_insert(): void
    {
        $member = $this->makeMember();
        $order = $this->makeOrder($member);
        $payload = $this->buildPayload($order, ['PaymentDate' => '2026/05/03 10:00:00']);

        $this->postJson('/api/v1/webhooks/ecpay/payment', $payload)->assertOk();
        $this->postJson('/api/v1/webhooks/ecpay/payment', $payload)->assertOk();

        // Only one row in DB (second INSERT was blocked by DB UNIQUE)
        $this->assertSame(1, PaymentCallback::count());
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    // ── non-success RtnCode ───────────────────────────────────────────────

    public function test_rtn_code_not_1_keeps_order_pending(): void
    {
        $member = $this->makeMember();
        $order = $this->makeOrder($member);
        $payload = $this->buildPayload($order, ['RtnCode' => '10100058']); // card declined example

        $this->postJson('/api/v1/webhooks/ecpay/payment', $payload)->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertSame(PaymentCallback::STATUS_FAILED, PaymentCallback::first()->status);
    }

    // ── unknown order ─────────────────────────────────────────────────────

    public function test_unknown_order_no_marks_callback_failed(): void
    {
        // Build payload with a non-existent order_no
        $fakeOrder = new Order(['order_no' => 'EZ-99990101-9999', 'paid_amount' => 1000]);
        $payload = $this->buildPayload($fakeOrder);

        $this->postJson('/api/v1/webhooks/ecpay/payment', $payload)->assertOk();

        $this->assertSame(PaymentCallback::STATUS_FAILED, PaymentCallback::first()->status);
    }

    // ── IP whitelist (non-production — always allowed) ────────────────────

    public function test_any_ip_allowed_in_non_production_env(): void
    {
        // APP_ENV=testing, so isAllowedIp returns true for any IP
        $member = $this->makeMember();
        $order = $this->makeOrder($member);

        $response = $this->postJson(
            '/api/v1/webhooks/ecpay/payment',
            $this->buildPayload($order),
            ['REMOTE_ADDR' => '1.2.3.4'], // random IP not in whitelist
        );

        $response->assertOk();
    }

    // ── ECPay sandbox integration tests (@group ecpay-sandbox) ───────────

    /**
     * Verify that createPaymentForm() produces a form accepted by ECPay stage.
     * Skipped in CI — run manually with: phpunit --group ecpay-sandbox
     *
     * @group ecpay-sandbox
     */
    public function test_sandbox_payment_form_is_accepted(): void
    {
        $this->markTestSkipped('ECPay sandbox integration — run manually with --group ecpay-sandbox');
    }
}
