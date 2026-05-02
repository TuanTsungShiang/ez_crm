# Coupon 優惠券系統 整合計畫（Phase 2.2）

> 版本：v1.0（Accepted）
> 建立日期：2026-05-02
> 狀態：✅ Accepted — 直接進入實作
> 對應 Roadmap：SENIOR_ROADMAP Phase 2.2
> 前置依賴：Phase 2.1 Points ✅

---

## 一、為什麼做

Phase 2.2 展示的 senior 能力：**狀態機設計 + 併發防護**。

- ❌ Junior 寫法：`UPDATE coupons SET status='redeemed'` — 沒 lock，沒狀態檢查，沒 race protection
- ✅ Senior 寫法：State machine + lockForUpdate + 非法轉換拋 Exception + 反向 Points refund

Phase 2 順序：Points（獨立）→ **Coupon（消耗 Points）**→ Order（觸發兩者）

---

## 二、範圍切割

### In scope（Phase 2.2）
- ✅ `coupon_batches` 表（活動批次：類型、折扣值、有效期、數量）
- ✅ `coupons` 表（個別代碼：狀態機、lockForUpdate）
- ✅ `CouponService`：createBatch / verify / redeem / cancel
- ✅ 狀態機：`created → redeemed → cancelled`，`created → expired`
- ✅ 4 個 API endpoint（create / verify / redeem / cancel）
- ✅ Points 整合：`type=points` 核銷時走 `PointService::adjust`
- ✅ 取消核銷自動退回 Points（`type=points` 反向 refund）
- ✅ Webhook event `coupon.redeemed`
- ✅ Filament UI（CouponBatch 管理 + Coupon 列表）
- ✅ Feature tests + Concurrency tests

### Out of scope（留後續 Phase）
- ❌ 前台會員自己兌換 Coupon（等 Order Phase）
- ❌ 自動過期 cron job（Phase 2.5 一起做）
- ❌ Coupon 限制：每人限用 N 次（max_per_member > 1 的複雜邏輯）
- ❌ 批次匯出 / CSV 下載

---

## 三、Schema 設計

### 3.1 `coupon_batches`（活動批次）

```php
Schema::create('coupon_batches', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->string('name', 100);
    $table->text('description')->nullable();
    $table->enum('type', ['discount_amount', 'discount_percent', 'points'])
          ->comment('discount_amount=折抵金額 / discount_percent=折扣% / points=兌換點數');
    $table->unsignedInteger('value')
          ->comment('discount_amount=NT元整數 / discount_percent=百分比整數 / points=點數整數');
    $table->unsignedInteger('quantity')->comment('批次產生的券數');
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('starts_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();
});
```

### 3.2 `coupons`（個別代碼，主菜）

```php
Schema::create('coupons', function (Blueprint $table) {
    $table->id();
    $table->string('code', 32)->unique();          // EZCRM-XXXX-XXXX
    $table->foreignId('batch_id')->constrained('coupon_batches')->cascadeOnDelete();
    $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();  // null = 通用券

    $table->enum('status', ['created', 'redeemed', 'cancelled', 'expired'])
          ->default('created');

    $table->foreignId('redeemed_by')->nullable()->constrained('members')->nullOnDelete();
    $table->timestamp('redeemed_at')->nullable();

    $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('cancelled_at')->nullable();

    $table->json('meta')->nullable();
    $table->timestamps();

    $table->index(['status', 'created_at']);
    $table->index('batch_id');
});
```

---

## 四、狀態機

```
           ┌─── verify ───┐ (read-only 驗證，不改狀態)
           │               │
  [created] ──── redeem ──→ [redeemed] ──── cancel ──→ [cancelled]
       │
       └──→ [expired]  (排程 cron 或 redeem 時檢測 expires_at)
                ✗ 不可 redeem
```

**合法轉換：**
| 起始狀態 | 動作 | 目標狀態 |
|---|---|---|
| created | redeem | redeemed |
| created | (expires_at 過期) | expired |
| redeemed | cancel | cancelled |

**非法轉換（拋 InvalidCouponStateException）：**
- redeemed → redeem（已核銷）
- cancelled → redeem（已取消）
- expired → redeem（已過期）
- created → cancel（尚未核銷）
- redeemed/cancelled/expired 之間互轉

