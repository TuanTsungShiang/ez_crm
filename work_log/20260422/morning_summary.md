# 上午工作紀錄 — 2026-04-22 (Wed) AM

> 時間:10:36 - 12:15(約 1 小時 40 分)
> 分支狀態(ending):雙 repo `develop` 乾淨,無殘留 feature branch
> 協作:Kevin + Claude Code

---

## 今日起點

Kevin 於昨晚/今早已**獨立完成 Phase 4.2 LINE OAuth 後端**:
- commit `8ffb8eb` feat(auth): Phase 4.2 — LINE OAuth login
- commit `c314b0c` test(auth): OAuthTest state validation + LINE/Discord cases

順便加了 **stateless OAuth state CSRF 防護**(Cache 10 min TTL),修 LINE 的 INVALID_REQUEST,並安裝了 `socialiteproviders/line` + `socialiteproviders/discord` 兩個 provider 套件、EventServiceProvider 也一起註冊好。

---

## 上午完成項目

### 1. 📝 Webhook 計畫 v0.2(套完 review)

- 文件:[work_log/20260422/ez_crm_webhook_plan.md](ez_crm_webhook_plan.md) 從 v0.1 (423 行) 升到 v0.2 (584 行)
- 套進 6 個 code review 點 + 2 個延伸改善:

| # | 改善 | 落腳處 |
|---|---|---|
| 1 | `DispatchWebhook` 加 `DB::transaction` + `afterCommit()` | 第四節 listener 程式碼 |
| 2 | 事件 `sequence_number`(= webhook_events.id)防 out-of-order | 第二節 schema + 第四節 payload |
| 3 | Circuit breaker(連續失敗 20 次自動斷路) | 第二節 schema 新增 is_circuit_broken + 第四節 retry + 第六節 admin action |
| 4 | Payload 512KB 硬上限 | 第四節 DispatchWebhook guard |
| 5 | Admin UI 明確用 **Filament Resource** | 第六節 rewrote |
| 6 | Testing 展開 1 行 → 12 個具體 test case | 第七節 Phase 4 |
| A | `X-Idempotency-Key` 取代 `X-Webhook-Delivery-Id`(業界慣例) | 第四節 payload headers + 第五節 idempotent handling |
| B | Secret rotation 雙 secret 並行 24h(平滑過渡) | 第二節 schema + 第五節 security |

- commit:`1e1ff86` docs(webhook): finalize plan v0.2 after review

### 2. 🔐 Phase 4.3 Discord OAuth 端到端驗收

問題:Kevin 貼了 Discord credentials 到 `.env` 後,打 `/oauth/discord/redirect` 返回 `Driver [discord] not supported`。

診斷鏈:
- `optimize:clear` + 重啟 Apache → 依然失敗
- 觀察:Google(內建)OK,LINE + Discord(socialiteproviders 擴充)都失敗 → 不是 opcache
- 深挖:`vendor/socialiteproviders/` 整個資料夾**不存在**,但 `composer.lock` 有 5 筆記錄 → vendor 被清但沒重裝
- `composer install` → 修復

驗收結果(真 Discord OAuth):
- Scenario 3(全新建):第一次用 Discord → 建新 member(kevino430 / ruby004949@gmail.com)
- 後續 LINE 也綁到**同一個 member**(ruby004949 email 一致 → scenario 2 auto-bind)

### 3. 🏁 feature/member-oauth Merge 回 develop

9 commits 合併,OAuth Phase 4 全 4 provider 端到端完成。
- ez_crm `9926941` Merge commit
- 清 feature/member-oauth 本地 + remote 分支
- 新里程碑:前台 Auth API **19/24(79%)**

### 4. 🪟 OAuth SPA Popup 流程(前後端整合)

**後端**(`feature/oauth-spa-callback` → 已 merge):
- `OAuthController::callback()` 預設回 **HTML + postMessage**(SPA popup flow)
- 加 `?format=json` query 可切回 JSON(既有 API test 用)
- `services.frontend.url`(FRONTEND_URL env)作為 `postMessage` 的 targetOrigin,非 `*`
- 新增 2 個 feature test 覆蓋 HTML 回應 / state invalid 情境
- 17 OAuth tests passed / 62 assertions

**前端**(`feature/oauth-buttons` → 已 merge):
- `api/auth.ts::getOAuthRedirectUrl(provider)`
- `composables/useOAuthPopup.ts`:popup + postMessage listener + origin check + popup-closed 偵測
- `components/OAuthButtons.vue`:4 個 provider 按鈕(Google / GitHub / LINE / Discord)含 inline SVG icon、busy 狀態
- LoginView + RegisterView 都掛上

