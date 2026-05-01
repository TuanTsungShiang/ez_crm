# ez_crm 工作日誌 — 2026-05-01 / 2026-05-02

> 協作：Kevin + Claude Code
> 主題：Phase 2.1 Points 系統完整收尾

---

## 完成項目

### Phase 2.1 Day 2 完成（5/1 下午，承接 Day 1）

**API Layer**（`MemberPointsController` + `MePointsController`）：
- `GET  /api/v1/members/{uuid}/points` — 餘額 + 分頁交易明細
- `POST /api/v1/members/{uuid}/points/adjust` — earn/spend/adjust/refund
  - Idempotency-Key header 必填，缺少 → 422 V001
  - 點數不足 → 422 B001
  - Replay → 200 B002 + `replayed: true`
- `GET  /api/v1/me/points` — 前台會員自己看

**Permission Layer**：
- `RolePermissionSeeder`：加 `points.view` + `points.manage`
- admin / super_admin：view + manage
- marketing：view + manage（行銷活動送點需求）
- customer_support / viewer：view only
- `MemberPolicy::viewPoints()` / `managePoints()`

**關鍵 Bug 修復**：
- `User::getDefaultGuardName() = 'web'` — Spatie + Sanctum guard mismatch
  - `auth:sanctum` 請求讓 Spatie 嘗試查 guard='sanctum' 的 permissions
  - 所有 permissions 是 guard_name='web'，需覆寫固定住
  - 這是「第一個在 Sanctum route 上用 `$this->authorize()` 的 controller」才踩到的坑

### Phase 2.1 Day 3 完成（5/1 深夜 / 5/2）

**Filament UI**：
- `PointTransactionResource`：全域點數流水帳
  - 欄位：金額（+/- 顏色）、餘額後、類型 badge、說明、操作者、時間
  - Filter：類型 / 來源 / 日期範圍
  - 完全 read-only
- `MemberResource` 新增：
  - table 點數 badge 欄（sortable）
  - `PointTransactionsRelationManager`（編輯頁下方交易紀錄）

**Swagger 文件**：
- `GET /members/{uuid}/points`、`POST .../adjust`、`GET /me/points` 三支都加 OA 註解
- 重新 generate → Swagger UI 出現 Points section

**Webhook 掛接**：
- `EventServiceProvider` 加入 `PointAdjusted::class => [DispatchWebhook::class]`
- 原本漏掛，`points.adjusted` event 不會觸發 webhook subscriber

**Concurrency Tests**（4 cases）：
- sequential 10 次 -20 扣點（初始 100）：5 成 5 敗，餘額歸 0
- balance_after 連續正確性驗證
- 重複 idempotency key 只產生 1 筆 transaction
- 超額扣點不留 partial state

**ADR-0004**：記錄 atomic / idempotency / guard 修復的設計決策

---

## 數字

| 項目 | 數量 |
|---|---|
| 新增 tests | 19（feature）+ 4（concurrency）= 23 |
| 總 tests | 249 passed / 800 assertions |
| 新增 files | 15 |
| Commits（Phase 2.1 全程）| 6 |

---

## Phase 2.1 驗收清單

- [x] Schema migration（`members.points` + `point_transactions`）
- [x] `PointService::adjust` 10 unit tests 全綠
- [x] 2 admin + 1 me endpoint feature tests 全綠（15 cases）
- [x] Concurrency test：sequential 超賣防護 + balance 連續正確
- [x] Idempotency replay：同 key 兩次回同 transaction（單元 + feature 雙層）
- [x] PHPStan level 5 不破 baseline
- [x] Filament UI：交易紀錄 + 過濾器 + 排序 + 會員點數 badge
- [x] Webhook：`points.adjusted` event 掛上 DispatchWebhook listener
- [x] Permission：customer_support 不能 adjust（無 points.manage）
- [x] Swagger：三支 endpoint 文件齊全
- [x] ADR-0004 歸檔

---

## 下一步

Phase 2.1 完整收尾，`develop` 準備在 Phase 2.2（Coupon）完成後合并 `main` 做 v1.1 release。

ENGINEERING W3（Pre-commit hook）deadline 2026-05-12，下次開工。
