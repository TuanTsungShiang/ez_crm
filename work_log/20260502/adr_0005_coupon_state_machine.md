# ADR-0005: Coupon 狀態機、原子性與設計決策

## Status
Accepted (2026-05-02)

## Context

Phase 2.2 Coupon 系統的核心挑戰：
1. **狀態機完整性**：expired 的券不能被 redeem，redeemed 不能再 redeem
2. **Race condition**：兩個並發 request 同時 redeem 同一張券，只有一個成功
3. **Points 整合**：type=points 的 Coupon 核銷時要走 PointService（保留其冪等性和 audit log）

## Decision

### 1. 狀態機（4 個狀態）

```
created → redeemed (via redeem)
created → expired  (at redeem-time, detected from batch.expires_at)
redeemed → cancelled (via cancel)
```

**為什麼 expired 在 redeem-time 偵測，不靠 cron**：
Phase 2.5 才做自動過期 cron；Phase 2.2 在有人 try redeem 時順便標記 expired，是最小實作。
`CouponExpiredException` 語意比 `InvalidCouponStateException` 更明確，讓 client 知道是時間問題。

### 2. Pre-transaction expiry check（fast-fail）

```php
// 在 transaction 外先確認
$pre = Coupon::where('code', ...)->with('batch')->firstOrFail();
if ($pre->batch->isExpired() && $pre->isCreated()) {
    $pre->update(['status' => STATUS_EXPIRED]);
    throw new CouponExpiredException($pre->code);
}

// 進 transaction 才 lockForUpdate
return DB::transaction(function () use (...) {
    $coupon = Coupon::where('code', ...)->lockForUpdate()->firstOrFail();
    $this->assertRedeemable($coupon, $member);
    // ...
});
```

**為什麼 expiry 在 transaction 外處理**：
若在 `DB::transaction` 內 `update(['status' => 'expired'])` 後拋出例外，transaction 會 rollback，expired 標記也被 rollback。所以 expiry mark 必須在 transaction 外才能持久化。

**為什麼 lockForUpdate 仍在 transaction 內**：
lockForUpdate 的目的是防止兩個並發 redeem 同時讀到 status='created'。expiry 已在外層確認過，transaction 只保護 status 轉換的 atomicity。

### 3. Points 整合語意

type=points 的 coupon 核銷時呼叫 `PointService::adjust`（idempotency_key = `coupon-redeem-{id}`）：
- 繼承 PointService 的所有保證（lockForUpdate、audit log、不允許負餘額）
- idempotency_key 固定，若 coupon 核銷因某原因重試，Points 不會被重複加

cancel 時反向呼叫 `adjust(amount: -value, type: 'refund', key: "coupon-cancel-{id}")`：
- 退款不需要 refund > current balance 的檢查（admin 操作，不是會員扣點）

### 4. 代碼格式：EZCRM-XXXX-XXXX

4+4 隨機 A-Z0-9，理論空間 `36^8 ≈ 2.8 兆`，遠超 10,000 的單批次上限。
生成時有 DB 查詢去重，但碰撞率極低（< 1/億），不需 retry loop 以外的防護。

### 5. Coupon vs coupon_batches 分離

**為什麼分兩張表**：
- batch = 活動設定（行銷人員操作，不常變）
- coupon = 個別代碼（每次核銷都寫一次）
- 分離後 `coupon.batch_id FK` 保持設定與使用紀錄的 audit trail 獨立

**為什麼 type/value 在 batch 不在 coupon**：
同一批次的所有券有相同折扣，不需要 per-code 差異化。若未來需要 per-code 差異，加 coupon.value nullable override 即可（forward-compatible）。

## Consequences

**正面**：
+ 狀態機所有非法轉換都有 exception + C001/C002 ApiCode
+ concurrent redeem 只有一個成功（lockForUpdate + state check）
+ Points 整合走既有 PointService，不重複造輪子
+ expiry 在 redeem-time 偵測，Phase 2.5 cron 只是加速偵測，不是必要

**負面 / 注意**：
- `cancelled` 是 terminal state，不能「恢復」成 created（業務需要就要建新券）
- expiry 標記是 lazy（只有 try redeem 時才標記），大量過期券的清理要靠 Phase 2.5 cron
- max_per_member 限制（一人限用幾次）留 Phase 2.5 實作

## References

- `app/Services/Coupon/CouponService.php`
- `tests/Unit/Services/CouponServiceTest.php`（16 cases）
- `tests/Feature/Api/V1/CouponApiTest.php`（19 cases）
- ADR-0004（Points 系統 — lockForUpdate / idempotency 相同模式的參考）
