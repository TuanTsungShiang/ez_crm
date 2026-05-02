# ez_crm 工作日誌 — 2026-05-03（Phase 2.3 Order Day 5）

> 協作：Kevin + Claude Code（Sonnet 4.6）
> 主題：Phase 2.3 ECPay L1 整合 — ECPayService + payment_callbacks + IP whitelist
> 接續：`session_log_phase2_3_day2.md`（Day 2~4 OrderService FSM 完整收尾）

---

## 過程概覽

今天實作 ORDER_INTEGRATION_PLAN Day 5 全部內容：

1. **config/ecpay.php** — 環境設定、endpoint、IP whitelist
2. **ECPayService** — createPaymentForm / verifyCallback / isAllowedIp / handleCallback
3. **EcpayIpWhitelist middleware** — IP 白名單守門（production-only）
4. **EcpayWebhookController** — 薄 controller，ECPay 協議 always 200

---

## 1. config/ecpay.php

```php
return [
    'merchant_id' => env('ECPAY_MERCHANT_ID', '2000132'),  // sandbox 公開憑證
    'hash_key'    => env('ECPAY_HASH_KEY', '5294y06JbISpM5x9'),
    'hash_iv'     => env('ECPAY_HASH_IV', 'v77hoKGq4kWxNNIS'),
    'env'         => env('ECPAY_ENV', 'stage'),
    'endpoints'   => [
        'stage'      => 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5',
        'production' => 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5',
    ],
    'return_url'      => env('ECPAY_RETURN_URL', ...),
    'client_back_url' => env('ECPAY_CLIENT_BACK_URL', ...),
    'ip_whitelist'    => array_filter(explode(',', env('ECPAY_IP_WHITELIST', '...'))),
];
```

設計重點：
- sandbox 公開憑證直接設為 default → 新開發者 `git clone` 即可跑 sandbox，零設定
- IP whitelist 支援逗號分隔 + CIDR，從 env 覆寫，方便 ECPay 更新 IP 時不改程式碼

---

## 2. ECPayService

### 2.1 createPaymentForm

```php
public function createPaymentForm(Order $order): string
```

- 組出所有必填 ECPay AIO 參數（MerchantID / MerchantTradeNo / TotalAmount / ItemName / ReturnURL / …）
- `MerchantTradeNo = order_no`（≤ 20 chars，格式 `EZ-YYYYMMDD-NNNN` 正好 16 chars）
- 計算 CheckMacValue，附加至 params
- 產生 auto-submit HTML form（含 `<script>form.submit()</script>`）
- 前端拿到 HTML string 後直接注入 DOM → ECPay 頁面自動跳轉

### 2.2 CheckMacValue 演算法（手刻，不依賴外部 SDK）

```
演算法（ECPay spec §4.4.1）：
1. 移除 CheckMacValue 欄位
2. 按 key 字典序排序（case-insensitive）
3. 組出：HashKey={key}&k1=v1&...&HashIV={iv}
4. ECPay 特殊 URL encode（urlencode + 還原 ! ( ) * - . _ ~ 六個字元）
5. 轉小寫 → SHA256 → 轉大寫
```

```php
private function encodeForCheckMac(string $raw): string
{
    return strtr(urlencode($raw), [
        '%21' => '!', '%28' => '(', '%29' => ')',
        '%2a' => '*', '%2d' => '-', '%2e' => '.',
        '%5f' => '_', '%7e' => '~',
        // uppercase variants also covered
    ]);
}
```

決策：手刻而不用 ECPay PHP SDK 的原因：
1. ECPay SDK 不在 Packagist，需要 GitHub 手動下載
2. 演算法本身 40 行，可讀性高、無外部依賴
3. 最重要：對 interviewer 展示你真的懂 signing 原理，而不只是 call SDK

### 2.3 isAllowedIp — CIDR 檢查（production-only）

```php
public function isAllowedIp(string $ip): bool
{
    if (config('app.env') !== 'production') {
        return true;  // sandbox testing 不受 IP 限制
    }
    foreach (config('ecpay.ip_whitelist') as $entry) {
        if ($this->ipMatchesCidr($ip, $entry)) { return true; }
    }
    return false;
}

private function ipMatchesCidr(string $ip, string $cidr): bool
{
    // 支援單 IP 和 CIDR range（如 103.52.180.0/22）
    [$subnet, $bits] = explode('/', $cidr, 2);
    $mask = ~0 << (32 - (int)$bits);
    return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
}
```

### 2.4 handleCallback — 8 步驟 pipeline

```
Step 1: 插入 payment_callbacks row（status=received，raw_payload 全存）
Step 2: DB UNIQUE(provider, trade_no, callback_time) → 並發 INSERT 失敗 = 23000 = duplicate
Step 3: verifyCallback CheckMacValue → 失敗 status=failed，return（不要 ECPay retry）
Step 4: App 層查 trade_no 是否已 processed → duplicate（非並發 retry 場景）
Step 5: RtnCode !== '1' → status=failed，return
Step 6: Order::where('order_no', $merchantTradeNo)->first() → 找不到 status=failed + Log::error
Step 7: orderService::markPaid（catch InvalidOrderStateTransitionException → idempotent skip）
Step 8: status=processed，return $cb
```

