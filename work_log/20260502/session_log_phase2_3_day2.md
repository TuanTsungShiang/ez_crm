# ez_crm 工作日誌 — 2026-05-02（Phase 2.3 Order Day 2~4）

> 協作：Kevin + Claude Code（Sonnet 4.6）
> 主題：Phase 2.3 OrderService 完整 FSM + 20 unit tests
> 接續：`session_log_phase2_3_day1.md`（同日稍早完成 schema + models）

---

## 過程概覽

原計畫 Day 2 / 3 / 4 分別是：
- Day 2：OrderNumberGenerator + OrderService::create + 8 tests
- Day 3：markPaid / ship / complete / cancel + 8 tests
- Day 4：refund / partialRefund + Coupon priority engine + 6 tests

實際一個 session 全部做完，因為 Points / Coupon 的模式已熟，三段邏輯的核心設計一脈相承。

---

## 1. Exceptions + ApiCode 擴充

### `app/Exceptions/Order/InvalidOrderStateTransitionException.php`

```php
public function __construct(
    public readonly string $currentStatus,
    public readonly string $attemptedAction,
) {
    parent::__construct("Cannot perform '{$attemptedAction}' on order with status '{$currentStatus}'");
}
```

設計重點：`currentStatus` / `attemptedAction` 作為 public readonly property，讓上層 ExceptionHandler 可以從 exception 取出 context 填入 API response 的 `details`，不需要解析 message 字串。

### `app/Exceptions/Order/RefundAmountExceedsPaidException.php`

承載兩個整數（requested / remaining），讓前端可以顯示「你請求退 $X，剩餘可退 $Y」。

### `app/Enums/ApiCode.php` — 新增 D-class

| Code | 意義 |
|---|---|
| D001 | INVALID_ORDER_STATE_TRANSITION |
| D002 | ORDER_NOT_REFUNDABLE |
| D003 | PAYMENT_SIGNATURE_INVALID |
| D004 | REFUND_AMOUNT_EXCEEDS_PAID |
| D005 | PAYMENT_CALLBACK_DUPLICATE |
| D006 | ORDER_BELOW_MIN_CHARGE |

---

## 2. OrderNumberGenerator

```
格式：{PREFIX}-{YYYYMMDD}-{NNNN}
範例：EZ-20260502-0001
```

### 競爭安全設計

```php
$maxNo = Order::where('order_no', 'like', "{$prefix}-{$date}-%")
    ->lockForUpdate()
    ->max('order_no');
```

- `SELECT MAX ... lockForUpdate()` 在 transaction 內 → 同一時間只有一個 request 能取得 max 值
- `orders.order_no` 有 UNIQUE constraint → 萬一 lock 外還有邊角 race，DB 最終守門
- 最多重試 5 次；fallback 加 microseconds（極端罕見）

---

## 3. OrderService — 完整 FSM

### 狀態機合法轉換（8 條）

| T# | From → To | 副作用 |
|---|---|---|
| T1 | pending → paid | 鎖倉 + redeem coupons |
| T2 | pending → cancelled | 無（coupon 尚未 redeem）|
| T3 | paid → shipped | 寫 shipped_at |
| T4 | paid → cancelled | 反向 cancel coupons |
| T5 | shipped → completed | 加 Points（paid_amount × rate）|
| T6a | completed → refunded | 全退 Points 比例倒退 + 寫 Refund row |
| T6b/c/d | completed/partial_refunded → partial_refunded/refunded | 部分退款累加 |
| T7 | shipped → cancelled | 同 T4 |
| T8 | paid → completed | 跳過 shipped（數位商品）|

### Coupon priority engine（create 時計算）

```
resolveCoupons → sortBy(priority ASC, id ASC)
→ 同 category 限 1 張
→ 每張計算折扣後驗 min_charge_amount floor
→ snapshot 存進 order_coupons.discount_applied
```

Coupon 在 `create` 時**只 verify，不 redeem**；`markPaid` 時才真正 redeem。

### Idempotency 雙保險（對齊 PointService）

