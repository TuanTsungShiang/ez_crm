<?php

namespace App\Services\Payments\ECPay;

use App\Exceptions\Order\InvalidOrderStateTransitionException;
use App\Models\Order;
use App\Models\PaymentCallback;
use App\Services\Order\OrderService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * ECPay (綠界) payment gateway integration — Phase 2.3 L1.
 *
 * Scope:
 *   - createPaymentForm(): build the auto-submit HTML form for frontend
 *   - verifyCallback():    verify CheckMacValue SHA256 signature
 *   - isAllowedIp():       IP whitelist check (production only)
 *   - handleCallback():    orchestrate full inbound-webhook pipeline
 *
 * Out of scope (Phase 2.4 / 7):
 *   - ECPay refund API (L2)
 *   - Multi-payment-method routing / reconciliation (L3)
 *
 * CheckMacValue algorithm (per ECPay spec §4.4.1):
 *   1. Remove CheckMacValue from params
 *   2. Sort remaining keys alphabetically (case-insensitive)
 *   3. Join as "HashKey={key}&k=v&...&HashIV={iv}"
 *   4. URL-encode with ECPay-specific char set (see encodeForCheckMac())
 *   5. Lowercase → SHA256 → Uppercase
 */
class ECPayService
{
    public function __construct(private OrderService $orderService) {}

    // ── Payment form ──────────────────────────────────────────────────────

    /**
     * Build the ECPay auto-submit HTML form for a pending order.
     *
     * The frontend receives this HTML string and can either inject it into
     * the DOM (which auto-submits via the onload script) or display a
     * "Proceed to payment" button that triggers the form's submit().
     *
     * MerchantTradeNo = order_no (≤ 20 chars, e.g. EZ-20260502-0001).
     */
    public function createPaymentForm(Order $order): string
    {
        $params = $this->buildPaymentParams($order);
        $params['CheckMacValue'] = $this->computeCheckMacValue($params);

        $endpoint = $this->endpoint();
        $fields = '';

        foreach ($params as $key => $value) {
            $escaped = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $fields .= "<input type=\"hidden\" name=\"{$key}\" value=\"{$escaped}\">\n";
        }

        return <<<HTML
            <form id="ecpay-form" action="{$endpoint}" method="POST">
            {$fields}
            </form>
            <script>document.getElementById('ecpay-form').submit();</script>
            HTML;
    }

    // ── Signature ─────────────────────────────────────────────────────────

    /**
     * Verify the CheckMacValue in an inbound ECPay callback payload.
     *
     * @param  array<string, string>  $payload  raw POST params from ECPay
     */
    public function verifyCallback(array $payload): bool
    {
        if (! isset($payload['CheckMacValue'])) {
            return false;
        }

        $received = strtoupper($payload['CheckMacValue']);
        $params = array_diff_key($payload, ['CheckMacValue' => true]);
        $expected = $this->computeCheckMacValue($params);

        return hash_equals($expected, $received);
    }