**Replay 雙保險設計**：

| 層 | 機制 | 覆蓋場景 |
|---|---|---|
| DB UNIQUE | `unique(provider, trade_no, callback_time)` 在 INSERT 失敗 | 同一 payload 並發打進來 |
| App layer | Step 4 查 status=processed | ECPay 超時重發（不同時間）|

---

## 3. EcpayIpWhitelist Middleware

```php
public function handle(Request $request, Closure $next): Response
{
    if (! $this->ecpay->isAllowedIp($request->ip())) {
        abort(403, 'Forbidden: IP not in ECPay whitelist');
    }
    return $next($request);
}
```

- 薄 middleware：邏輯全委給 ECPayService::isAllowedIp
- 在 Kernel.php 注冊為 `ecpay.ip` alias
- 只套用在 `/api/v1/webhooks/ecpay/*` route group

---

## 4. EcpayWebhookController

ECPay 協議設計決策：

| 情況 | 我們回傳 | 原因 |
|---|---|---|
| 驗簽成功、訂單付款完成 | `1\|OK` 200 | 正常 |
| 驗簽失敗 | `1\|OK` 200 | 不要 ECPay 重試帶同樣壞 payload |
| Replay / duplicate | `1\|OK` 200 | 已處理，不需重試 |
| 訂單找不到 | `1\|OK` 200 | 我方 data issue，重試無助 |
| 任何其他錯誤 | `1\|OK` 200 | 防止 ECPay retry storm |

> 唯一例外：`0|Error` 適用於**系統暫時性故障**且確認 ECPay retry 有幫助的場景（例如 DB 完全掛掉）。Phase 2.3 L1 先統一 200，Phase 7 可細化。

```php
public function payment(Request $request): Response
{
    $cb = $this->ecpay->handleCallback($request->all());
    // controller stays thin — all logic in ECPayService
    return response('1|OK', 200)->header('Content-Type', 'text/plain');
}
```

---

## 5. Route 設計

```php
// routes/api.php
Route::prefix('v1/webhooks')
    ->middleware('ecpay.ip')
    ->group(function () {
        Route::post('ecpay/payment', [EcpayWebhookController::class, 'payment']);
    });
```

放在 `auth:sanctum` group 之外：webhook 不需要 token 認證，靠簽名驗証。

---

## 驗證

```
PHPStan level 5   → 0 errors ✅
php artisan test  → 304 tests / 919 assertions ✅（無 regression）
```

PHPStan 發現：Pint auto-fixed import ordering + concat/unary spacing，5 個檔案。

---

## 數字

| 項目 | 數量 |
|---|---|
| 今日 commits | 1（feat）|
| 新 Service 檔 | 1（ECPayService ~250 行）|
| 新 Middleware | 1 |
| 新 Controller | 1 |
| 新 Exception | 1 |
| 新 config 檔 | 1 |
| .env.example 新變數 | 7（ECPAY_*）|
| 新增程式碼 | ~648 行（含 OA Swagger annotation）|
| 累計 tests | 304（Day 5 本身沒有新 tests — feature tests 留 Day 9）|

---

## 對應 commits

```
a91a7cd feat(order): Phase 2.3 Day 5 — ECPayService + payment_callbacks + IP whitelist middleware
```

---

## 下一步（Day 6）

| Day | 範圍 | 估時 |
|---|---|---|
| **Day 6** | Member API endpoints（create / repay / cancel / list / detail）+ FormRequests + feature tests | 1 天 |
| Day 7 | Admin API endpoints + Filament OrderResource + RBAC 6 permissions + RolePermissionSeeder 補 | 1 天 |
| Day 8 | Webhook events 6 個（created/paid/shipped/completed/cancelled/refunded）+ CancelPendingOrdersJob cron | 1 天 |
| Day 9 | Concurrency tests + ECPay sandbox 整合測 + Swagger annotations | 1 天 |
| Day 10 | ADR-0006 + session log + bug fix buffer | 1 天 |

---

## 個人觀察

**ECPay 的難點不是 API 呼叫，是邊角防禦**：

- CheckMacValue 手刻 40 行，但只要有一個字元沒對齊（URL encoding 的 `!` `(` `)` 要不要 restore），簽名就永遠錯。
- Replay 防禦的「雙保險」有順序性：DB UNIQUE 在 INSERT 時擋並發，App layer 在 SELECT 時擋序列 retry — 兩者缺一不可。
- "Always 200" 策略違反直覺（錯誤也回 200？），但這是 ECPay 協議要求，不是偷懶。

**反模式對比（給 portfolio 閱讀者）**：

| 反模式 | 我們的做法 |
|---|---|
| Controller 直接處理 CheckMacValue | 委給 ECPayService，controller 只管協議 |
| 把 payment_callbacks 視為 logging，直接寫就好 | 雙保險（DB UNIQUE + App layer），raw_payload 永存 |
| IP whitelist hardcode 在程式碼 | config/ecpay.php + env 覆寫，定期更新不改程式 |
| 只在 production 環境才想到 IP whitelist | `isAllowedIp` 內建環境判斷，dev/test 自動 bypass |
| ECPay SDK 當黑盒子 | 手刻 CheckMacValue，展示你懂 signing 原理 |
