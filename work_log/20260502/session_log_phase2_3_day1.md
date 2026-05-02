# ez_crm 工作日誌 — 2026-05-02（Phase 2.3 Order Day 1)

> 協作:Kevin + Claude Code(Opus 4.7 1M)
> 主題:Phase 2.3 Order 系統 plan review + Day 1 schema/models
> 接續:`session_log_phase2_2.md`(同日早上 Phase 2.2 完整收尾)

---

## 過程概覽

今天一日完成 4 件大事:

1. **倉清** — ARCHITECTURE.md / CI_CD_IMPROVEMENT_PLAN.md 入庫
2. **Phase 2.3 plan review** — 9 議題 30+ 子決議拍板
3. **Plan v1.0** — `ORDER_INTEGRATION_PLAN.md` 1099 行寫成 + commit
4. **Day 1 schema + models** — 10 migrations + 9 models + 跑測試

---

## 1. 倉清(早上 sync 後 first move)

### `docs(architecture): add ARCHITECTURE.md — five hard rules` (19eab1c)

之前 4/30 寫好但躺 working tree 沒 commit。憲法級防呆規則,Linky360 痛點提前釘在 ez_crm 零行業務程式碼之前:
- 禁 `resources/css/pages/`
- 禁 raw Blade 偷渡業務頁
- 禁 jQuery
- 禁單頁 `<link>` / `<script>`
- 禁手寫權限判斷散落

### `docs(plan): add CI/CD improvement plan + refresh April index` (764b952)

CI/CD 12 項待辦,從 57/100 推到 80+/100,4 週路徑。同步刷 April README 索引(補 4 個 session log + Roadmap 子計畫段)。

### 清理
- 砍 `result.txt`(4/29 之前的 PHPUnit dump 殘骸)

---

## 2. Phase 2.3 Plan Review(9 議題 × 30+ 子決議)

> 完整紀錄在 `ORDER_INTEGRATION_PLAN.md` §11,本文僅摘關鍵走向

| # | 議題 | 走向 |
|---|---|---|
| 1 | Schema 切割 | 4 主表 + Tier 2 不加(returns/refunds 後來 Choice B 補回)|
| 2 | 訂單編號 | `{PREFIX}-{YYYYMMDD}-{NNNN}` 單 global,**多 prefix 升級成本 1 天 寫進 plan §10.1** |
| 3 | 狀態機 | **B 路線:真實電商複雜度** — 6 狀態(+ partial_refunded)+ ECPay L1 |
| 4 | Points 加點 | completed 才加 / paid_amount × 1% / 退款比例倒退 |
| 5 | Coupon 套用 | paid 時鎖 / 多張可疊(+ junction)/ Priority 順序 / 同 category 限 1 張 |
| 6 | Idempotency | Client `Idempotency-Key` header → DB UNIQUE,永久 |
| 7 | Endpoint | Member + Admin 兩端,共用 Service,admin 可 mark as paid offline |
| 8 | Timeout | 設定式 30 分,Cron 5 分鐘,FSM immutable 不允許復活 |
| 9 | ECPay 安全 | Signature 必驗 + Replay App+DB 雙保險 + IP whitelist Prod-only |

### Plan review 過程的關鍵 redirect

- **議題 #1**:Kevin 要求加「auto increase id」+「prefix 後台自訂」 → 我原本只想 4 表單 prefix,被推進到 Filament 後台可改的設定表
- **議題 #2c**:Kevin pushback YAGNI 推「多 prefix 直接做」→ 我計算 1 天 migration 成本後,翻盤推 A 單 global,Kevin 接受
- **議題 #3**:Kevin 問「真實電商怎麼選」→ 我攤出 showcase 簡化 vs 真實電商複雜度,Kevin 選 **B 全做** + ECPay L1,範圍從 4-5 天拉到 9-11 天
- **整段 review**:約 1.5 hr,9 個議題逐個確認

> Senior 訊號 takeaway:**真實複雜度 ≠ senior 訊號**,senior 訊號 =(1)知道真實複雜度長什麼樣 (2)明確 scope 切割 (3)schema 預留升級路徑 (4)ADR 解釋為什麼簡化

---

## 3. Plan v1.0(`docs(plan): add Order integration plan v1.0 Accepted` e6eb18b)

`scheme/improvement_plan/2026/05/ORDER_INTEGRATION_PLAN.md`,1099 行。

15 個章節:Why / Scope / Schema(7+2) / 狀態機 / API / ApiCode / ECPay L1 / Service 架構 / Test 策略 / 未來擴展 / Review 紀錄 / Timeline / 風險預想 / 既有模組接點 / 驗收清單。

