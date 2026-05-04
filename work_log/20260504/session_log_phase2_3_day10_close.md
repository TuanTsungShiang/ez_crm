# ez_crm 工作日誌 — 2026-05-04(Phase 2.3 Order Day 10 收尾)

> 協作:Kevin + Claude Code(Opus 4.7 1M)
> 主題:Phase 2.3 Order 系統 hotfix + ADR-0006 + 整段封箱
> 接續:`work_log/20260503/session_log_phase2_3_day5.md`(Day 5)+ Day 6/7/8/9 commits

---

## 過程概覽

今天從早上 sync 開始,主要做 3 件事:

1. **拉 develop 最新狀態** — 補上週日(5/3)Kevin 跑的 Day 6/7/8/9 共 4 commits
2. **抓修 1 個 fail** — `EcpayWebhookTest::test_replay_same_timestamp_db_unique_prevents_second_insert` 失敗,深入 debug 找到 MySQL `TIMESTAMP` 隱式 `ON UPDATE CURRENT_TIMESTAMP` quirk,加 hotfix migration
3. **A. 收 Day 10**(ADR-0006 + 此 session log)— Phase 2.3 真正封箱

---

## 1. 早上 sync(`git pull origin develop`)

從 5/2 d2276f1 → 拉到 5/4 74a3981,中間 7 個新 commits(都是 Kevin 在 5/3 完成的):

```
74a3981  Day 9 — concurrency tests + ECPay webhook tests + Swagger fix    (5/4 00:04)
6b023af  Day 8 — Webhook events (x6) + CancelPendingOrdersJob cron        (5/3 22:02)
431c07b  Day 7 — Admin API + Filament OrderResource + RBAC 6 permissions  (5/3 18:49)
5e175a6  Day 6 — Member Order API + FormRequest + feature tests           (5/3 18:14)
123a155  docs(session-log): Phase 2.3 Day 2~4 + Day 5                     (5/3 00:25)
a91a7cd  Day 5 — ECPayService + payment_callbacks + IP whitelist          (5/3 00:05)
aba1092  Day 2 — OrderService + OrderNumberGenerator + 20 unit tests      (5/2 ~)
```

**進度判讀**:Phase 2.3 計畫 9-11 天,實際 5/2-5/4 三個日曆天完成 Day 1-9,**剩 Day 10**(ADR + session log)就完整收尾。

---

## 2. Hotfix:`payment_callbacks.callback_time` 隱式 ON UPDATE

### 抓 fail 過程

跑全 suite → `1 failed, 337 passed, 15 pending` → 第一輪 output 被 tail 截掉,看不到失敗細節。

第二輪 `--stop-on-failure`(避免淹沒)→ 找到 `test_replay_same_timestamp_db_unique_prevents_second_insert`。

直接 `dump()` 注入 test → 看到 DB 兩 row 的 `callback_time` 都是 NOW(),不是 test 設的 `'2026/05/03 10:00:00'`。

加 log 進 `parsePaymentDate` → 確認 parse **正常**,輸出 Carbon `2026-05-03 10:00:00`。所以 parse 不是兇手。

跑 `SHOW COLUMNS FROM payment_callbacks WHERE Field = 'callback_time'`:

```
Type:    timestamp
Default: CURRENT_TIMESTAMP
Extra:   on update CURRENT_TIMESTAMP        ← !!!
```

**真兇**:MySQL `explicit_defaults_for_timestamp=OFF` 的 legacy 行為,**第一個無 default 的 TIMESTAMP 欄位會自動加 `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`**。

### 修法

新 migration `2026_05_04_010001_fix_payment_callbacks_callback_time_no_implicit_default.php`:

```php
DB::statement('ALTER TABLE payment_callbacks MODIFY callback_time DATETIME NOT NULL');
```

`DATETIME` 不會被 MySQL 加上 implicit default/on-update,根除問題。

### 結果

```
Before: 1 failed, 337 passed, 15 pending
After:  352 passed, 1 skipped (ecpay-sandbox group)
PHPStan: baseline 10 維持
```

### 教訓寫進 ADR-0006 Lessons Learned §L1

> **Snapshot 用途的時間欄位用 DATETIME**,record-lifecycle 用途的(created_at/updated_at)才用 TIMESTAMP。

這個 gotcha 是 **concurrency test 真的有用** 的最佳例子 — 沒有那個刻意設計的「同 timestamp 兩次 POST」test case,這個 bug 會直接上 production,然後客服收到「同一筆 ECPay 通知重複算錢」的客訴才會被發現。

---

## 3. ADR-0006:Phase 2.3 整段設計決策

`work_log/20260504/adr_0006_order_system.md`(~270 行)

