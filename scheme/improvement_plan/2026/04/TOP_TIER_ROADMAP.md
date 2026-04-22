# ez_crm 頂標路線圖（TOP_TIER_ROADMAP）

> 往上爬，一直往上爬，直到可以看到山的另一側。

| 欄位 | 值 |
|---|---|
| 文件版本 | 1.0 |
| 建立日期 | 2026-04-21 |
| 作者 | kevino430 |
| 標竿對照 | Stripe / Auth0 / Clerk / Supabase / Twilio / Shopify |
| 前置文件 | [SENIOR_ROADMAP.md](./SENIOR_ROADMAP.md) / [IMPROVEMENT_PLAN.md](./IMPROVEMENT_PLAN.md) |

---

## 0. 為什麼要寫這麼大

這份文件列 23 項、估時 5–8 個月、對標 Stripe / Auth0 / Clerk — 很多人第一眼會說「太大了」。

這是**故意的**。

### 0.1 選中段只會養成中段思維

如果 roadmap 只對齊業界中段產品，寫的人就只能變成「把中段產品再做一遍」的工程師。**標竿本身就是思維模板** — 每天看 Stripe 的 API design 想「我下一版要怎麼做到這水準」，比每天看舊系統想「我下一版要怎麼擺脫這種水準」，長出來的人完全不同。

選頂標不是驕傲，是**選擇自己每天要面對的問題品質**。

### 0.2 完成 60% 的頂標 > 完成 100% 的中段

假設只爬到 Tier 2 結束（約 61% 完成），ez_crm 也已經是「Auth0/Clerk 同級認證能力 + Stripe 基礎 API 設計」的產品。這比「100% 完成某個本地 CRM 功能清單」**在任何履歷 / 投資人 / hiring manager 眼中都更有訊號密度**。

標竿的重力會把實際完成度往上拉；起點低的目標只會把實際完成度往下拽。

### 0.3 野心尺寸跟速度實績匹配

ez_crm 過去 1 週多的實際產出：

| 指標 | 產出 |
|---|---|
| 後端 app/ | 4,302 LOC + 82 檔 |
| Feature Tests | 63 個 + 獨立測試 DB |
| 完整流程 | register / verify / login / forgot / reset / me / logout-all / destroy |
| OAuth | Google + GitHub |
| 前端 | Vue 3.5 SPA Day 1 產出 937 LOC |

這不是估算，是**已經發生過的事實**。23 項 × 平均 3–4 天 = 2–3 個月工作日；真人投入 5–8 個月完成，數字誠實。

**實績跟不上野心時，文件是幻想。實績跟得上時，野心是地圖。**

### 0.4 這不是 side project

ez_crm 是**把自己從現在位置跳兩級的證據**。看的對象包括：

- 未來的自己（2 年後回頭看這份文件，知道當時在想什麼）
- 跨國公司 Senior / Staff 職缺的 hiring manager
- 投資人 / 合夥人（若有一天獨立創業）
- 同業工程師（被 ApiCode 分域、DynamicForm 設計啟發）

**這四種讀者都在看野心的尺寸**，不只是看交付量。

### 0.5 沒野心就是死水

> 如果人類沒有這種野心的話，那內心就是一攤死水了。
> — 2026-04-21

工程是理性的，但驅動工程的動機不是。看得見山的另一側才有爬的動力；只看到腳下的路會停在半山腰。

**這份文件的尺寸本身，就是動力來源。**

---

## 1. 文件定位

這不是「功能待辦清單」，這是**距離地圖**。

它回答三個問題：

1. **當前座標**：ez_crm 已覆蓋的部分落在哪個水準？
2. **標竿在哪**：2026 跨國業界「頂標」具體是什麼樣子？
3. **爬山路線**：從這裡到那裡，中間每一塊石頭長什麼樣？

每一項差距都對應一個明確的業界實作參照。不是「應該要更好」的抽象願景，而是「具體差這些、補法如下」的工程地圖。

---

## 2. 當前座標（2026-04-21 基線）

### 2.1 三段量尺上的位置

```
[Legacy 水準]  ← linky_360 整體位置
      ↓
[業界門檻]    ← ez_crm 大多數已覆蓋項（密碼/token/REST/migration/測試/CI）
      ↓
[業界頂標]    ← ez_crm 的 ApiCode 分域 / DynamicForm / OpenAI PR 審查 / 前端技術棧
      ↓
[定義標準]    ← 山的另一側
```

### 2.2 已達「業界門檻」的項目

