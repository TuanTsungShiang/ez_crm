# Order 訂單系統 整合計畫(Phase 2.3)

> 版本：v1.0（Accepted）
> 建立日期：2026-05-02
> 狀態：✅ Accepted — 9 議題 30+ 子決議全部拍板（2026-05-02 review session），可進實作
> 對應 Roadmap：SENIOR_ROADMAP Phase 2.3（Phase 2 鐵三角壓軸）
> 預估實作：**9–11 個工作天**（B 路線真實電商複雜度 + ECPay L1）
> 預定起跑日：v1.0 拍板後立即（無前置依賴等待）
> 前置依賴：Phase 2.1 Points ✅ / Phase 2.2 Coupon ✅ / RBAC ✅ / Webhook ✅

---

## 一、為什麼做（Context）

Phase 2 鐵三角的最後一塊：**Order 是 Points 跟 Coupon 的「消費端」**。完成後 Phase 2 整段封閉，可以回頭看 e2e 流程。

- ❌ Junior 寫法：`orders` 一張表把所有東西塞進去（status 字串、coupon_id 單欄、address 直接展開、refund 改原 row）
- ✅ Senior 寫法：State machine + 多表正規化 + snapshot 凍結歷史 + 真實 ECPay webhook + idempotency 完整防禦

**Senior 訊號的鎖點**：
1. **State machine**（轉換白名單 + immutable history）
2. **真實電商複雜度**（returns / partial refund / multi-coupon stacking）
3. **真實金流整合**（ECPay webhook signature + replay defense + IP whitelist）
4. **Snapshot pattern**（order_items / order_addresses 凍結當時資料，不 join）
5. **複用既有 Service**（PointService / CouponService 整合，不重造輪子）

---

## 二、範圍切割

### In scope（Phase 2.3）

**核心模組**
- ✅ 9 張新表（orders / order_items / order_addresses / order_status_histories / order_coupons / returns / refunds / payment_callbacks / order_settings）
- ✅ Coupons 表加 2 欄（priority / category）— 補一支 migration
- ✅ `OrderService::create()` / `markPaid()` / `markPaidOffline()` / `cancel()` / `ship()` / `complete()` / `refund()` / `partialRefund()`
- ✅ State machine：6 狀態 + 8 條合法轉換 + 全部禁止逆向
- ✅ Member API（下單 / 查單 / 重付）
- ✅ Admin API + Filament UI（CRUD + 狀態操作）
- ✅ ECPay L1 整合（接 paid webhook，verify signature + replay defense + IP whitelist prod-only）
- ✅ Points 整合（completed 才加，按 paid_amount × rate；退款按比例倒退）
- ✅ Coupon 整合（paid 時鎖券；多張可疊；priority 套用；refund 反向 cancel）
- ✅ Idempotency：Client `Idempotency-Key` header → DB UNIQUE
- ✅ Timeout：Cron 每 5 分鐘掃 pending 超時 → cancel + webhook
- ✅ 6 個 RBAC permissions
- ✅ Webhook events：`order.created` / `order.paid` / `order.shipped` / `order.completed` / `order.cancelled` / `order.refunded`

### Out of scope（留後續 Phase）

| 項目 | 預定 Phase |
|---|---|
| ECPay L2（退款 API 整合）| Phase 2.4 / Phase 7 |
| ECPay L3（多支付方式 / 失敗重試 / 對帳）| Phase 7 完整實作 |
| 多前台 prefix（多 `order_prefixes` 表 + Resolver）| Phase 3+（對應 §9.4 多前台研究）|
| Email / SMS 通知（訂單建立 / 付款 / 出貨 / 退款）| Phase 2.4 |
| 部分退款 Coupon 比例退（目前 Coupon 全消耗）| Phase 2.4 |
| 商品（Product）系統 — 現在用 snapshot pattern | 永久不做（CRM 不是電商管商品系統）|
| Returns 真實退換貨流程細化（picked_up / quality_check）| Phase 2.4 |
| 物流串接（黑貓 / 7-11 出貨自動 trace）| Phase 4+ |

---

## 三、Schema 設計

### 3.1 `order_settings`（單列配置表）

```php
Schema::create('order_settings', function (Blueprint $table) {
    $table->id();
    $table->string('order_no_prefix', 16)->default('EZ');
    $table->decimal('points_rate', 5, 4)->default(0.0100);  // 1% 預設
    $table->unsignedInteger('pending_timeout_minutes')->default(30);
    $table->unsignedInteger('min_charge_amount')->default(1);  // 折抵後最低金額
    $table->timestamps();
});

// Seeder: 寫入 1 row 預設值，後台改而非 insert
```

**為什麼單列表而非 key-value**：YAGNI，目前 4 個設定夠用，不引入通用 settings 抽象。
**未來擴展**：若需多 prefix（多前台），重構為 `order_prefixes` 表 + `orders.order_prefix_id` FK，估時 +1 天，全 additive。

### 3.2 `orders`（header）