---

## 五、API 規格

### 5.1 `POST /api/v1/coupons`（建立批次）

**Auth**: `auth:sanctum` | **Permission**: `coupon.manage`

**Request**:
```json
{
  "name": "五一勞動節感謝券",
  "type": "discount_amount",
  "value": 100,
  "quantity": 500,
  "expires_at": "2026-06-30T23:59:59+08:00",
  "description": "限時折抵 NT$100"
}
```

**Response 201**: 批次資料 + 前 5 個範例 code

### 5.2 `POST /api/v1/coupons/{code}/verify`

**Auth**: `auth:sanctum` | **Permission**: `coupon.view`
**用途**: 核銷前確認有效性（不改狀態）

**Response 200**: coupon 詳情 + batch 資訊
**Response 422 C001**: 非 created 狀態
**Response 422 C002**: 已過期
**Response 404**: 不存在

### 5.3 `POST /api/v1/coupons/{code}/redeem`

**Auth**: `auth:sanctum` | **Permission**: `coupon.manage`

**Request**:
```json
{ "member_uuid": "8da9df..." }
```

**Response 200**: 核銷結果 + points_awarded（type=points 時）
**Response 422 C001**: 狀態不允許
**Response 422 C002**: 已過期

### 5.4 `POST /api/v1/coupons/{code}/cancel`

**Auth**: `auth:sanctum` | **Permission**: `coupon.manage`

**Response 200**: 取消結果 + points_refunded（type=points 時）
**Response 422 C001**: 狀態不允許（只有 redeemed 可 cancel）

---

## 六、ApiCode 擴充

| Code | 說明 |
|---|---|
| `C001` INVALID_COUPON_STATE | 狀態機非法轉換（附帶 current_status） |
| `C002` COUPON_EXPIRED | 優惠券已過期 |
| `C003` COUPON_NOT_FOR_MEMBER | 會員限定券不符合 |

---

## 七、CouponService 關鍵設計

### 7.1 redeem 的 lockForUpdate

```php
DB::transaction(function () use ($code, $member) {
    $coupon = Coupon::where('code', $code)->lockForUpdate()->firstOrFail();
    // 兩個並發 request 只有一個能到這裡且看到 status='created'
    if ($coupon->status !== Coupon::STATUS_CREATED) {
        throw new InvalidCouponStateException($coupon->status, 'redeem');
    }
    // ...
});
```

### 7.2 Points 整合

```php
if ($coupon->batch->type === CouponBatch::TYPE_POINTS) {
    app(PointService::class)->adjust(
        $member,
        (int) $coupon->batch->value,
        "優惠券 {$coupon->code} 兌換",
        'earn',
        "coupon-redeem-{$coupon->id}",  // idempotency key
    );
}
```

Cancel 時反向：
```php
app(PointService::class)->adjust($member, -(int)$batch->value, ..., 'refund', "coupon-cancel-{$coupon->id}");
```

---

## 八、Test 策略

### 8.1 Unit tests（CouponService）
- 正常 redeem 流程
- verify 不改狀態
- 已 redeemed 不可再 redeem（C001）
- expired 不可 redeem（C002）
- cancel redeemed 成功
- cancel created 失敗（C001）
- 並發測試：兩個 request 同時 redeem，只有一個成功
- type=points redeem 自動調用 PointService
- cancel points coupon 退回 points

### 8.2 Feature tests（API）
- 完整 CRUD 路徑
- 403 permission checks
- 狀態機非法轉換 422

---

## 九、驗收清單

- [ ] Migration 跑起來（local + CI）
- [ ] CouponService 10+ unit tests 全綠
- [ ] 4 API endpoint feature tests 全綠
- [ ] 並發 redeem 測試：兩者同時送，只有一個成功
- [ ] PHPStan 不破 baseline
- [ ] Filament UI：批次管理 + 代碼列表 + 狀態 badge
- [ ] Webhook `coupon.redeemed` event
- [ ] Permission：`coupon.manage` / `coupon.view`
- [ ] ADR-0005 歸檔