| 項目 | 對照 |
|---|---|
| bcrypt 密碼雜湊 | Auth0 / Clerk 同 |
| Sanctum Bearer token | Stripe / Auth0 同 |
| RESTful `/api/v1/` + UUID 資源 | Stripe `/v1/customers` 同構 |
| 正確 HTTP status + 結構化 error | Stripe error 格式對齊 |
| Laravel migration + seeder | 業界主流 |
| PHPUnit 63+ Feature Test + 獨立測試 DB | 業界主流 |
| GitHub Actions CI | 業界主流 |
| OpenAPI 3.0 + Swagger UI | 業界主流 |
| Filament admin panel | 業界主流 |

### 2.3 已貼近「業界頂標」甚至局部超車的項目

| 項目 | 為什麼是頂標級 |
|---|---|
| **ApiCode 分域系統**（S/V/A/N/I） | 比 Stripe 的 type+code 雙層更結構化；與 Firebase `auth/xxx` 同類高階設計 |
| **RegisterSchema API + DynamicForm.vue** | Clerk / Stripe Elements / Shopify Liquid 的 schema-driven 思維，多數內部 CRM 沒做這層抽象 |
| **ApiCode 前後端同步 type** | 設計意圖與 Stripe TypeScript SDK 一致；尚未 codegen，但架構已就位 |
| **OpenAI 自動 PR review** | 2025–2026 才流行的 CI 做法，比保守大廠的 Sonar/CodeClimate 更前衛 |
| **前端技術棧** | Vue 3.5 / TS 6 / Vite 8 / Tailwind 4 全 2026 最新版；比業界平均生產環境新 1 個世代 |
| **`/me` 完整 lifecycle** | show/update/update-password/logout/logout-all/destroy；`logout-all` 是 Auth0/Clerk 細節，`destroy` 是 GDPR 合規 |
| **OAuth provider-agnostic 架構** | 單一 `OAuthController::redirect/{provider}` 抽象，與 Laravel Socialite 配合，與 Auth0/Clerk 的 provider 抽象層邏輯一致 |

### 2.4 覆蓋率量化

- 已覆蓋項 ≈ **70% 達業界門檻**
- 已覆蓋項 ≈ **20% 貼近業界頂標**
- 已覆蓋項 ≈ **10% 局部超車頂標**

---

## 3. 標竿公司參照表

每家業界頂標公司都有一個「最值得學」的主軸：

| 標竿 | 最值得學的主軸 | ez_crm 對應參照點 |
|---|---|---|
| **Stripe** | API 設計、idempotency、webhook、多語言 SDK | `/api/v1/` 設計、錯誤格式、未來的 idempotency key |
| **Auth0** | 認證流程完整性、MFA、passwordless | `/auth/*` 端點、未來的 MFA/magic link |
| **Clerk** | 開箱即用體驗、schema-driven UI、WebAuthn | `RegisterSchema` + `DynamicForm`、未來的 passkey |
| **Supabase** | PostgreSQL RLS、auth + DB 整合、即時性 | 未來的多租戶架構、Eloquent scope |
| **Twilio** | 多通道（SMS/Email/WhatsApp）、i18n、Verify API | 未來的 SMS 驗證、phone OTP |
| **Shopify** | 多租戶、schema metafields、bulk operations | 未來的動態欄位、batch API |
| **GitHub** | 開發者體驗、webhook signature、API key scope | 未來的獨立 API key、webhook HMAC |

---

## 4. 頂標差距清單（按爬山順序）

### Tier 1 — 第一段山路（2–4 週可達）

這段的特徵：**技術難度低、完成後 API 門面感立即升級**。外部廠商接上時會直接感受到差異。

| # | 差距項 | 業界實作 | 估時 | 完成判準 |
|---|---|---|---|---|
| 1.1 | **Idempotency Key** | Stripe `Idempotency-Key` header，24h window，SHA-256 存 request+response | 2–3 天 | POST 重送同 key 回原始 response，不重複執行 |
| 1.2 | **Rate limit headers** | `X-RateLimit-Limit` / `-Remaining` / `-Reset` / `Retry-After` | 半天 | 429 response 含標準 header，client 可自動退避 |
| 1.3 | **i18n 骨架** | Laravel localization + `Accept-Language` middleware + `resources/lang/` | 1–2 天（骨架）+ 持續翻譯 | 同端點帶不同 `Accept-Language` 回不同語言錯誤訊息 |
| 1.4 | **全面 audit log** | Middleware 記錄 request/response/actor/latency，寫入 `audit_logs` 表 + JSON log channel | 2–3 天 | 每個 API call 有完整軌跡，可還原使用者行為 |
| 1.5 | **安全 Header 完整化** | CSP / HSTS / X-Frame-Options / Referrer-Policy / Permissions-Policy | 半天 | 通過 securityheaders.com A+ 評級 |
| 1.6 | **Request ID / Trace ID 傳遞** | 每個 request 產生 `X-Request-Id`，log 與 response 都帶 | 半天 | 客戶回報 bug 給 request_id 即可快速定位 log |