寫進**未來擴展 §10**(YAGNI 留路):
- 多 prefix(對應 §9.4 多前台,1 天 additive)
- ECPay L2 退款 API
- ECPay L3 多支付方式
- 部分退款 Coupon 比例退
- Email/SMS 通知
- Returns 真實流程細化

---

## 4. Day 1:Schema + Models(`feat(order): Phase 2.3 Day 1 — schema + models` 281046f)

### Migrations(10 個)

| 檔案 | 表 | 角色 |
|---|---|---|
| `010001_create_order_settings_table` | `order_settings` | 單列配置(prefix / points_rate / pending_timeout / min_charge),seed 一筆預設 |
| `010002_create_orders_table` | `orders` | header,status enum + 7 個money 欄位 + idempotency_key UNIQUE + ecpay_trade_no UNIQUE |
| `010003_create_order_items_table` | `order_items` | line items snapshot(no product_id FK)|
| `010004_create_order_addresses_table` | `order_addresses` | ship/bill snapshot,unique(order_id, type)|
| `010005_create_order_status_histories_table` | `order_status_histories` | append-only audit log |
| `010006_create_order_coupons_table` | `order_coupons` | junction(discount_applied + apply_order)|
| `010007_create_returns_table` | `returns` | 退換貨 lifecycle |
| `010008_create_refunds_table` | `refunds` | 退款 ledger,連 PointTransaction id |
| `010009_create_payment_callbacks_table` | `payment_callbacks` | ECPay webhook log + replay defense |
| `010010_add_priority_and_category_to_coupons_table` | `coupons` (modify) | 補 category / priority 欄 |

### Models(9 新 + 2 改)

**新**:`Order` / `OrderSettings` / `OrderItem` / `OrderAddress` / `OrderStatusHistory` / `OrderCoupon`(custom Pivot)/ `OrderReturn`(避 PHP `Return` keyword)/ `Refund` / `PaymentCallback`

**改**:`Member.orders()` HasMany / `Coupon.orders()` BelongsToMany + `category` / `priority` fillable

### 設計亮點

- **`Order` 7 個 status 常數 + 3 個 actor_type 常數** — Service 層守門好查
- **`OrderCoupon` 用 Custom Pivot** — `discount_applied` / `apply_order` 整數 cast,iterate 時不用每次轉型
- **`OrderReturn` 表名 `returns`** — 避開 PHP reserved word `return`
- **所有 docblock 強調**「NEVER write directly,go through OrderService」

### 驗證

```
migrate              :  10 / 10 OK
Tests                : 284 / 284 全綠(無 regression)
PHPStan baseline     :  10 維持
```

---

## 數字

| 項目 | 數量 |
|---|---|
| 今日 commits | 4(2 docs + 1 plan + 1 schema)|
| Plan 行數 | 1099(ORDER_INTEGRATION_PLAN v1.0)|
| 新 migrations | 10 |
| 新 models | 9 |
| 改 models | 2(Member / Coupon)|
| 新增程式碼 | ~2,300 行 |
| 累計 tests | 284(無新增 — Day 1 純 schema)|

---

## 對應 commits

```
19eab1c docs(architecture): add ARCHITECTURE.md — five hard rules
764b952 docs(plan): add CI/CD improvement plan + refresh April index
e6eb18b docs(plan): add Order integration plan v1.0 Accepted (Phase 2.3)
281046f feat(order): Phase 2.3 Day 1 — schema + models
```

全 push 到 `origin/develop`。

---

## 下一步(Day 2)

按 timeline:
- `OrderNumberGenerator`({PREFIX}-{YYYYMMDD}-{NNNN} 流水號 lock-safe 生成)
- `OrderService::create()`(idempotency + atomic transaction + coupon priority engine)
- `InvalidOrderStateTransitionException`
- 8 unit tests(create 正常 / 多券 / amount < min_charge 拒 / idempotency replay / TOCTOU race / 違法 transition)

預估 1 天,實際應該也是 ~2-3 hr(複利 + 既有 PointService/CouponService 模式可抄)。

---

## 個人觀察

**複利曲線開始發酵**:
- Phase 2.1 Points:5-7 天計畫 → 3 天實際(-50%)
- Phase 2.2 Coupon:約 5 天計畫 → 3 天實際(-40%)
- Phase 2.3 Order Day 1:1 天計畫 → ~2.5 hr 實際(-70%)

**主要原因**:
1. Schema 已熟練(欄位選型 + 索引設計都有 Points / Coupon 模板)
2. Service-only writes 紀律 + lockForUpdate 模式可直接套
3. Idempotency / audit log / state machine pattern 全部已經做過一次

**但 Day 5 ECPay 是首次串接,別過度樂觀** — webhook signature / replay defense / IP whitelist 三層防護全是新東西,留 buffer。
