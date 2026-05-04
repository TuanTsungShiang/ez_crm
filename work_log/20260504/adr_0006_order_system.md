# ADR-0006: Order 系統的 FSM、ECPay L1 整合與設計決策

## Status

Accepted (2026-05-04)

## Context

Phase 2.3 Order 是 Phase 2 鐵三角的壓軸,銜接 Points + Coupon + 真實金流。同時面對 5 個核心挑戰:

1. **State machine 完整性**:6 個狀態（pending / paid / shipped / completed / cancelled / partial_refunded / refunded）+ 8 條合法轉換,**禁止逆向 + 不允許 cancelled/refunded 復活**(對齊 ARCHITECTURE.md immutable 精神)
2. **Race condition**:
   - 客戶端雙擊 / 網路 retry → 重複下單
   - ECPay 重發 webhook → 重複加點 / 重複 redeem coupon
   - 兩個並發 redeem 同一張 coupon
3. **金流真實性**:第一次串接 ECPay,signature / replay / IP 三層防禦不能少
4. **多券疊加**:`order_coupons` junction + Priority engine + 同 category 限 1 張 + 折抵後 ≥ min_charge
5. **退款語意**:partial / full 兩種,Points 必須**比例**倒退,Coupon 全消耗(不退)

## Decision

### 1. State machine — 顯式 transition table + immutable history

```
pending  → paid             (T1: ECPay webhook / admin manual)
pending  → cancelled        (T2: member / admin / system timeout)
paid     → shipped          (T3: admin manual)
paid     → cancelled        (T4: + reverse coupon redeem)
paid     → completed        (T8: 數位商品跳過 shipped)
shipped  → completed        (T5: + earn points)
shipped  → cancelled        (T7: admin only,触發 returns 流程)
completed → refunded        (T6a: full refund)
completed → partial_refunded(T6b: partial refund)
partial_refunded → partial_refunded (T6c: 累加)
partial_refunded → refunded (T6d: refund_amount = paid_amount 自動轉)
```

**為什麼用顯式 transition table 而非 spatie/laravel-model-states**:6 個狀態 + 8 條轉換是手刻 case 分析的甜蜜點。引入 spatie 套件等於再學一層 DSL,senior 訊號反而被「我會用 spatie」稀釋成「我會 require composer 套件」。`OrderService` 的 6 個 public method(create / markPaid / ship / complete / cancel / refund)就是 transition guard,**case + early throw 比配置式狀態機更可讀**。

**為什麼 cancelled / refunded 不允許復活**:對齊 ARCHITECTURE.md「不接受『先做完再回來補』」的精神。`order_status_histories` append-only,無 update;客戶想復活 = 開新單,不污染歷史。

**為什麼 `order_status_histories` 沒有 updated_at**:`public const UPDATED_AT = null` — 結構上強制 append-only,沒有「改過的歷史紀錄」這種事。

### 2. Atomic transaction + lockForUpdate(複用 Points/Coupon 既有模式)

```php
public function markPaid(Order $order, ...): Order
{
    return DB::transaction(function () use (...) {
        $locked = Order::lockForUpdate()->findOrFail($order->id);
        if ($locked->status !== STATUS_PENDING) {
            throw new InvalidOrderStateTransitionException(...);
        }
        // status update + history + coupon redeem + event ALL in one commit
    });
}
```

**為什麼跟 PointService::adjust / CouponService::redeem 同模式**:Phase 2.1/2.2 已經建立的「Service-only writes + lockForUpdate + transaction wrap」是組織級紀律。Phase 2.3 直接套用,**不重新發明 atomic 設計**。

### 3. Idempotency — Client header → DB UNIQUE backstop + TOCTOU defense

下單流程:
- Client 帶 `Idempotency-Key: {uuid-v4}` header
- `OrderService::create` 先 fast-fail SELECT (transaction 外)
- 進 transaction → INSERT orders with idempotency_key
- 若 23000(兩個 client 同 key 並發) → catch QueryException → 回原 order

```php
try {
    $order = Order::create([..., 'idempotency_key' => $idempotencyKey]);
} catch (QueryException $e) {
    if (str_contains($e->getMessage(), '23000')) {
        return Order::where('idempotency_key', $idempotencyKey)->firstOrFail();
    }
    throw $e;
}
```

**為什麼 fast-fail 在 transaction 外**:replay 的常見情況不該擋住其他 active create — transaction 外 SELECT 不持鎖,延遲零。

**為什麼仍要 transaction 內 catch 23000**:fast-fail SELECT 跟 INSERT 之間有 TOCTOU window — 兩個並發 request 都 SELECT 不到,都進 transaction,只有一個 INSERT 成功,另一個 catch 23000 回原 row。**這是 senior 跟 junior 的分水嶺** — junior 只做 fast-fail,以為夠;senior 知道 fast-fail 不 atomic,DB UNIQUE 是最後防線。

### 4. Snapshot pattern — 凍結交易瞬間的所有外部狀態