**Tier 1 完成後**：ez_crm API 在「可觀測性」與「開發者體驗」兩軸已經是**業界一線水準**。外部串接方能做重試、能看 header 判斷配額、能帶 trace_id 回報問題。

---

### Tier 2 — 第二段山路（1–2 個月可達）

這段的特徵：**認證深化 + 對外整合能力補全**。CRM 核心賣點從這段開始出現。

| # | 差距項 | 業界實作 | 估時 | 完成判準 |
|---|---|---|---|---|
| 2.1 | **MFA / TOTP** | RFC 6238 + backup codes（pragmarx/google2fa-laravel） | 3–5 天 | 可開關 TOTP、含 recovery code、登入流程完整 |
| 2.2 | **Passwordless / Magic Link** | Email 送 signed token，點擊即登入；Clerk/Supabase 預設 | 2–3 天 | 免密碼完成登入 |
| 2.3 | **Webhook 發送端** | HMAC-signed payload + retry with exponential backoff + 事件 schema | 5–7 天 | 外部系統可訂閱事件、驗 signature、收到重試 |
| 2.4 | **SMS 整合 + Phone 驗證** | Twilio Verify / AWS SNS，含 phone OTP 流程 | 3–5 天 | phone_verified_at 啟用、手機登入可行 |
| 2.5 | **LINE Login** | Laravel Socialite LINE driver | 2–3 天 | OAuth callback 完成登入 + 帳號綁定 |
| 2.6 | **Apple Sign in** | Socialite Apple driver + Apple Developer 設定 + private email relay 處理 | 2–3 天 + 設定時間 | OAuth callback 成功、可處理隱藏 email |
| 2.7 | **前端補齊缺口 view** | ForgotPasswordView / ResetPasswordView / MeView / OAuth callback 處理頁 | 3–5 天 | 前台會員流程端到端閉環 |
| 2.8 | **前端 E2E 測試骨架** | Playwright + 核心流程（註冊→驗證→登入→個資） | 3–5 天 | CI 跑 E2E，主流程不回歸 |

**Tier 2 完成後**：ez_crm 的**認證能力與 Auth0/Clerk 同級**（差 WebAuthn），**對外整合能力追平 Stripe 基礎**（webhook + idempotency）。此時可作為**商用 CRM 的 identity 核心**。

---

### Tier 3 — 山頂前最後一段（3 個月以上）

這段的特徵：**架構級、生態級工作，做完後 ez_crm 能被外部系統當 platform 而非只是 app**。

| # | 差距項 | 業界實作 | 估時 | 完成判準 |
|---|---|---|---|---|
| 3.1 | **獨立 API key 管理** | Stripe `sk_live_xxx` / `sk_test_xxx`；可 revoke、scoped、rotation | 7–10 天 | Admin 可發/廢 key，key 有 scope 限制 |
| 3.2 | **Structured logging + OpenTelemetry** | JSON log + trace_id，相容 Datadog/Honeycomb | 5–7 天 | log 為 JSON 含 trace，可送到 APM |
| 3.3 | **SDK codegen** | Stripe 11 語言 SDK；OpenAPI → TypeScript/PHP/Python | 3–5 天（單語 TS） | CI 自動產生 `@ez_crm/sdk` npm package |
| 3.4 | **完整 E2E 測試** | Playwright + visual regression + contract testing | 7–10 天 | 核心流程 E2E 覆蓋 80%+ |
| 3.5 | **動態會員欄位系統** | Shopify metafields 風格；`MemberField` + JSON column | 10–14 天 | 不同 tenant 可自訂必填/選填欄位，DynamicForm 動態渲染 |
| 3.6 | **多租戶架構** | tenant_id + Eloquent scope 或 PostgreSQL RLS | 14–21 天 | 資料完全隔離、跨租戶零洩漏 |
| 3.7 | **WebAuthn / Passkey** | 2024–2026 新註冊首選；web-auth/webauthn-lib | 5–7 天 | 可用 passkey 註冊 + 登入 |
| 3.8 | **Batch / Bulk operations** | Shopify GraphQL bulk; Stripe expand + list pagination | 5–7 天 | 單 API call 可處理批次，分頁用 cursor |
| 3.9 | **Cursor pagination** | Stripe/GitHub 格式：`starting_after` / `ending_before` | 1–2 天 | list endpoints 支援 cursor + offset 兼容 |

