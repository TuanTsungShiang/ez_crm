# ez_crm 工作日誌 — 2026-04-22 (Wed)

> 分支狀態(ending):雙 repo `develop` 乾淨,無殘留 feature branch
> 協作:Kevin + Claude Code
> 前情:昨天(4/21)完成 Phase 4 OAuth 全部 + SPA popup + `/me` 頁 + Dashboard

---

## 🎯 今日主戰場:Webhook 系統(Phase 1 + 2 + 後續)

**3 個 phase 當天完成上線**,架構從「計畫書」到「端到端實跑 + 後台可視化管理」:

---

## 上午(10:36-12:15)

### 1. 🔥 webhook 計畫升級 v0.2(1e1ff86)

Kevin 今早前寫了初版計畫(423 行),我做 code review 給 6+2 個強化點,全套進 plan v0.2(584 行):

| # | 優化 |
|---|---|
| 1 | `DispatchWebhook` 要包 DB transaction + `afterCommit()`(防 orphan delivery) |
| 2 | 事件加 `sequence_number` = events.id(防 out-of-order) |
| 3 | Dead Letter 告警 + Circuit Breaker(連續失敗自動斷路) |
| 4 | Payload size 512KB 硬上限 |
| 5 | Admin UI 明確用 **Filament Resource** |
| 6 | Testing 章節從 1 行展開為 12 個具體 test case |
| A | `X-Idempotency-Key` 取代自訂 header(業界慣例) |
| B | Secret rotation 雙 secret 並行 24h |

### 2. 🔐 Phase 4.3 Discord OAuth 端到端驗收

踩的雷:`vendor/socialiteproviders/` 整個資料夾不見(composer.lock 有記錄但檔案被清)→ `composer install` 補齊 → Driver not supported 問題解決。

**結果**:同 email `ruby004949@gmail.com` 用 Google / GitHub / LINE / Discord 任一登入都聚合到同一個 member(scenario 2 auto-bind),4 個 provider 同 member 在 `/me` 頁面一起顯示。

### 3. 🪟 SPA OAuth Popup Flow(前後端整合)

後端 `OAuthController::callback()` 從 JSON 改成**預設 HTML + postMessage**(SPA 用),`?format=json` 可切回既有行為。前端用 popup + `window.addEventListener('message')` 收 token,origin check 鎖死 backend domain。

端到端驗證:`Discord OAuth` 按鈕 → popup → 授權 → 自動關閉 → 回到主頁已登入。

### 4. 🪪 `/me` 頁 + Home Dashboard 改版

- `/me` 三張卡:基本資料 / 已綁定登入方式 / 個人設定
- Home 從 debug info 卡改為 **漸層 greeting hero + stats + 快速操作**,debug 塞 `<details>` + `import.meta.env.DEV` 條件渲染(production 看不到)

---

## 下午(14:00-15:27)

### 5. 🏗️ Webhook Phase 1 MVP(e614119)

**Schema(3 表)**:
- `webhook_subscriptions`:含 `secret` + `previous_secret` + `previous_secret_expires_at`(rotation)、`is_active`/`is_circuit_broken`/`consecutive_failure_count`
- `webhook_events`:`id` 即 sequence、`payload` JSON 快照、`occurred_at` 微秒精度
- `webhook_deliveries`:enum status、attempts、http_status、response_body 截 1000 字、next_retry_at、delivered_at

**DispatchWebhook listener**:
- `DB::transaction` 包住「event INSERT + delivery INSERT + job dispatch」
- `SendWebhookJob::dispatch()->afterCommit()` 避免 transaction 未 commit 就撿
- 512KB guard 超過 log error 直接返回
- Sequence 回填(= event.id)
- 只派給 `is_active AND NOT is_circuit_broken AND 訂閱此 event_type` 的 subscription

**SendWebhookJob**:
- 自控重試(`tries=1`,不用 Laravel 內建)
- 指數退避 `[60, 300, 1800, 7200, 43200]` 秒
- HMAC-SHA256 簽 `{timestamp}.{body}`,headers 帶 `X-Webhook-Signature: v1=`、`X-Idempotency-Key`、`X-Webhook-Event`
- Circuit breaker 閾值 20,成功則歸零
- 三個 guard:deactivated / circuit_broken / already-successful 直接短路

**首個 producer**:`RegisterController` 觸發 `MemberCreated`。

### 6. 🌐 β 端到端真實驗證