```php
// Fast-fail 在 transaction 外
if ($existing = Order::where('idempotency_key', $key)->first()) {
    return $existing;
}

// Transaction 內 catch 23000 → TOCTOU 兜底
try {
    $order = Order::create([...]);
} catch (QueryException $e) {
    if (str_contains($e->getMessage(), '23000')) {
        return Order::where('idempotency_key', $key)->firstOrFail();
    }
    throw $e;
}
```

### markPaid 的 coupon redeem 冪等

```php
foreach ($locked->coupons as $coupon) {
    try {
        $this->couponService->redeem($coupon->code, $locked->member);
    } catch (InvalidCouponStateException) {
        // Already redeemed (webhook replay) — idempotent, skip
    }
}
```

CouponService::redeem 本身有 lockForUpdate + status guard，直接用 try/catch 包而不重造。

### refund 的比例計算

```php
$proportion     = $locked->paid_amount > 0 ? $amount / $locked->paid_amount : 0;
$pointsToRefund = (int) floor($locked->points_earned * $proportion);
```

`floor` 有意為之：多次 partial refund 累積可能少退 1~2 點（精度捨入），這是已知行為，不是 bug，記錄在 ORDER_INTEGRATION_PLAN §13。

---

## 4. Unit Tests（20 cases）

| 分組 | 測試 | 重點 |
|---|---|---|
| create | totals / items+addresses / status history | snapshot 凍結驗証 |
| create coupon | single / multi priority / idempotency | 優先度引擎 + one-per-category |
| markPaid | pending→paid / redeem coupons / 拒非 pending | 副作用全覆蓋 |
| ship | paid→shipped / 拒非 paid | FSM 守門 |
| complete | shipped→completed + points / paid→completed(T8) / 冪等不重複加點 | Points 整合 |
| cancel | pending→cancelled / 拒 completed | T2 |
| refund | full + reverse points / partial + partial_refunded / 超額拋錯 | T6a/b |
| FSM violation | pending→ship 拋 InvalidOrderStateTransitionException | 非法轉換 |

### 典型 assertion pattern

```php
// 2000 × 1% = 20 points
$this->assertSame(20, $completed->points_earned);
$member->refresh();
$this->assertSame(20, $member->points);
```

---

## 驗證

```
php artisan test        → 304 tests / 919 assertions ✅
PHPStan level 5         → 0 errors ✅
```

---

## 數字

| 項目 | 數量 |
|---|---|
| 今日 commits（本 batch）| 1（feat）|
| 新 Service 檔 | 2（OrderNumberGenerator / OrderService）|
| 新 Exception 檔 | 2 |
| 新 unit tests | 20 |
| 新增程式碼 | ~700 行 |
| 累計 tests | 304 |

---

## 對應 commits

```
aba1092 feat(order): Phase 2.3 Day 2 — OrderService + OrderNumberGenerator + 20 unit tests
```

---

## 下一步（Day 5）

按 ORDER_INTEGRATION_PLAN timeline：

| Day | 範圍 |
|---|---|
| ~~Day 2~~ ✅ | OrderNumberGenerator + OrderService FSM |
| ~~Day 3~~ ✅ | markPaid / ship / complete / cancel（含在同一 commit）|
| ~~Day 4~~ ✅ | refund / coupon priority engine（含在同一 commit）|
| **Day 5** | ECPayService + payment_callbacks + signature verify + IP whitelist |
| Day 6 | Member API endpoints + FormRequests + feature tests |
| Day 7 | Admin API + Filament OrderResource + RBAC 6 permissions |

---

## 個人觀察

**Day 2~4 壓縮成 1 session 的原因**：

- `OrderNumberGenerator` 完全對應 `PointService` 的 `lockForUpdate` 模式
- `OrderService::create` idempotency 直接抄 `PointService::adjust` 的雙保險結構
- FSM guard 只是 `if (!in_array($status, $allowed)) throw` — pattern 已熟
- Coupon priority engine 是新東西，但因為 `CouponService::verify` 已有驗証，priority 計算本身不複雜

真正的新挑戰是 **Day 5 ECPay**：CheckMacValue 演算法、webhook replay 防禦、IP whitelist — 全是第一次實作，節奏會慢一些。