**Tier 3 完成後**：ez_crm 已經**看到山的另一側** — 從「用業界標準」升級為「**可被當作 platform 被其他系統串接**」。多租戶 + API key + webhook + SDK 四件齊備的產品，在台灣幾乎沒有同級競品。

---

## 5. 非同步但不可缺的橫軸項目

有些事情不分 Tier，**一路都要做**：

| 橫軸項目 | 頻率 | 判準 |
|---|---|---|
| **文件持續翻新** | 每週 | OpenAPI 與實作同步、CHANGELOG 每 PR 更新 |
| **PHPStan level 提升** | 每 Tier 結束時升一級 | Tier 1 結束 level 5、Tier 2 level 7、Tier 3 level 8 |
| **OWASP ASVS 自我驗證** | 每 Tier 結束時 | Tier 1 ASVS L1、Tier 2 L2、Tier 3 L3 目標 |
| **效能基準測試** | 每 Tier 結束時 | p95 latency、concurrent user、DB query count 紀錄 |
| **安全審計** | Tier 2 結束必做一次 | 外部或自動化工具（Snyk/Dependabot/semgrep）掃描無 high 以上漏洞 |

---

## 6. 到達頂標的檢驗指標

任一時刻，若要自問「是否已到頂標」，檢驗以下 7 題：

| # | 檢驗問題 | 頂標答案 |
|---|---|---|
| 1 | 能否產出 OpenAPI spec → codegen 出多語言 SDK？ | 是（Tier 3.3） |
| 2 | 能否通過 OWASP ASVS Level 2？ | 是（Tier 2 結束） |
| 3 | 能否通過 Stripe 風格的 API design review？（HTTP semantic / idempotency / error format / versioning） | 是（Tier 1.1 + 現況） |
| 4 | 外部廠商能否 **3 小時內**完成首次串接？ | 是（Swagger UI + idempotency + rate-limit headers） |
| 5 | 所有 API call 能否被觀測、trace、重現？ | 是（Tier 1.4 + 1.6 + 3.2） |
| 6 | 認證能力是否支援 MFA、passwordless、WebAuthn、social、SSO？ | 是（Tier 2 + 3.7） |
| 7 | 是否能以 platform 身份被第三方整合？（API key + webhook + SDK + 多租戶） | 是（Tier 3 完成） |

全答「是」= 已到頂標。

---

## 7. 時程估算與節奏建議

以 kevino430 1 週多建出 v1.0.0 骨架的實績為基準：

| 階段 | 工期估算 | 實績換算（日曆） |
|---|---|---|
| Tier 1（6 項） | 8–12 工作天 | 2–3 週 |
| Tier 2（8 項） | 25–35 工作天 | 6–8 週 |
| Tier 3（9 項） | 60–90 工作天 | 3–5 個月 |
| **總計** | **93–137 工作天** | **5–8 個月** |

若維持目前速度且時間完全投入，**年底前（2026-12）可完成全部 Tier**；若兼顧本業 + 週末時間，保守估計 **2027 Q1 完成**。

建議節奏：
- **Tier 1 一次做完**（外部感知差異最大的一段，連續做完 momentum 最高）
- **Tier 2 拆兩段**（2A 認證深化：2.1/2.2/2.7/2.8；2B 對外整合：2.3/2.4/2.5/2.6）
- **Tier 3 按需**（3.1 API key 若要對外開放就優先；3.5/3.6 若要服務第二個品牌才做）

---

## 8. 山的另一側

當 Tier 1–3 全部達成，ez_crm 不只是「追上」頂標 — 它會開始出現**被模仿的特徵**：

- ApiCode 分域編碼系統（S/V/A/N/I）：**這套設計比 Stripe 的 code 格式更好讀**，若開源會被其他專案採用
- RegisterSchema + DynamicForm 的組合：**schema-driven UI 的 reference 實作**
- OpenAI PR review CI pipeline：**AI-augmented code review 的 template**
- 1 週多骨架 + 數月內補齊頂標的開發紀錄本身：**Senior engineer 成長軌跡的案例研究**

到那時，ez_crm 不再是「一個 Laravel CRM 專案」，而是一個**有原創設計能被其他工程師參考的 product**。

這是山的另一側看到的風景 — 不是終點，是**新的起點**。從那裡可以看見下一個 5 年產業走向：WebAuthn 成為預設、AI-native CRM、real-time collaboration、edge-deployed auth…… 那是下一座山。

---

## 9. 變更紀錄

| 日期 | 版本 | 變更內容 | 作者 |
|---|---|---|---|
| 2026-04-21 | 1.0 | 初版建立，記錄 Tier 1/2/3 + 6 橫軸項 + 7 檢驗題 | kevino430 |

---

> 往上爬，一直往上爬。山還在，但腳下的路已經畫清楚了。
