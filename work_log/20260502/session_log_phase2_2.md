# ez_crm 工作日誌 — 2026-05-02（Phase 2.2 Coupon）

> 協作：Kevin + Claude Code
> 主題：Phase 2.2 Coupon 優惠券系統完整實作

---

## 完成項目（單日完成 Phase 2.2 全程）

### Day 1：Schema + Models + Service + Unit Tests

**COUPON_INTEGRATION_PLAN.md**：v1.0 完整規格，含狀態機圖、API 規格、設計決策、驗收清單

**Schema**：
- `coupon_batches`（批次活動設定：type/value/quantity/expires_at）
- `coupons`（個別代碼：EZCRM-XXXX-XXXX unique，狀態機 4 態，redeem/cancel audit 欄位）

**Models**：`CouponBatch` + `Coupon`（4 狀態常數 + 輔助方法）

**Exceptions**：
- `InvalidCouponStateException`（C001）：狀態機非法轉換
- `CouponExpiredException`（C002）：批次已過期

**ApiCode**：加 C001 / C002 / C003

**CouponService**：
- `createBatch` — bulk-insert EZCRM-XXXX-XXXX 代碼
- `verify` — read-only 狀態檢查（不改 DB）
- `redeem` — fast-fail expiry check 外層 + lockForUpdate 內層 + Points 整合 + CouponRedeemed event
- `cancel` — lockForUpdate + Points refund 反向

**Webhook**：`CouponRedeemed` event → `EventServiceProvider` 掛 DispatchWebhook

**Unit Tests**：16 cases — createBatch、verify、redeem 各路徑、cancel 各路徑、Points 整合、concurrent redeem

### Day 2：API + Permissions

**Endpoints**（4 個，全 auth:sanctum）：
- `POST /v1/coupons` — 建立批次（coupon.manage）
- `POST /v1/coupons/{code}/verify` — 驗證（coupon.view）
- `POST /v1/coupons/{code}/redeem` — 核銷（coupon.manage）
- `POST /v1/coupons/{code}/cancel` — 取消核銷（coupon.manage）

**Permission**：`coupon.view` / `coupon.manage` 加入 RolePermissionSeeder + 各角色 matrix

**Swagger**：4 個 endpoint OA 文件

### Day 3：Filament UI + Feature Tests + ADR

**Filament**：
- `CouponBatchResource`（建立批次 + 列表 + 詳情，CreateRecord override 走 CouponService）
- `CouponResource`（代碼列表，read-only，filter 狀態/批次）
- 導覽群組「行銷工具」

**Feature Tests**：19 cases — 全端點 happy path + 403/422 + concurrent redeem API 層驗證

**ADR-0005**：記錄狀態機設計、pre-transaction expiry、Points 整合語意

---

## 數字

| 項目 | 數量 |
|---|---|
| 新增 tests | 16（unit）+ 19（feature）= 35 |
| 累計 tests | 284 passed / 879 assertions |
| 新增 files | 20 |
| Commits | 2 |

---

## Phase 2.2 驗收清單

- [x] Schema migration（local + CI）
- [x] CouponService 16 unit tests 全綠
- [x] 4 API endpoint feature tests 全綠（19 cases）
- [x] concurrent redeem：只有一個成功
- [x] PHPStan level 5 不破 baseline
- [x] Filament UI：批次管理 + 代碼列表 + 狀態 badge
- [x] Webhook `coupon.redeemed` event
- [x] Permission：`coupon.manage` / `coupon.view`
- [x] ADR-0005 歸檔

---

## 下一步

Phase 2.3 Order 訂單系統（觸發 Points + Coupon）或 Engineering W4-W5 Docker。