涵蓋 9 個核心決策:
1. State machine 顯式 transition table + immutable history(為什麼不用 spatie 套件)
2. Atomic transaction + lockForUpdate(複用 Phase 2.1/2.2 既有模式)
3. Idempotency 三層:Client header → fast-fail SELECT → DB UNIQUE backstop + TOCTOU defense
4. Snapshot pattern(凍結交易瞬間的所有外部狀態 — items / addresses / discount / points / raw_payload)
5. Service-only writes(三層紀律:Model docblock + Service 入口 + Filament 不繞過)
6. ECPay L1 三層安全(IP whitelist + signature + replay 雙保險)
7. Coupon priority engine(priority + category limit + 折抵後 cap)
8. Points 整合(completed-only earn + paid_amount-based 防套利 + 比例 refund)
9. Timeout cron 也走 OrderService(FSM 紀律延伸)

加 3 個 Lessons Learned:
- **L1**:MySQL TIMESTAMP 隱式 ON UPDATE(今天的 hotfix)
- **L2**:多券 priority engine 的非顯然複雜度
- **L3**:ECPay 「永遠回 200」反直覺但必要

加 Future Evolution(對齊 plan §10)+ References。

---

## 數字

| 項目 | 數量 |
|---|---|
| 今日 commits | 預計 2(1 hotfix + 1 ADR/session log)|
| Hotfix migration | 1 (~50 行) |
| ADR-0006 行數 | ~270 |
| Day 10 session log 行數 | ~180 (本文) |
| 累計 tests | **352 passed / 1 skipped**(ecpay-sandbox group,要 manual run)|
| 累計 assertions | 1,042 |
| PHPStan baseline | 10(維持)|
| Phase 2.3 總工期 | 5/2 - 5/4 共 **3 個日曆天**(計畫 9-11 天 → -70%)|

---

## Phase 2.3 驗收清單

- [x] 9 + 1 migrations(7 主表 + 2 junction/snapshot 表 + Coupon retrofit + 1 hotfix)跑得起來(local + CI)
- [x] OrderService 20+ unit tests 全綠
- [x] Member + Admin endpoints feature tests 全綠
- [x] Concurrency tests:同 coupon 兩單 / webhook replay / order_no race
- [x] ECPay sandbox 整合測試(@group ecpay-sandbox,manual run)
- [x] PHPStan 不破 baseline(level 5)
- [x] Filament UI:OrderResource 列表 + 詳情 + 操作按鈕 + 狀態 badge
- [x] Webhook 6 個 event(created/paid/shipped/completed/cancelled/refunded)
- [x] Permission:6 個 permissions(refund 限 admin+)
- [x] Cron `CancelPendingOrdersJob` 在 Laravel Scheduler 註冊
- [x] ECPay IP whitelist middleware:dev env skip,prod env enforce
- [x] Swagger annotations 涵蓋全 endpoints
- [x] **ADR-0006 歸檔**(本日完成)

---

## 對應 commits(本日)

```
c80d594  fix(order): payment_callbacks.callback_time leaks ON UPDATE CURRENT_TIMESTAMP
[next]   docs(adr/session-log): Phase 2.3 Day 10 close — ADR-0006 + wrap session log
```

---

## 個人觀察

### 複利曲線正式收尾

| Phase | 計畫估時 | 實際工期 | 提前比例 |
|---|---|---|---|
| 2.1 Points | 5-7 天 | 3 天 | -50%~-60% |
| 2.2 Coupon | 約 5 天 | 3 天 | -40% |
| **2.3 Order** | **9-11 天** | **3 天** | **-70%** |

Order 是最複雜的(7 個主表 + ECPay 全新整合 + 多券 priority + partial refund)— 還能 -70%,**senior 訊號**:

1. **不發明新模式**:既有 Service 紀律 / lockForUpdate / idempotency / event / FSM 全部直接套
2. **schema 先行 + plan 拍板**:9 議題 review 一次到位,實作幾乎沒回頭改設計
3. **測試文化壓著走**:concurrency test 抓到了 production 才會炸的 MySQL gotcha

### Phase 2 鐵三角整段封閉 ✅

```
Points (Phase 2.1) ──┐
                    ├──> Order (Phase 2.3) ←── ECPay L1 webhook
Coupon (Phase 2.2) ──┘                              ↓
                                              真實金流動線
```

下次有人讀 ez_crm portfolio,看到的是:**完整的 e-commerce domain 後端**(會員 → 點數 → 優惠券 → 訂單 → 金流),不是 CRUD demo。

### Day 10 hotfix 是個小驚喜

我原本預期今天就是「ADR + session log 兩份文件」單純收尾,結果跑 test 發現 1 fail,debug 過程意外讓 ADR-0006 多了一條紮實的 Lessons Learned。**Senior 訊號:** 不是「沒 bug 才能寫 ADR」,而是「ADR 有寫到實作中真的踩到的坑」更可信。

---

## 下一步

按 ABCD 順序:

- [x] **A 收 Day 10**(本日完成)
- [ ] **B Phase 2 整段 reflection**(三模組對照、senior 訊號清單、portfolio piece 等級)
- [ ] **C Engineering W4-W5 Docker / CI/CD 改善前 6 項**

B 的撰寫節奏:Phase 2 整段細節還熱在腦袋裡,**今天可以接著寫**(若 Kevin 有體力)或下次再說。