| 表 | 為什麼 snapshot |
|---|---|
| `order_items` | product 價格 / 名稱會變,商品會下架 |
| `order_addresses` | member.profile 之後可能改地址 |
| `order_coupons.discount_applied` | coupon 規則之後可能改,但歷史折扣金額不能變 |
| `orders.points_earned` | rate 之後可能改,但歷史加點不能重算 |
| `payment_callbacks.raw_payload` | ECPay 原始 payload 永存,法務 / 對帳 / debug 全靠它 |

**核心原則**:**boundary state must be frozen at write-time**。任何「之後可能變」的外部資料都要 snapshot,絕不 join live table。

### 5. Service-only writes(三層紀律)

- **Model 層**:Order / Refund / OrderStatusHistory 等的 docblock 寫明「NEVER write directly」
- **Service 層**:OrderService 是唯一入口
- **Filament 層**:OrderResource override `mutateFormDataBeforeSave / save` 走 Service,不直接 update model

### 6. ECPay L1 三層安全

```
1. EcpayIpWhitelistMiddleware       ← Layer 1 (來源驗證,prod only)
   ↓
2. ECPayService::verifyCallback()   ← Layer 2 (內容驗證,CheckMacValue SHA256)
   ↓
3. handleCallback() 雙保險           ← Layer 3 (replay 防禦)
   - INSERT first, catch 23000     ← DB UNIQUE on (provider, trade_no, callback_time)
   - 若 INSERT 成功,App layer 查    ← SELECT existing processed row
     status=processed → mark duplicate
```

**為什麼三層而非單層**:任一層失守不會造成嚴重後果(IP 假造但 sig 對不上 → 擋住;sig 對但 trade_no 已 process → 擋住)。**深度防禦,正交設計**。

**為什麼 IP whitelist 只 prod 啟用**:test / dev 環境經常需要 mock callback。`EcpayIpWhitelistMiddleware` 內建 `app()->environment('production')` 判斷,不需要每次測試都 disable。

**為什麼 CheckMacValue 手刻而非用 ECPay PHP SDK**:展示懂 signing 原理(URL encoding restore + SHA256 lowercase + 字元順序)— SDK 黑盒對 senior 訊號減分。手刻 ~40 行 + EcpayWebhookTest 的 sig fail case 驗證,維護成本可接受。

**為什麼 webhook controller 永遠回 200 "1|OK"**:ECPay 協議要求 — 任何非 200 = 重發。簽名失敗 / 找不到 order / duplicate 都要回 200,**錯誤紀錄走 PaymentCallback.status 跟 log,不靠 HTTP 回 4xx/5xx**。違反直覺但是金流業界常規。

### 7. Coupon priority engine

```php
private function applyCouponPriority(Collection $coupons, int $subtotal): array
{
    $sorted = $coupons->sortBy(fn (Coupon $c) => [$c->priority, $c->id]);
    $usedCategories = [];
    $remaining = $subtotal;

    foreach ($sorted as $coupon) {
        if (in_array($coupon->category, $usedCategories, true)) continue;  // 同 category 限 1
        $discount = $this->computeCouponDiscount($coupon, $remaining);

        $afterDiscount = $remaining - $discount;
        if ($afterDiscount < $settings->min_charge_amount && $afterDiscount > 0) {
            $discount = $remaining - $settings->min_charge_amount;  // cap
        }
        if ($discount <= 0) continue;

        $plan[] = ['coupon' => $coupon, 'discount' => $discount];
        $usedCategories[] = $coupon->category;
        $remaining -= $discount;
    }
    return [$totalDiscount, $plan];
}
```

**為什麼 priority + category limit 都做**:真實電商 coupon engine 標配,光 priority 不夠(會兩張折扣券疊),光 category 不夠(沒 priority 順序)。**兩個結合才完整**。

### 8. Points 整合 — completed-only earn,paid_amount-based,比例 refund

- **completed 才加點**:對齊 PChome / momo / Amazon 主流。`pending → cancelled` / `paid → cancelled` 路徑**完全不需要退點**(沒加過),簡化 cancel 路徑邏輯。
- **基於 paid_amount(折扣後)**:防止「滿千送百券買 1000 元 → 實付 900 → 仍按 1000 算 10 點」套利。Senior 訊號:**懂業務套利風險**。
- **比例 refund**:`partial_refund` 時 `points_to_refund = floor(points_earned × refund_amount / paid_amount)`,走 `PointService::adjust(amount=-X, type=refund, source=$order)`,**完全複用 PointService 既有 idempotency / audit / lockForUpdate**。

### 9. Timeout cron — FSM 紀律延伸到背景任務

```php
// CancelPendingOrdersJob
Order::where('status', 'pending')
    ->where('created_at', '<', now()->subMinutes($settings->pending_timeout_minutes))
    ->each(fn ($o) => $orderService->cancel($o, actor: null, reason: 'pending_timeout'));
```

**為什麼 cron 走 OrderService::cancel 而不是直接 update**:同 §5 原則 — 即使是系統觸發,也要走 Service 寫 status_history(`actor_type=system`,reason 帶 timeout 分鐘數)+ 觸發 `order.cancelled` webhook。