- Tinker 建 `WebhookSubscription` 指向 `webhook.site/05a00539...`
- `curl POST /api/v1/auth/register` 建新 member
- 因為 `QUEUE_CONNECTION=sync`,listener → job 同步跑,HTTP POST 直接送達 webhook.site
- 看到 2 個 delivery 都 **status=success、http=200、attempts=1**
- webhook.site 儀表板看到完整 body + 所有 security headers

驗到 seq=1 → seq=2 遞增,且第 3 發 register 踩到 Mailtrap rate limit 暴露出 **event 應該先於 notify** 的順序 bug(後來修掉)。

### 7. 🧪 γ SendWebhookJob 單元測試(830d73f)

11 個 test case 全綠:
- Success path + consecutive_failure_count 歸零
- HMAC 簽章 / Idempotency-Key header 內容正確
- 失敗 → status=retrying + attempts++ + schedule retry
- RETRY_DELAYS 常數正確([60, 300, 1800, 7200, 43200])
- Max retries reached → status=failed,不再排 retry
- 網路例外 → error_message 存 + retry
- 連續失敗 19→20 次觸發 circuit breaker
- 5 次失敗不觸發(閾值保護)
- Guards:deactivated / circuit_broken / already-success 不呼 Http

### 8. 🎨 α Filament Admin UI(decf3b9)

**3 個 Resource**:
- `WebhookSubscriptionResource`(CRUD)+ actions:
  - **Rotate Secret**:舊 secret 入 previous_secret + expires 24h,新 secret 一次性顯示
  - **解除斷路**:`is_circuit_broken=false` + `consecutive_failure_count=0`,僅斷路時可見
- `WebhookDeliveryResource`(唯讀)+ **Retry / Bulk Retry** action,10 秒自動 poll,infolist 顯示 pretty-printed payload + response body
- `WebhookEventResource`(唯讀)+ **重新派送** action(對該事件把 delivery 重新派給所有當前 active subscribers)

**Dashboard Widget:`WebhookHealthWidget`**(30 秒 poll):
- 24h 成功率(≥99% 綠 / ≥95% 黃 / 低於 紅)
- 24h 失敗數
- 斷路中訂閱數
- Queue 深度(pending + retrying)

### 9. 🔧 η register 順序 bug 修復 + ζ 3 個新 event 類型(85a9e66)

**η**:RegisterController 把 `event(new MemberCreated)` 移到 notify 前,Mailtrap rate limit 不再阻斷 webhook。

**ζ**:新增 3 個 event 類型全部接好:
- `MemberVerifiedEmail`(VerifyEmailController)
- `MemberLoggedIn`(LoginController / VerifyEmailController / OAuthController,含 `method` 欄位區分 email / google / github / line / discord)
- `OAuthBound`(OAuthController 情境 2 + 3,含 `is_new_account` 區分新用戶綁 vs 既有用戶多綁)

Filament `availableEvents()` 同步更新成 7 個選項。

---

## 測試結果

```
Tests:    166 passed (538 assertions)
Duration: 115s
```

| 測試檔 | 數量 |
|---|---|
| Webhook DispatchWebhookTest | 12 |
| Webhook SendWebhookJobTest | 11 |
| **今日新增合計** | **23** |
| 既有(OAuth / Me / Auth / CRM) | 143 |
| **總計** | **166** |

---

## 關鍵決策紀錄

### 1. 為什麼 `event()` 要先於 `notify()`

Register 觸發 webhook 的 domain 意義是「**一個新 member 已經存在於系統**」,不該被 email 基礎設施問題(Mailtrap rate limit、SMTP 故障)阻擋。Member 已經 commit 就是 commit,下游服務有權知道。

Notify 失敗屬於獨立基礎設施錯誤,應該獨立處理(log + alert + retry),不該拖累下游訊號。

### 2. 為什麼 `QUEUE_CONNECTION=sync` 也是生產決策

很多人預設「webhook 就要 async」,但我們目前選 sync 是因為:
- Dev 階段方便端到端 debug,效果即時
- 未來切到 `database` 或 `redis` 只需改 `.env`,code 不動
- Production 建議切 `redis` + `queue:work --queue=webhooks` + supervisor

### 3. 為什麼 Circuit Breaker 閾值 20

- 低於 10 → 過於敏感,下游小維護就跳掉
- 高於 50 → 太晚發現問題,Queue 塞爆
- 20 對應「重試 5 次 × 4 個失敗事件」≈ 半小時~一小時的連續故障,抓到「對方真的壞了」而不是「偶發網路抖動」

### 4. 為什麼 Admin UI 選 Filament 而不是自刻

- 專案已經裝 Filament 3(做 Member / Group / Tag admin)
- Filament Resource 給 CRUD / bulk / filter / action / 通知幾乎零成本
- Widget 系統整合 dashboard 直觀
- 自刻頁面沒有實質差異化收益