    /**
     * Check if a client IP is allowed to hit the ECPay webhook endpoint.
     *
     * Only enforced when APP_ENV=production.  In all other environments
     * (local, staging, testing) every IP is allowed so that sandbox testing
     * works without additional configuration.
     */
    public function isAllowedIp(string $ip): bool
    {
        if (config('app.env') !== 'production') {
            return true;
        }

        $whitelist = config('ecpay.ip_whitelist', []);

        foreach ($whitelist as $entry) {
            $entry = trim($entry);

            if ($this->ipMatchesCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    // ── Inbound callback pipeline ─────────────────────────────────────────

    /**
     * Orchestrate the full ECPay payment callback pipeline.
     *
     * Processing steps (per ORDER_INTEGRATION_PLAN §5.3):
     *  1. Store raw callback row (status=received) — always, even on failure
     *  2. DB UNIQUE constraint catches concurrent replays at INSERT time
     *  3. Verify CheckMacValue → fail: status=failed, return (don't ask ECPay to retry)
     *  4. App-level replay guard: same trade_no already processed → status=duplicate
     *  5. Only proceed for RtnCode=1 (success)
     *  6. Find order by order_no = MerchantTradeNo
     *  7. OrderService::markPaid → update status, redeem coupons
     *  8. Set status=processed
     *
     * Return value: the PaymentCallback row (callers check ->status).
     * Signature failures return null — the caller must still return 200 to ECPay.
     *
     * @param  array<string, string>  $payload  raw POST params from ECPay
     */
    public function handleCallback(array $payload): PaymentCallback
    {
        $merchantTradeNo = $payload['MerchantTradeNo'] ?? '';
        $callbackTime = $this->parsePaymentDate($payload['PaymentDate'] ?? '');

        // Step 1 + 2: insert with DB UNIQUE backstop
        try {
            $cb = PaymentCallback::create([
                'provider' => PaymentCallback::PROVIDER_ECPAY,
                'trade_no' => $merchantTradeNo,
                'rtn_code' => $payload['RtnCode'] ?? '',
                'rtn_msg' => $payload['RtnMsg'] ?? null,
                'amount' => isset($payload['TradeAmt']) ? (int) $payload['TradeAmt'] : null,
                'callback_time' => $callbackTime,
                'status' => PaymentCallback::STATUS_RECEIVED,
                'raw_payload' => $payload,
            ]);
        } catch (QueryException $e) {
            // DB UNIQUE: exact same (provider, trade_no, callback_time) already stored
            if (str_contains($e->getMessage(), '23000')) {
                // Return a transient placeholder — caller sees it as duplicate
                return $this->makeDuplicatePlaceholder($merchantTradeNo, $callbackTime);
            }
            throw $e;
        }

        // Step 3: signature verification
        if (! $this->verifyCallback($payload)) {
            $cb->update(['status' => PaymentCallback::STATUS_FAILED]);
            Log::warning('ECPay: CheckMacValue mismatch', [
                'trade_no' => $merchantTradeNo,
                'cb_id' => $cb->id,
            ]);

            return $cb;
        }

        $cb->update(['status' => PaymentCallback::STATUS_VERIFIED]);

        // Step 4: app-level replay guard (non-concurrent ECPay retry)
        $alreadyProcessed = PaymentCallback::where('provider', PaymentCallback::PROVIDER_ECPAY)
            ->where('trade_no', $merchantTradeNo)
            ->where('status', PaymentCallback::STATUS_PROCESSED)
            ->where('id', '!=', $cb->id)
            ->exists();

        if ($alreadyProcessed) {
            $cb->update(['status' => PaymentCallback::STATUS_DUPLICATE]);

            return $cb;
        }

        // Step 5: only process successful payments
        if (($payload['RtnCode'] ?? '') !== '1') {
            $cb->update(['status' => PaymentCallback::STATUS_FAILED]);
            Log::info('ECPay: non-success callback', [
                'trade_no' => $merchantTradeNo,
                'rtn_code' => $payload['RtnCode'] ?? '',
                'rtn_msg' => $payload['RtnMsg'] ?? '',
            ]);

            return $cb;
        }

        // Step 6: find order by order_no = MerchantTradeNo
        $order = Order::where('order_no', $merchantTradeNo)->first();

        if (! $order) {
            $cb->update(['status' => PaymentCallback::STATUS_FAILED]);
            Log::error('ECPay: order not found for MerchantTradeNo', [
                'trade_no' => $merchantTradeNo,
                'cb_id' => $cb->id,
            ]);

            return $cb;
        }

        $cb->update(['order_id' => $order->id]);

        // Step 7: mark order paid — ECPay's internal TradeNo stored as ecpay_trade_no
        try {
            $this->orderService->markPaid(
                $order,
                Order::PAYMENT_METHOD_ECPAY,
                $payload['TradeNo'] ?? null,
            );
        } catch (InvalidOrderStateTransitionException) {
            // Order already paid (concurrent webhook / manual admin action) — idempotent
            Log::info('ECPay: order already paid, idempotent skip', [
                'order_no' => $merchantTradeNo,
                'cb_id' => $cb->id,
            ]);
        }

        // Step 8
        $cb->update(['status' => PaymentCallback::STATUS_PROCESSED]);

        return $cb;
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Build the POST parameters for ECPay AIO checkout (without CheckMacValue).
     *
     * @return array<string, string|int>
     */
    private function buildPaymentParams(Order $order): array
    {
        $itemNames = $order->items
            ->map(fn ($item) => "{$item->product_name} x{$item->quantity}")
            ->implode('#');

        // ECPay MerchantTradeNo max 20 chars; our order_no is ≤ 20 (PREFIX-YYYYMMDD-NNNN)
        return [
            'MerchantID' => config('ecpay.merchant_id'),
            'MerchantTradeNo' => $order->order_no,
            'MerchantTradeDate' => now()->format('Y/m/d H:i:s'),
            'PaymentType' => 'aio',
            'TotalAmount' => $order->paid_amount,
            'TradeDesc' => '訂單付款',
            'ItemName' => $itemNames ?: '商品',
            'ReturnURL' => config('ecpay.return_url'),
            'ClientBackURL' => config('ecpay.client_back_url'),
            'ChoosePayment' => 'ALL',
            'EncryptType' => 1,
        ];
    }

    /**
     * Compute CheckMacValue (SHA256) per ECPay spec §4.4.1.
     *
     * @param  array<string, mixed>  $params  all parameters except CheckMacValue
     */
    private function computeCheckMacValue(array $params): string
    {
        // Sort keys case-insensitively
        uksort($params, fn ($a, $b) => strcasecmp($a, $b));

        $hashKey = config('ecpay.hash_key');
        $hashIv = config('ecpay.hash_iv');

        // Build raw string: HashKey={key}&k=v&...&HashIV={iv}
        $raw = 'HashKey='.$hashKey;
        foreach ($params as $key => $value) {
            $raw .= "&{$key}={$value}";
        }
        $raw .= '&HashIV='.$hashIv;

        // ECPay-specific URL encoding (urlencode + restore certain unreserved chars)
        $encoded = $this->encodeForCheckMac($raw);

        return strtoupper(hash('sha256', strtolower($encoded)));
    }

    /**
     * ECPay-specific URL encoding for CheckMacValue computation.
     *
     * ECPay uses PHP urlencode() then restores the unreserved chars that
     * standard RFC 3986 / ECPay spec requires to remain un-encoded.
     */
    private function encodeForCheckMac(string $raw): string
    {
        $encoded = urlencode($raw);

        // Restore characters that ECPay wants literal (not percent-encoded)
        return strtr($encoded, [
            '%21' => '!',
            '%28' => '(',
            '%29' => ')',
            '%2a' => '*',
            '%2A' => '*',
            '%2d' => '-',
            '%2D' => '-',
            '%2e' => '.',
            '%2E' => '.',
            '%5f' => '_',
            '%5F' => '_',
            '%7e' => '~',
            '%7E' => '~',
        ]);
    }

    /** Parse ECPay PaymentDate string ("Y/m/d H:i:s") into a Carbon instance. */
    private function parsePaymentDate(string $dateStr): Carbon
    {
        try {
            return Carbon::createFromFormat('Y/m/d H:i:s', $dateStr);
        } catch (\Exception) {
            return now();
        }
    }

    /**
     * Return a transient (unsaved) PaymentCallback-like object indicating a
     * DB-level duplicate. Used when the INSERT itself fails with 23000.
     */
    private function makeDuplicatePlaceholder(string $tradeNo, Carbon $callbackTime): PaymentCallback
    {
        $cb = new PaymentCallback([
            'provider' => PaymentCallback::PROVIDER_ECPAY,
            'trade_no' => $tradeNo,
            'rtn_code' => '',
            'callback_time' => $callbackTime,
            'status' => PaymentCallback::STATUS_DUPLICATE,
            'raw_payload' => [],
        ]);
        // id=0 signals "not persisted" — callers should check status, not id
        $cb->exists = false;

        return $cb;
    }

    /** Active ECPay endpoint URL based on configured environment. */
    private function endpoint(): string
    {
        $env = config('ecpay.env', 'stage');

        return config("ecpay.endpoints.{$env}");
    }

    /**
     * Check if $ip falls within a CIDR range or matches exactly.
     *
     * Supports both IPv4 CIDR ("203.66.91.0/24") and plain IPs ("203.66.91.31").
     */
    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