```php
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->string('order_no', 32)->unique();   // PREFIX-YYYYMMDD-NNNN

    $table->foreignId('member_id')->constrained()->restrictOnDelete();

    // 狀態機
    $table->enum('status', [
        'pending', 'paid', 'shipped', 'completed',
        'cancelled', 'partial_refunded', 'refunded',
    ])->default('pending');

    // 金額（snapshot 凍結）
    $table->bigInteger('subtotal');             // 商品小計（折扣前）
    $table->bigInteger('discount_total');       // 所有 coupon 折扣加總
    $table->bigInteger('paid_amount');          // 實付金額 = subtotal - discount_total
    $table->bigInteger('refund_amount')->default(0);  // 累計已退款（partial / full）

    // Points snapshot
    $table->bigInteger('points_earned')->default(0);    // completed 時加的點數
    $table->bigInteger('points_refunded')->default(0);  // 退款倒退的點數累計

    // Idempotency（議題 #6）
    $table->string('idempotency_key', 64)->unique();

    // 付款資訊
    $table->enum('payment_method', ['ecpay', 'offline'])->nullable();
    $table->string('ecpay_trade_no', 32)->nullable()->unique();  // ECPay MerchantTradeNo
    $table->timestamp('paid_at')->nullable();
    $table->timestamp('shipped_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamp('cancelled_at')->nullable();

    // Audit
    $table->foreignId('created_by_actor_id')->nullable()->constrained('users')->nullOnDelete();
    $table->enum('created_by_actor_type', ['member', 'user', 'system'])->default('member');

    $table->json('meta')->nullable();
    $table->timestamps();

    $table->index(['member_id', 'created_at']);
    $table->index(['status', 'created_at']);
    $table->index('paid_at');
});
```

**設計要點**：
- `id` PK 內部用 / `order_no` 對外用（議題 #1）
- 所有金額 `bigInteger`（無小數，台幣），對齊 Points
- `payment_method` enum 區分 ECPay / offline（議題 #7d）
- `ecpay_trade_no` UNIQUE：跟 ECPay webhook 配對 + 防 replay
- 狀態時戳分欄（`paid_at` / `shipped_at` / 等）：BI 查詢效能 + audit 友善

### 3.3 `order_items`（line items）

```php
Schema::create('order_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();

    // Snapshot 凍結（無 product 表 FK,因為 ez_crm 不管商品系統）
    $table->string('product_sku', 64);
    $table->string('product_name', 200);
    $table->bigInteger('unit_price');           // snapshot
    $table->unsignedInteger('quantity');
    $table->bigInteger('subtotal');             // unit_price × quantity

    $table->json('product_meta')->nullable();   // 商品快照其他欄位（規格、圖片 URL）

    $table->timestamps();

    $table->index('order_id');
});
```

**設計要點**：
- **無 `product_id` FK** — 商品價格會變、商品會下架，訂單必須凍結
- `product_meta` JSON 存規格 / 圖片 URL 等延伸資料

### 3.4 `order_addresses`（收貨/帳單地址 snapshot）

```php
Schema::create('order_addresses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();
    $table->enum('type', ['shipping', 'billing']);

    $table->string('recipient_name', 100);
    $table->string('phone', 32);
    $table->string('country', 8)->default('TW');
    $table->string('postal_code', 16);
    $table->string('city', 64);
    $table->string('district', 64);
    $table->string('address_line', 200);
    $table->string('address_line2', 200)->nullable();

    $table->timestamps();

    $table->unique(['order_id', 'type']);  // 一張單 ship + bill 各 1 筆
});
```

**為什麼獨立表而非 orders 欄位**：member profile 可能改，訂單歷史地址必須凍結。

### 3.5 `order_status_histories`（狀態轉換 audit log）

```php
Schema::create('order_status_histories', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();

    $table->enum('from_status', [
        'pending', 'paid', 'shipped', 'completed',
        'cancelled', 'partial_refunded', 'refunded',
    ])->nullable();  // null = 初次建立
    $table->enum('to_status', [
        'pending', 'paid', 'shipped', 'completed',
        'cancelled', 'partial_refunded', 'refunded',
    ]);

    $table->string('reason', 500)->nullable();

    // 觸發者
    $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
    $table->enum('actor_type', ['member', 'user', 'system'])->default('user');

    $table->json('meta')->nullable();   // ECPay trade_no / cron 執行時間 / 等
    $table->timestamp('created_at')->useCurrent();

    $table->index(['order_id', 'created_at']);
});
```

**設計要點**：直接複用 `PointTransaction` 的 audit 設計理念（每次變動寫一筆，不可改）。

### 3.6 `order_coupons`（多券 junction）

```php
Schema::create('order_coupons', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();
    $table->foreignId('coupon_id')->constrained()->restrictOnDelete();

    $table->bigInteger('discount_applied');    // snapshot：此券在此訂單實際折抵
    $table->unsignedInteger('apply_order');    // 套用順序（0-based，依 priority）

    $table->timestamps();

    $table->unique(['order_id', 'coupon_id']);
});
```

**為什麼 `discount_applied` 是 snapshot**：coupon 規則可能改，但訂單套用結果必須凍結。

### 3.7 `returns`（退換貨流程 placeholder）

```php
Schema::create('returns', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->restrictOnDelete();
    $table->string('return_no', 32)->unique();   // R-YYYYMMDD-NNNN

    $table->enum('type', ['return', 'exchange']);
    $table->enum('status', [
        'requested', 'approved', 'rejected',
        'picked_up', 'received', 'refunded',
    ])->default('requested');

    $table->string('reason', 500);
    $table->bigInteger('refund_amount')->nullable();

    $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('approved_at')->nullable();
    $table->timestamp('refunded_at')->nullable();

    $table->json('meta')->nullable();
    $table->timestamps();

    $table->index(['order_id', 'status']);
});
```

**Phase 2.3 範圍**：Schema + Filament 列表（read-only）+ 基本 status 切換。實際物流 / 質檢流程留 Phase 2.4。

### 3.8 `refunds`（退款歷程）