### 5. 為什麼 Filament Resource 不 test(目前)

Filament 本身有 good coverage。我們的 Resource 大多是**配置**(columns / filters / actions),邏輯都在 Model / Service / Job。那些邏輯已經有 unit + feature test 覆蓋了。

未來如果 Resource 裡寫了複雜 custom action(例如批次操作的 business rule),再寫 Livewire 測試。

### 6. 為什麼用 `X-Idempotency-Key` 而不是自訂 `X-Webhook-Delivery-Id`

Stripe / GitHub / Shopify / Zendesk 全部都用 `X-Idempotency-Key`,是業界標準 header,下游實作者比較好辨識。自訂 header 會多一層學習成本。

---

## 後端 API 與 Event 現況

### API
| Area | 狀態 |
|---|---|
| CRM 後台(Members/Groups/Tags)| ✅ |
| Auth 前台(Register/Verify/Login/Forgot/Reset)| ✅ |
| OAuth(Google/GitHub/LINE/Discord)| ✅(+SPA popup HTML callback) |
| Me(show/update/password/logout/logout-all/destroy)| ✅ |

### Webhook Events
| 事件 | 觸發點 |
|---|---|
| `member.created` | RegisterController |
| `member.email_verified` | VerifyEmailController |
| `member.logged_in` | LoginController / VerifyEmailController / OAuthController |
| `oauth.bound` | OAuthController 情境 2/3 |

未來可再加:`member.updated`(PUT /me)、`member.deleted`(DELETE /me)、`oauth.unbound`(解綁 SNS)。

---

## 今日 ez_crm Commit 序列

```
85a9e66  Merge feature/webhook-more-events into develop
813dca4  feat(webhook): add 3 event types + fix register event order
fa5dcec  Merge feature/webhook-system: production-grade webhook system
decf3b9  feat(webhook): Phase 2 — Filament admin UI + health widget
830d73f  test(webhook): add SendWebhookJob unit tests (11 cases)
e614119  feat(webhook): Phase 1 MVP — event dispatch pipeline + MemberCreated
a9ab9b4  docs: add 2026-04-22 morning work log
fecc56b  Merge feature/oauth-spa-callback: HTML+postMessage callback for SPA
1e1ff86  docs(webhook): finalize plan v0.2 after review
9926941  Merge feature/member-oauth into develop  (← 昨天晚上的成果)
```

---

## 下次接續

### 短期(明天/明後天)

1. **切換 queue driver 到 database**,跑 `queue:work` 看真 async + retry
2. **加 `member.updated` / `member.deleted` event**(前端 `/me` PUT / DELETE)
3. **寫一個 receiver consumer guide**(`scheme/api/webhook_consumer_guide.md`):
   - HMAC 驗簽範例(PHP / Node.js / Python)
   - Idempotency 實作建議
   - Sequence out-of-order 處理

### 中期(下週)

4. **Circuit Breaker 觸發時發 admin email/Slack**(TODO 在 Job 裡留了)
5. **Secret rotation 驗證邏輯真的試 previous_secret**(目前 rotation 有存,但 Job 內只用 secret 主欄位簽)
6. **Backpressure**:Queue 深度超過某值自動降級(只派 P0 事件)

### 長期(1-2 個月)

7. **Webhook Receiver 模組**(ez_crm 能接收 LINE / SendGrid / 金流 webhook)
8. **Event versioning**(`member.created.v2`)
9. **SCIM 2.0**(Phase 9,計畫已在 [scheme/scim_2.0_reference/INTEGRATION_PLAN.md](../../scim_2.0_reference/INTEGRATION_PLAN.md))

---

## 今日小經驗

1. **vendor 目錄不完整 → composer install**:composer.lock 有記錄但 vendor 空,直接 install 解決。
2. **Apache opcache vs Laravel cache**:EventServiceProvider 改 listener 時 Apache 必須重啟(opcache),`artisan optimize:clear` 只清 Laravel 那層。
3. **Filament widget generator 在某些 CLI 環境會卡住等 input**:發現卡住就手寫檔案,別硬等。
4. **`Bus::assertDispatchedAfterResponse` != `afterCommit()`**:前者是 HTTP response 之後,後者是 DB transaction commit 之後,兩者不等價。測試 afterCommit 的 job 用 `Bus::assertDispatched` 就好。
5. **Mailtrap free tier 有每秒 email 限制**:暴露了 register event→notify 順序的設計問題,也是系統性壓測的入口。