**為什麼 30 分鐘可配置**:`order_settings.pending_timeout_minutes` 後台可改 — Black Friday 改寬鬆 / 平常嚴格,不需 deploy。

## Lessons Learned

### L1: MySQL TIMESTAMP 隱式 ON UPDATE CURRENT_TIMESTAMP(實作 Day 9 → Day 10 hotfix)

`$table->timestamp('callback_time')` 看起來無害,實際因為 MySQL `explicit_defaults_for_timestamp=OFF` 預設,**第一個無顯式 default 的 TIMESTAMP 欄位會自動加 `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`**。導致每次 `$cb->update(['status' => ...])` 都把 callback_time 蓋成 NOW,**replay 防禦的 DB UNIQUE 失效**。

**Fix migration**:`ALTER TABLE payment_callbacks MODIFY callback_time DATETIME NOT NULL`(DATETIME 沒這個 legacy 行為)。

**Takeaway**:**snapshot 用途的時間欄位用 DATETIME**,record-lifecycle 用途的(created_at / updated_at)才用 TIMESTAMP。被 `EcpayWebhookTest::test_replay_same_timestamp_db_unique_prevents_second_insert` 抓到 — concurrency test 真的有用。

### L2: 多券 priority engine 的非顯然複雜度

最初以為 priority + category limit 是直觀,實作時才發現:
- 折抵後最低金額 cap 邏輯(若超過要 cap,而非整張券作廢)
- discount=0 時要 skip(不要在 plan 留無效 entry)
- 多券順序的 `apply_order` 必須 snapshot(讓事後可重現計算)

**Takeaway**:看似簡單的業務邏輯實作上有很多邊界,單元測試覆蓋每個 edge case 才能放心。

### L3: ECPay 「永遠回 200」反直覺但必要

第一直覺是「sig 失敗應該回 401」,但 ECPay 收到非 200 會無限重發 → DB 充滿 status=failed 的 callback row。**正確做法**:回 200 + 內部記 `PaymentCallback.status='failed'` + log warning。

**Takeaway**:webhook 協議要看廠商規範,不能套通用 REST 直覺。

## Consequences

### 正面
- ✅ Phase 2 鐵三角整段封閉,Order 銜接 Points + Coupon + 真實 ECPay
- ✅ 9 議題 30+ 子決議全部 ADR 化,portfolio 讀者有完整設計脈絡
- ✅ 352 tests passed / PHPStan baseline 不破 / 1 hotfix 抓到 senior-level DB gotcha
- ✅ 完整 senior 訊號矩陣:FSM + atomic + idempotency + snapshot + 三層金流防禦 + 比例 refund

### 中性 / 待觀察
- ⚠️ ECPay L1 只接 paid webhook;refund 走 admin manual + ECPay 後台。L2 自動退款 API 留 Phase 2.4 / Phase 7。
- ⚠️ Returns 表有 schema 但流程簡化;真實退換貨的 picked_up / quality_check 留 Phase 2.4。
- ⚠️ 多 prefix(`order_settings.order_no_prefix` 單欄)— 多前台需求出現時要升級為 `order_prefixes` 表,估時 1 天 additive(對應 §9.4 多前台研究)。

### 負面
- ❌ partial refund 的 Coupon 不退(全消耗)— 真實電商部分退款應按比例退 coupon 折抵,目前語意「coupon 用了就用了」。Phase 2.4 補。
- ❌ Email / SMS 通知未做 — webhook event 已 ready,Phase 2.4 補 Notification listener。
- ❌ ECPay IP whitelist 是寫死 config,IP 變動需手動更新 — Phase 7 加 cron 拉 ECPay 公布 list。

## Future Evolution

詳見 `ORDER_INTEGRATION_PLAN.md §10`:

| # | 主題 | 觸發條件 | 估時 |
|---|---|---|---|
| 10.1 | 多 prefix(對應多前台)| 第 2 個前台需求 | 1 天 additive |
| 10.2 | ECPay L2 退款 API | 客服流程要求自動退款 | 1.5 天 |
| 10.3 | ECPay L3(多支付方式 / 重試 / 對帳)| 真實 production | 3 天 |
| 10.4 | Coupon partial refund 比例退 | 客戶體驗投訴 | 1 天 |
| 10.5 | Email / SMS 通知 | 客戶體驗 | 1 天 |
| 10.6 | Returns 真實退換貨流程 | 真實退貨需求 | 1-2 天 |

## References

- `ORDER_INTEGRATION_PLAN.md` v1.0 (Accepted 2026-05-02)
- ADR-0001(RBAC baseline)
- ADR-0004(Points concurrency design)
- ADR-0005(Coupon state machine)
- ARCHITECTURE.md(Filament-first / Policy-first / FSM-immutable 紀律)
- MySQL `explicit_defaults_for_timestamp`:https://dev.mysql.com/doc/refman/8.0/en/server-system-variables.html#sysvar_explicit_defaults_for_timestamp
- ECPay 永遠回 200 協議:https://www.ecpay.com.tw/Service/API_Dwnld