```php
Schema::create('refunds', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->restrictOnDelete();

    $table->bigInteger('amount');   // 此次退款金額
    $table->enum('type', ['partial', 'full'])->default('partial');
    $table->string('reason', 500);

    // 處理者
    $table->foreignId('processed_by')->constrained('users');
    $table->timestamp('processed_at');

    // ECPay 整合（L2 用，目前 nullable）
    $table->string('ecpay_refund_no', 64)->nullable();
    $table->enum('ecpay_status', ['pending', 'success', 'failed'])->nullable();

    // 連動 PointService（idempotency）
    $table->foreignId('point_transaction_id')->nullable()
          ->constrained('point_transactions')->nullOnDelete();

    $table->json('meta')->nullable();
    $table->timestamps();

    $table->index(['order_id', 'created_at']);
});
```

**設計要點**：每次退款一行（partial 多次累加），Phase 2.3 只實作金額記錄 + Points 反向 transaction，ECPay refund API 留 L2。

### 3.9 `payment_callbacks`（ECPay webhook 紀錄 + replay 防禦）

```php
Schema::create('payment_callbacks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
    // nullable：callback 進來查不到 order 也要存（debug + alert）

    $table->string('provider', 32)->default('ecpay');
    $table->string('trade_no', 64);          // ECPay MerchantTradeNo
    $table->string('rtn_code', 8);           // ECPay 回傳代碼
    $table->string('rtn_msg', 200)->nullable();
    $table->bigInteger('amount')->nullable();
    $table->timestamp('callback_time');      // ECPay PaymentDate

    $table->enum('status', ['received', 'verified', 'processed', 'failed', 'duplicate'])
          ->default('received');
    $table->json('raw_payload');             // 完整保存原 webhook body

    $table->timestamps();

    // Replay 防禦：DB UNIQUE 兜底（議題 #9b）
    $table->unique(['provider', 'trade_no', 'callback_time']);
    $table->index(['provider', 'status']);
});
```

**設計要點**：
- **Replay 雙保險**：App 先查 → DB UNIQUE 兜底
- `raw_payload` JSON 存原始 webhook，事後 debug / 對帳 / 法務都能用
- `order_id` nullable：callback 進來但找不到對應 order 不丟，存起來查

### 3.10 既有 Coupons 表補欄（migration）

```php
Schema::table('coupons', function (Blueprint $table) {
    // 多券疊加用（議題 #5c / #5d）
    $table->enum('category', ['discount', 'threshold', 'shipping'])
          ->default('discount')
          ->after('type');
    $table->unsignedInteger('priority')
          ->default(100)
          ->after('category')
          ->comment('多券同時套用時的順序（小→大），預設 100');

    $table->index(['category', 'priority']);
});
```

---

## 四、狀態機

### 4.1 狀態 + 合法轉換

```
                  ┌────────────────────────────┐
                  │                            │
                  ▼                            │
            ┌─────────┐                        │
            │ pending │──────────┐             │
            └────┬────┘          │             │
                 │ T1 paid       │ T2 cancel   │
                 ▼               ▼             │
            ┌─────────┐    ┌───────────┐       │
            │  paid   │───▶│ cancelled │       │
            └────┬────┘ T4 └───────────┘       │
                 │              (terminal)     │
                 │ T3 ship                     │
                 ▼                             │
            ┌─────────┐                        │
            │ shipped │────────────────────────┘ T7 cancel(允許)
            └────┬────┘
                 │ T5 complete
                 ▼
            ┌──────────┐ T8 paid→completed
            │completed │◀────────  (跳過 shipped, 數位商品)
            └──┬───┬───┘
               │   │
            T6a│   │T6b
               │   │ partial refund
               ▼   ▼
       ┌──────────┐   ┌───────────────────┐
       │ refunded │   │ partial_refunded  │
       └──────────┘   └─────┬─────────────┘
       (terminal)           │ T6c 後續再 partial / 全退
                            ▼
                       ┌──────────┐
                       │ refunded │
                       └──────────┘
                       (terminal)
```

### 4.2 轉換明細表

| # | 轉換 | 觸發者 | 副作用 |
|---|---|---|---|
| T1 | `pending → paid` | ECPay webhook(member 路徑)/ admin manual | 寫 status_history,觸發 `order.paid` webhook,**redeem 所有 order_coupons** |
| T2 | `pending → cancelled` | member / admin / system(timeout) | 觸發 `order.cancelled` webhook;coupon 不需釋放(未 redeem) |
| T3 | `paid → shipped` | admin manual | 寫 `shipped_at`,觸發 `order.shipped` webhook |
| T4 | `paid → cancelled` | member / admin | 觸發 `order.cancelled`;**反向 cancel order_coupons**;**ECPay refund(L2)/ 標記 manual refund(L1)** |
| T5 | `shipped → completed` | system(cron N 天 / 物流 webhook L3)/ admin manual | 寫 `completed_at`,**觸發 PointService 加點**,觸發 `order.completed` webhook |
| T6a | `completed → refunded`(全退)| admin only(`order.refund` perm) | 寫 refunds row,**反向 PointService 全退**,反向 cancel coupons,觸發 `order.refunded` webhook |
| T6b | `completed → partial_refunded` | admin only | 寫 refunds row(amount < paid),**反向 PointService 比例退**,coupon 不退(全消耗),觸發 `order.refunded` webhook(partial flag) |
| T6c | `partial_refunded → partial_refunded` | admin | 累加 refunds rows,持續比例退 Points |
| T6d | `partial_refunded → refunded` | admin | refund_amount 累計 = paid_amount 時自動轉 |
| T7 | `shipped → cancelled` | admin only | 寫 returns row(處理攔件 / 拒收),觸發 `order.cancelled`,反向 PointService(若已加)+ Coupon |
| T8 | `paid → completed` | admin manual(數位商品 / 服務)| 跳過 shipped,直接做 T5 副作用 |

### 4.3 禁止的轉換（FSM 守門）