**端到端驗證**:Discord button → popup → 授權 → 關閉 → 回主頁 → 跳 Home 已登入。

### 5. 🪪 `/me` 頁(會員自我檢視)

- 後端 `GET /api/v1/me`(前天做的)直接打通
- 前端 `feature/me-page` → `feature/me-page` 已 merge
- 三張卡:基本資料 / 已綁定登入方式 / 個人設定
- 狀態 badge(正常 / 停用 / 待驗證)
- 已綁定登入方式:**四個 provider 同 member 一起顯示** —— 統一身份架構 UI 可視化驗證

---

## 途中發現的小坑(已解)

1. **vendor/ 目錄不完整**:composer.lock 有記錄但實體檔案被清掉。`composer install` 還原。
2. **Apache opcache**:EventServiceProvider 加新 listener 時需要 Apache 重啟。
3. **git 跨 repo 操作**:我在 ez_crm 目錄下敲 client 的 git 指令會失敗,務必 `cd` 切對 repo。
4. **LINE Developer Console email permission 預設關閉**:要去 `OpenID Connect → Email address permission` 按 Apply,不然拿到 placeholder email。

---

## DB 最終狀態(兩個 member 四個 provider)

```
zongyongduan23@gmail.com — google, github (2 providers)
ruby004949@gmail.com     — discord, line, google, github (4 providers)
```

第二個 member 是「同 email 跨 provider 自動綁定」的活示範,可以拿來做 demo。

---

## 兩個 repo 的 commit 序列(上午)

### ez_crm(後端)
```
fecc56b  Merge feature/oauth-spa-callback into develop
fe5f11b  feat(auth): OAuth callback returns HTML + postMessage for SPA popup
1e1ff86  docs(webhook): finalize plan v0.2 after review
9926941  Merge feature/member-oauth into develop
5588ae5  fix(auth): normalize EMAIL_NOT_VERIFIED errors shape  (← 之前的)
```

### ez_crm_client(前端)
```
1a5026a  Merge feature/me-page into develop
2b6b2bf  feat(me): add /me page showing profile + bound OAuth providers
1fbb30d  Merge feature/oauth-buttons into develop
33e50b7  feat(auth): add OAuth button flow (popup + postMessage)
```

---

## 午休後(13:30+ 或 14:00+)下午計畫

依照上午策略 (c):**開 Webhook 系統(feature/webhook-system)**

### Phase 1 MVP(~3.5 小時)

1. 建 3 張 migration:`webhook_subscriptions` / `webhook_events` / `webhook_deliveries`
   - 按 webhook plan v0.2 schema(含 circuit breaker 欄位、secret rotation 欄位)
2. 建 3 個 Eloquent Model
3. 建第一個 Event:`App\Events\Webhooks\MemberCreated`
4. 建共用 Listener:`App\Listeners\DispatchWebhook`
   - DB::transaction + afterCommit
   - 512KB payload guard
5. 建 Job:`App\Jobs\SendWebhookJob`
   - HMAC simulate + 指數退避 + circuit breaker
6. 註冊 `EventServiceProvider::$listen`
7. 在 `RegisterController` 加 `event(new MemberCreated($member))` 觸發
8. 設 queue driver(先用 database driver)
9. 起一個最小 Feature Test 證明 flow 通

### Phase 2(明天或後天)

- Filament Resource(WebhookSubscriptionResource / DeliveryResource / EventResource)
- Dashboard Widget(最近 24h 健康狀態)
- 更多 event types(member.updated / oauth.bound 等)

---

## 今日亮點(若要收進履歷 / 週報)

- **1 個上午完成 4 個 OAuth provider 端到端**(後端邏輯 + 前端 popup 整合 + 真實 demo)
- **跨 repo 同步開發並保持 Gitflow 紀律**(ez_crm + ez_crm_client 都有 develop / feature branch)
- **API contract 雙向嚴謹**:後端 HTML/JSON 雙模式、前端 postMessage origin check、OAuth state CSRF 防護
- **Webhook 計畫升級到 production-ready**(含 circuit breaker / idempotency / secret rotation / 12 個測試案例)
- **統一身份架構真實驗證**:同一個 email 用 4 種 OAuth provider 全部自動聚合到一個 member