- **所有逆向**：`paid → pending`、`shipped → paid`、`completed → shipped`、等等
- **terminal 出去**：`cancelled → ANY`、`refunded → ANY`
- **跳付款**：`pending → shipped`、`pending → completed`
- **`pending → completed`**：必須先 paid

任一違法呼叫拋 `InvalidOrderStateTransitionException`,Service 守門。

---

## 五、API 規格

### 5.1 Member 端

#### `POST /api/v1/orders`(下單)

**Auth**：`auth:member`
**Headers**：
- `Idempotency-Key: {uuid-v4}`(必填，議題 #6a)

**Request**:
```json
{
  "items": [
    { "product_sku": "SKU-001", "product_name": "商品 A", "unit_price": 1000, "quantity": 2 }
  ],
  "shipping_address": { "recipient_name": "...", "phone": "...", ... },
  "billing_address": { ... },  // optional, fallback to shipping
  "coupon_codes": ["ABC123", "XYZ789"]   // optional, 多張
}
```

**Response 200**:
```json
{
  "success": true,
  "data": {
    "order": { "id": 142, "order_no": "EZ-20260502-0001", "status": "pending", ... },
    "ecpay_payment_html": "<form ...>...</form>"  // ECPay 自動 submit form
  }
}
```

**Response 422**：
- `V003 OUT_OF_RANGE` — 數量超範圍
- `V004 INVALID_ENUM` — 不存在的 coupon
- `B001 INSUFFICIENT_POINTS` — (此處不發生，留 future)
- `D004 REFUND_AMOUNT_EXCEEDS_PAID` — (此處不發生)
- `C001 INVALID_COUPON_STATE` — coupon 已 redeem
- `C002 COUPON_EXPIRED` — coupon 過期

#### `GET /api/v1/orders`（查自己訂單列表）

#### `GET /api/v1/orders/{order_no}`（查自己單筆）

#### `POST /api/v1/orders/{order_no}/repay`（重付未付款訂單，議題 #7e）

**Auth**：`auth:member`,只能對自己 `pending` 狀態的訂單重發。

**Response**：同 create，回新的 `ecpay_payment_html`。

#### `POST /api/v1/orders/{order_no}/cancel`（自己取消未付款）

**Auth**：`auth:member`,限 `pending` 狀態。

### 5.2 Admin 端

#### `POST /api/v1/admin/orders`（代下單）

**Auth**：`auth:user` + `order.create` permission
**多支援**：
- `member_id` 必填（任意 member）
- `mark_as_paid_offline: true`（議題 #7d）→ skip ECPay，直接 status=paid
- `discount_override`（手動覆寫折扣，需寫 reason，議題 #7b）

#### `GET /api/v1/admin/orders`（列表，支援多 filter）

#### `POST /api/v1/admin/orders/{id}/ship`（出貨）

#### `POST /api/v1/admin/orders/{id}/complete`（完成 — 加點觸發點）

#### `POST /api/v1/admin/orders/{id}/refund`（退款，全 / 部分）

**Body**:
```json
{ "amount": 500, "reason": "客服補償運費" }
```

**Auth**：`auth:user` + `order.refund` permission（議題 #7c：限 admin / super_admin）

### 5.3 ECPay Webhook 端

#### `POST /api/v1/webhooks/ecpay/payment`

**Auth**：無（公開）+ 三層防護:
1. **Signature verify**（議題 #9a）— `CheckMacValue` SHA256
2. **IP whitelist**（議題 #9c）— `APP_ENV=production` 啟用
3. **Replay defense**（議題 #9b）— App + DB UNIQUE 雙保險

**處理流程**:
1. 寫 `payment_callbacks` row（`status=received`,raw_payload 全存）
2. Verify signature → fail 設 `status=failed`,return 200 給 ECPay（不要 retry）
3. App 層查 `trade_no` → 若已 processed,設 `status=duplicate`,return 200
4. DB UNIQUE constraint → race 兜底
5. 找 order by `ecpay_trade_no` → 不存在設 `status=failed` + alert
6. `OrderService::markPaid($order, $trade_no)` → 內部 transaction：
   - 改 status pending→paid
   - 寫 status_history
   - **redeem 所有 order_coupons**（CouponService::redeem 帶 idempotency_key）
   - 觸發 `order.paid` webhook
7. 設 `status=processed`,return 200

---

## 六、ApiCode 擴充

新增 D-class（Domain：訂單 / 金流）：

```php
// D — 訂單 / 金流類
const INVALID_ORDER_STATE_TRANSITION = 'D001';   // 違反狀態機
const ORDER_NOT_REFUNDABLE           = 'D002';   // 嘗試對非 completed 訂單退款
const PAYMENT_SIGNATURE_INVALID      = 'D003';   // ECPay sig 驗證失敗
const REFUND_AMOUNT_EXCEEDS_PAID     = 'D004';   // 累計退款 > 實付
const PAYMENT_CALLBACK_DUPLICATE     = 'D005';   // 同 trade_no 重發
const ORDER_TIMEOUT                  = 'D006';   // 嘗試操作已 timeout 的 pending order
```

---

## 七、ECPay L1 整合細節

### 7.1 SDK 選擇

推薦 **`ecpay/payment` 官方 SDK**（github.com/ECPay/ECPayAIO_PHP）：
- 維護狀態：穩定
- algo 跟隨 ECPay 政策更新
- HashKey/HashIV 計算內建
- 替代方案：手刻（不推，algo 邊角易錯）

### 7.2 配置

```env
# .env
ECPAY_MERCHANT_ID=2000132
ECPAY_HASH_KEY=5294y06JbISpM5x9
ECPAY_HASH_IV=v77hoKGq4kWxNNIS
ECPAY_ENV=stage   # stage | production
ECPAY_RETURN_URL=https://crm.example.com/api/v1/webhooks/ecpay/payment
ECPAY_CLIENT_BACK_URL=https://crm.example.com/orders/result
```

```php
// config/ecpay.php
return [
    'merchant_id' => env('ECPAY_MERCHANT_ID'),
    'hash_key'    => env('ECPAY_HASH_KEY'),
    'hash_iv'     => env('ECPAY_HASH_IV'),
    'env'         => env('ECPAY_ENV', 'stage'),
    'endpoints'   => [
        'stage'      => 'https://payment-stage.ecpay.com.tw/...',
        'production' => 'https://payment.ecpay.com.tw/...',
    ],
    'return_url'      => env('ECPAY_RETURN_URL'),
    'client_back_url' => env('ECPAY_CLIENT_BACK_URL'),

    // IP whitelist（議題 #9c，prod only 啟用）
    'ip_whitelist' => [
        // ECPay 公布的 IP 範圍 — 需定期更新
        '203.66.30.0/24',
        '13.231.x.x',  // TODO: 從 ECPay 文件補完
    ],
];
```

### 7.3 Sandbox 測試憑證

ECPay 公開測試環境（`stage`）使用免費憑證：
- MerchantID：`2000132`
- HashKey：`5294y06JbISpM5x9`
- HashIV：`v77hoKGq4kWxNNIS`

→ 寫進 README，方便其他開發者開箱即用。

### 7.4 ECPayService 結構

```php
namespace App\Services\Payments\ECPay;

class ECPayService
{
    public function createPaymentForm(Order $order): string
    {
        // 用 SDK 產 ECPay form HTML（含 CheckMacValue）
    }

    public function verifyCallback(array $payload): bool
    {
        // 重算 CheckMacValue 比對
    }

    public function isAllowedIp(string $ip): bool
    {
        // production 才檢查 ip_whitelist
    }
}
```

---

## 八、Service 架構

```
app/Services/
├── Orders/
│   ├── OrderService.php              ← 主入口（create / state transition / refund）
│   ├── OrderNumberGenerator.php      ← {PREFIX}-{YYYYMMDD}-{NNNN} 生成 + 流水號 lock
│   └── Exceptions/
│       └── InvalidOrderStateTransitionException.php
├── Payments/
│   ├── ECPay/
│   │   └── ECPayService.php
│   └── Exceptions/
│       └── PaymentSignatureException.php
```

### 8.1 OrderService::create（核心 atomic）

```php
public function create(
    Member $member,
    array $items,
    array $shippingAddress,
    ?array $billingAddress,
    array $couponCodes,
    string $idempotencyKey,
    Actor $actor,           // member / user / system
): Order {
    // 1. Idempotency 預檢（同 PointService）
    if ($existing = Order::where('idempotency_key', $idempotencyKey)->first()) {
        return $existing;
    }

    return DB::transaction(function () use (...) {
        // 2. 計算 subtotal
        // 3. Verify coupons（不 redeem，paid 才 redeem）
        // 4. 套用 priority 順序，計算 discount_total
        // 5. paid_amount = subtotal - discount_total
        // 6. 檢查 paid_amount >= order_settings.min_charge_amount
        // 7. 生成 order_no（OrderNumberGenerator + lock）
        // 8. 寫 orders / order_items / order_addresses / order_coupons / status_histories
        // 9. event(new OrderCreated($order))

        return $order;
    });
    // catch QueryException 23000 → 同 PointService TOCTOU defense
}
```

### 8.2 OrderService::markPaid（ECPay webhook 觸發）

```php
public function markPaid(Order $order, string $tradeNo): Order
{
    return DB::transaction(function () use ($order, $tradeNo) {
        $locked = Order::lockForUpdate()->findOrFail($order->id);

        if ($locked->status !== 'pending') {
            throw new InvalidOrderStateTransitionException(...);
        }

        $locked->update([
            'status'         => 'paid',
            'paid_at'        => now(),
            'ecpay_trade_no' => $tradeNo,
        ]);

        // 寫 status history
        $this->recordTransition($locked, 'pending', 'paid', 'ECPay webhook');

        // Redeem 所有 coupons（按 apply_order，全部走 CouponService 既有 idempotency）
        foreach ($locked->coupons as $coupon) {
            $this->couponService->redeem(
                coupon: $coupon,
                member: $locked->member,
                idempotencyKey: "order:{$locked->id}:coupon:{$coupon->id}:redeem",
            );
        }

        event(new OrderPaid($locked));

        return $locked;
    });
}
```

### 8.3 OrderService::complete → 觸發 Points 加點

```php
public function complete(Order $order, Actor $actor): Order
{
    return DB::transaction(function () use ($order, $actor) {
        $locked = Order::lockForUpdate()->findOrFail($order->id);

        if (! in_array($locked->status, ['shipped', 'paid'])) {  // T5 + T8
            throw new InvalidOrderStateTransitionException(...);
        }

        $rate         = OrderSettings::current()->points_rate;
        $pointsToEarn = (int) floor($locked->paid_amount * $rate);

        $tx = $this->pointService->adjust(
            member:        $locked->member,
            amount:        $pointsToEarn,
            reason:        "訂單 {$locked->order_no} 完成",
            type:          'earn',
            idempotencyKey: "order:{$locked->id}:earn",
            source:        $locked,
        );

        $locked->update([
            'status'         => 'completed',
            'completed_at'   => now(),
            'points_earned'  => $pointsToEarn,
        ]);

        $this->recordTransition($locked, 'shipped', 'completed', $actor);
        event(new OrderCompleted($locked));

        return $locked;
    });
}
```

### 8.4 OrderService::refund / partialRefund → 反向 Points

```php
public function partialRefund(Order $order, int $amount, string $reason, User $admin): Refund
{
    return DB::transaction(function () use ($order, $amount, $reason, $admin) {
        $locked = Order::lockForUpdate()->findOrFail($order->id);

        if (! in_array($locked->status, ['completed', 'partial_refunded'])) {
            throw new ApiException(ApiCode::ORDER_NOT_REFUNDABLE);
        }

        $newRefundTotal = $locked->refund_amount + $amount;
        if ($newRefundTotal > $locked->paid_amount) {
            throw new ApiException(ApiCode::REFUND_AMOUNT_EXCEEDS_PAID);
        }

        // 比例倒退 Points
        $proportion       = $amount / $locked->paid_amount;
        $pointsToRefund   = (int) floor($locked->points_earned * $proportion);

        $pointTx = $this->pointService->adjust(
            member:         $locked->member,
            amount:         -$pointsToRefund,
            reason:         "訂單 {$locked->order_no} 部分退款",
            type:           'refund',
            idempotencyKey: "order:{$locked->id}:refund:" . Str::uuid(),
            source:         $locked,
        );

        $refund = Refund::create([
            'order_id'             => $locked->id,
            'amount'               => $amount,
            'type'                 => 'partial',
            'reason'               => $reason,
            'processed_by'         => $admin->id,
            'processed_at'         => now(),
            'point_transaction_id' => $pointTx->id,
        ]);

        $newStatus = $newRefundTotal === $locked->paid_amount ? 'refunded' : 'partial_refunded';
        $locked->update([
            'status'           => $newStatus,
            'refund_amount'    => $newRefundTotal,
            'points_refunded'  => $locked->points_refunded + $pointsToRefund,
        ]);

        $this->recordTransition($locked, $locked->getOriginal('status'), $newStatus, $admin);
        event(new OrderRefunded($locked, $refund));

        return $refund;
    });
}
```

---

## 九、Test 策略

### 9.1 Unit tests（OrderService）

預估 **20+ cases**：

- ✅ create 正常流程（單品 / 多品）
- ✅ create with single coupon / multi coupons
- ✅ create rejects amount < min_charge_amount
- ✅ create idempotency replay 回原 order
- ✅ create TOCTOU race（catch QueryException 23000）
- ✅ markPaid pending→paid 寫所有副作用
- ✅ markPaid 拒絕非 pending order
- ✅ markPaid 觸發 coupon redeem with idempotency_key
- ✅ ship paid→shipped + status history
- ✅ ship 拒絕非 paid order
- ✅ complete shipped→completed + 加點
- ✅ complete paid→completed（跳過 shipped，T8）
- ✅ complete idempotency_key（不重複加點）
- ✅ refund completed→refunded + 全退 Points
- ✅ partialRefund + 比例退 Points
- ✅ partialRefund 多次累加 → 達 paid_amount 自動轉 refunded
- ✅ partialRefund 拒絕 amount > paid_amount
- ✅ cancel pending→cancelled（T2）
- ✅ cancel paid→cancelled（T4）+ 反向 redeem coupons
- ✅ 違法狀態轉換拋 InvalidOrderStateTransitionException

### 9.2 Feature tests（API）

預估 **25+ cases**：

- Member endpoint 正常 / 422 / 403 / 404
- Admin endpoint 全 CRUD + RBAC 守門
- ECPay webhook：sig pass / sig fail / replay / IP whitelist（prod env mock）
- Idempotency-Key replay 回原 order
- Repay endpoint：對非 pending 訂單拒絕

### 9.3 Concurrency tests（必）

```php
public function test_concurrent_create_with_same_coupon_only_one_paid(): void
{
    // 兩個 member 同時下單帶同一張 coupon
    // create 階段 都成功（只 verify，未 redeem）
    // ECPay webhook A 進來 → markPaid → coupon redeemed
    // ECPay webhook B 進來 → markPaid → CouponService throws
    //   → markPaid 內部 catch → order B status=cancelled + auto refund
    //   → 客戶 B 收 ECPay refund + 通知
}

public function test_concurrent_ecpay_webhook_replay_is_idempotent(): void
{
    // 同一筆 ecpay_trade_no 收兩次 webhook
    // 第一次 → processed
    // 第二次 → status=duplicate（App 層 skip）+ DB UNIQUE 兜底
    // 不重複加點 / 不重複觸發 coupon redeem
}
```

### 9.4 ECPay Sandbox 整合測

設一個專門 group test（manual run，CI skip）：

```php
/**
 * @group ecpay-sandbox
 */
public function test_real_ecpay_sandbox_payment_flow(): void
{
    // 用 sandbox 憑證真的呼叫 ECPay
    // 驗證 form HTML 可被 ECPay 接受
    // 模擬 sandbox callback
}
```

CI 跑：`vendor/bin/phpunit --exclude-group=ecpay-sandbox`

---

## 十、未來擴展（待決議，Phase 2.4 / Phase 7+）

### 10.1 多 prefix 支援（對應 §9.4 多前台）

- 觸發條件：第 2 個前台需求 / 多通路 / 多活動
- 升級成本：**1 個工作天**（全 additive，order_no 歷史不變）
- 路徑：`order_settings.order_no_prefix` → 拆 `order_prefixes` 表 + `orders.order_prefix_id` FK + PrefixResolver service

### 10.2 ECPay L2（退款 API 整合）

- 觸發條件：客服流程要求自動退款（不再 manual）
- 升級成本：1.5 天
- 路徑：`refunds.ecpay_refund_no` 已留欄，補 `ECPayService::createRefund()` + 退款 webhook receiver

### 10.3 ECPay L3（多支付方式 / 失敗重試 / 對帳）

- 升級成本：3 天
- 跟 Phase 7 完整 ECPay 整合對齊

### 10.4 部分退款 Coupon 比例退

- 目前語意：partial refund 不退 coupon（券視為「已使用整張」）
- 未來若改：需要 Coupon 加 `refund_amount` snapshot + CouponService 補 `partialCancel()`
- 升級成本：1 天

### 10.5 通知（Email / SMS）

- 觸發點：order.created / paid / shipped / completed / cancelled / refunded
- 已有 webhook event,Phase 2.4 補 Notification Listener

### 10.6 Returns 真實退換貨流程

- Phase 2.3 只實作 schema + Filament 列表
- 完整流程（picked_up / quality_check / 退款連動）留 Phase 2.4 — 1-2 天

---

## 十一、Review 決議紀錄（2026-05-02 拍板）

### 議題 #1：Schema 切割

| # | 子議題 | 決議 |
|---|---|---|
| 1 | 單表 vs 多表 | **orders + order_items + order_addresses + order_status_histories**（4 主表）|
| 2 | 內部 PK | 全部 `$table->id()` + 對外 unique key |
| — | Tier 2 不加 | order_payments / order_shipments / order_refunds(改用 refunds 表) |

### 議題 #2：訂單編號

| # | 子議題 | 決議 |
|---|---|---|
| 2a | Prefix 存哪 | **B 專用 `order_settings` 單列表** |
| 2b | 編號格式 | **`{PREFIX}-{YYYYMMDD}-{NNNN}`** |
| 2c | Prefix 數量 | **A 單 global**(多 prefix 升級成本 1 天，寫進 ADR + §10.1) |

### 議題 #3：狀態機

| # | 子議題 | 決議 | 走向 |
|---|---|---|---|
| 3a | shipped → cancelled | ✅ 允許 | + returns 表 |
| 3b | paid → completed(跳 shipped)| ✅ 允許 | 數位商品 |
| 3c | 部分退款 | ✅ 支援 | + partial_refunded 狀態 + refund_amount + refunds 表 |
| 3d | 觸發者規則 | 按 §4.2 表 | T1 ECPay webhook + admin |
| 3e | Returns 表 | ✅ 新增 | §3.7 |
| 3f | Refunds 表 | ✅ 新增 | §3.8 |
| 3g | ECPay 整合範圍 | **L1**(只接 paid webhook) | §7 |
| 3h | Payment webhook idempotency | ✅ payment_callbacks 表 | §3.9 |

### 議題 #4：Points 加點

| # | 子議題 | 決議 |
|---|---|---|
| 4a | 加點時機 | **C completed 才加** |
| 4b | 計算基礎 | **B paid_amount**(折扣後) |
| 4c | 計算公式 | **A 固定 %** @ `order_settings.points_rate` |
| 4d | 退款處理 | cancel 不退 / refunded 全退 / partial_refunded 比例退,全走 PointService |

### 議題 #5：Coupon 套用

| # | 子議題 | 決議 |
|---|---|---|
| 5a | 鎖券時機 | **B paid 時鎖**(對齊 Phase 2.2 既有 FSM) |
| 5b | 多張可疊 | **✅ 多張**(+1.5 天,新增 order_coupons junction) |
| 5c | 計算策略 | **B Priority**(Coupon 加 priority 欄) |
| 5d | 疊加規則 | 同 category 限 1 張 + 折抵後 ≥ `order_settings.min_charge_amount` |

### 議題 #6：Idempotency

| # | 子議題 | 決議 |
|---|---|---|
| 6a | 策略 | **A Client `Idempotency-Key` header**(UUID v4 → DB UNIQUE) |
| 6b | Window | **A 永久**(同 PointService) |
| 6c | Failure mode | replay 回原 / fail 不重做 / in-progress 409 |
| 6d | Server-side dedup | **A 不做**(client key 是契約) |

### 議題 #7：Endpoint + RBAC

| # | 子議題 | 決議 |
|---|---|---|
| 7a | Endpoint 範圍 | **C 兩者皆可**(member + admin) |
| 7b | 兩 endpoint 差異 | 按 §5 表 |
| 7c | RBAC 6 permissions | view_any / view / create / update / cancel / refund(refund 限 admin+) |
| 7d | Admin mark as paid offline | **✅ 做** |
| 7e | Member ECPay 跳轉 | 回 form HTML + repay endpoint |

### 議題 #8：Timeout / pending cancel

| # | 子議題 | 決議 |
|---|---|---|
| 8a | Timeout 期限 | **D 設定式**,預設 30 分鐘 @ `order_settings.pending_timeout_minutes` |
| 8b | Cancel 觸發 | **A Cron 每 5 分鐘** |
| 8c | Cancel 副作用 | 寫 history + 觸發 webhook,不寄 email |
| 8d | Timeout 後重付 | **A 引導重下**(FSM immutable) |

### 議題 #9：ECPay Webhook 安全

| # | 子議題 | 決議 |
|---|---|---|
| 9a | Signature verification | **A 必驗**(CheckMacValue + ECPay SDK) |
| 9b | Replay 防禦 | **C 雙保險**(App skip + DB UNIQUE) |
| 9c | IP whitelist | **C Prod only** |

---

## 十二、實作 timeline（9~11 天）

| Day | 範圍 | 估時 |
|---|---|---|
| **Day 1** | Schema migrations(9 新表 + Coupon 補欄)+ Models + Factory | 1 天 |
| **Day 2** | OrderNumberGenerator + OrderService::create + InvalidOrderStateTransitionException + 8 unit tests | 1 天 |
| **Day 3** | OrderService 狀態轉換(markPaid / ship / complete / cancel)+ 8 unit tests | 1 天 |
| **Day 4** | OrderService refund / partialRefund + Coupon priority engine + 6 unit tests | 1 天 |
| **Day 5** | ECPayService + payment_callbacks 處理 + signature verify + IP whitelist middleware | 1 天 |
| **Day 6** | Member API endpoints(create / repay / cancel / list / detail)+ FormRequests + feature tests | 1 天 |
| **Day 7** | Admin API endpoints + Filament OrderResource + RBAC 6 permissions + RolePermissionSeeder 補 | 1 天 |
| **Day 8** | Webhook event 6 個(created / paid / shipped / completed / cancelled / refunded)+ Cron CancelPendingOrdersJob | 1 天 |
| **Day 9** | Concurrency tests + ECPay sandbox 整合測 + Swagger annotations | 1 天 |
| **Day 10** | ADR-0006 + session log + bug fix buffer | 1 天 |
| **buffer** | edge case + review | 1 天 |
| **合計** | | **9–11 天** |

---

## 十三、潛在風險與踩坑預想

| 風險 | 預防 |
|---|---|
| ECPay sandbox 連不到 / 憑證過期 | Day 5 啟動前先 manual smoke test |
| Coupon redeem 在 markPaid 內失敗 → 訂單該怎辦 | Day 3 寫明:cancel order + auto refund(L1 階段標記 manual)|
| Concurrency test 在 SQLite 不會真 lock | 強制 MySQL test DB(已是) |
| `order_no` 流水號跨日歸零 race | OrderNumberGenerator 用 `lockForUpdate` 在 sequence 表 / 或 atomic SELECT MAX +1 重試 |
| ECPay webhook 進來時 order 還沒寫完 commit | markPaid 找不到 order → status=failed + 寫 alert,等下次重發或 manual 處理 |
| Filament 後台改訂單繞過 OrderService | OrderResource override save / mutate 強制走 Service,不直接 update model |
| Refund 比例計算 floor 後精度損失 | Document:partial refund 多次累加可能少退 1-2 點,屬已知行為 |
| ECPay IP list 過時 | config/ecpay.php 註解寫明「定期 review ECPay 文件」+ 可選一個 cron 每月提醒 |

---

## 十四、跟既有模組的接點

### Points(Phase 2.1)
- ✅ 全部走 `PointService::adjust`,不直接動 PointTransaction
- ✅ Idempotency_key pattern:`order:{order_id}:earn` / `order:{order_id}:refund:{uuid}`
- ✅ Order 是 polymorphic source(`source_type=App\Models\Order`,`source_id=order.id`)

### Coupon(Phase 2.2)
- ✅ 全部走 `CouponService::redeem` / `cancel`,不直接改 Coupon row
- ✅ Idempotency_key pattern:`order:{order_id}:coupon:{coupon_id}:redeem`
- ✅ Order 是 polymorphic source
- ⚠️ Coupon 表補 `priority` + `category` 兩欄(Phase 2.2 結構小擴充)

### Webhook(既有)
- ✅ 6 個新 event 對齊既有 WebhookEvent interface(`toWebhookPayload()`)
- ✅ DispatchWebhook listener 自動處理

### RBAC(既有)
- ✅ 6 個新 permission 寫進 `RolePermissionSeeder::baselinePermissions()`
- ✅ Filament OrderResource canViewAny / canCreate / 等走 spatie

### ECPay(本次首次落地)
- 🆕 `app/Services/Payments/ECPay/ECPayService.php` 新 namespace
- 🆕 `config/ecpay.php` 新 config
- 🆕 `.env` 新 5 個變數

---

## 十五、驗收清單

- [ ] 9 個 migrations + Coupon 補欄 migration 跑得起來(local + CI)
- [ ] OrderService 20+ unit tests 全綠
- [ ] Member + Admin endpoints feature tests 25+ cases 全綠
- [ ] Concurrency tests:同 coupon 兩單 / webhook replay / order_no race
- [ ] ECPay sandbox 整合測試(manual,group=ecpay-sandbox)
- [ ] PHPStan 不破 baseline(level 5,~10 errors)
- [ ] ESLint(前端)新增 Order UI 不破 lint
- [ ] Filament UI:訂單列表 + 詳情 + 操作按鈕(出貨/取消/退款) + 狀態 badge
- [ ] Webhook 6 個 event 能被 subscriber 收到
- [ ] Permission:`customer_support` 能 create/cancel,不能 refund(沒 `order.refund`)
- [ ] Cron `CancelPendingOrdersJob` 在 Laravel Scheduler 註冊
- [ ] ECPay IP whitelist middleware:dev env skip,prod env enforce
- [ ] Swagger annotations 涵蓋全 endpoints
- [ ] ADR-0006 寫完歸檔 work_log/

---

## References

- SENIOR_ROADMAP.md Phase 2.3 段
- POINTS_INTEGRATION_PLAN.md(對齊 atomic / idempotency / source pattern)
- COUPON_INTEGRATION_PLAN.md(對齊 state machine / lockForUpdate)
- ADR-0004(Points concurrency design)
- ADR-0005(Coupon state machine)
- ECPay 官方文件:https://www.ecpay.com.tw/Service/API_Dwnld
- ECPay PHP SDK:https://github.com/ECPay/ECPayAIO_PHP
- ECPay sandbox endpoint:https://payment-stage.ecpay.com.tw/
- spatie/laravel-permission v6
- Stripe Idempotent Requests:https://stripe.com/docs/api/idempotent_requests
- ARCHITECTURE.md(Filament-first / Policy-first 紀律)
