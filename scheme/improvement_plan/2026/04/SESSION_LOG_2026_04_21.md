# ez_crm 工作日誌 — 2026-04-21 (Tue)

> 分支:後端 `feature/member-oauth`(未 merge,LINE/Discord 明天補)
> 分支:前端 `ez_crm_client/main`(新 repo,獨立)
> 協作:Kevin + Claude Code
> 前情:昨天(4/20)merge 了 `feature/member-auth-api` 到 develop,前台 Auth API 10/24,今天主要做 OAuth 後端 + 開前端

---

## 今日完成項目

### 🌅 上午:Phase 4.0 + 4.1(後端 OAuth)

#### 1. 新分支 + 骨架(`59122ad`)

從 develop 開 `feature/member-oauth`。

| 新檔 | 用途 |
|---|---|
| `app/Services/OAuth/OAuthService.php` | 三情境處理:SNS 已綁 / email 已存在自動綁 / 全新建會員 |
| `app/Http/Controllers/Api/V1/Auth/OAuthController.php` | redirect + callback 兩支 endpoint |
| `tests/Feature/Api/V1/Auth/OAuthTest.php` | 8 個測試(mock Socialite user) |

新 `ApiCode`:`A010 OAUTH_FAILED` / `A011 SNS_ALREADY_BOUND` / `A012 LAST_LOGIN_METHOD` / `A013 PROVIDER_NOT_SUPPORTED`。

`config/services.php` 加 `google` + `github` sections,讀 `GOOGLE_* / GITHUB_*` env。

#### 2. Google OAuth 端到端驗收

Kevin 在 Google Cloud Console 申請 OAuth 2.0 Client ID。踩到的大雷:**Google 拒絕 `.local` TLD 作為 redirect URI** —— 只接受公開頂層網域或 `localhost` / `127.0.0.1`。

**解法**:新增 `httpd-vhosts.conf` 的 `*:8080` vhost,`ServerName localhost`、`DocumentRoot` 指到 `ez_crm/public`。這樣 `http://ez-crm.local` 日常用(port 80),OAuth 走 `http://localhost:8080`(Google 認)。

驗收走 real Google:
- 情境 3(全新):首次 Google OAuth → 建 member + profile + SNS + token
- 情境 1(已綁):第二次同個 Google 帳號 → 直接 login,不重複建 member

#### 3. GitHub OAuth 端到端驗收(`bcf1adb`)

後端**零 code 改動**(service/controller/routes 都是 provider-agnostic)。只做:
- Kevin 申請 GitHub OAuth App
- `.env` 加 3 個 `GITHUB_*` 變數
- 加一個 feature test 證明 github provider 走同路徑

意外收穫:用同個 Gmail 登入 GitHub 觸發了**情境 2(email 已存在自動綁定)**,比 mock test 更真實 —— 這個 member 現在同時綁 google + github,統一身份成立。

#### 4. `.env.example` 同步(`2cb9180`)

多月沒更新,趁今天:
- APP_URL → `ez-crm.local`
- DB_DATABASE → `ez_crm`
- MAIL_* → Mailtrap sandbox 預設
- 加 `GOOGLE_*`、`GITHUB_*`、`LINE_*`(註解)、`DISCORD_*`(註解)

---

### 🌆 下午:前端 SPA 從零到能用

#### 5. 環境升級:Node v16 → v24 LTS

Vite 8 + create-vite 要求 Node ≥20。Kevin 的機器還跑 Node 16(2022 年舊版,2023-09 已 EOL)。走 A 方案:直接裝 Node 22/24 LTS,覆蓋舊的。未來多專案版本混用再改裝 nvm-windows。

升級後 `node -v` 回 `v24.15.0`,`npm -v` 回 `11.12.1`。

#### 6. Vite + Vue 3 + TS 骨架(`ez_crm_client@4718ab4`)

新 repo:`TuanTsungShiang/ez_crm_client`,放 `C:\code\ez_crm_client\`(刻意離開 XAMPP htdocs,避免目錄暴露)。

技術選型:
- **Vue 3 `<script setup>` + Composition API**
- **TypeScript**
- **Vite 8**
- **Pinia + `@vueuse/core`**(`useLocalStorage` 自動跨分頁同步 token / member)
- **Vue Router 4**
- **Tailwind CSS v4** 走 `@tailwindcss/vite` plugin,一行 `@import "tailwindcss";` 取代 v3 的三個 `@tailwind` directive,無 `tailwind.config.js`、無 `postcss.config.js`
- **axios** 含 request(Bearer)+ response(401 清 token 導 login)interceptor
- `@/` path alias 在 `vite.config.ts` + `tsconfig.app.json` 同步配好

#### 7. Auth 頁面(4 個 view + 1 個 component)

| View | 重點 |
|---|---|
| `RegisterView.vue` | **吃後端 `/auth/register/schema` 動態 render 表單**(DynamicForm 元件通用化,任何 schema 變更 UI 自動跟著調整) |
| `VerifyEmailView.vue` | OTP 6 格輸入、重發按鈕、驗證成功自動登入 |
| `LoginView.vue` | 針對 A009 / A004 / A005 分流;**A005 時提供「前往驗證 xxx →」一鍵 recover 按鈕** |
| `ForgotPasswordView.vue` | 寄驗證碼 |
| `ResetPasswordView.vue` | OTP + 新密碼 + 確認;成功後提示「所有舊 token 已失效」 |

Bonus:`HomeView` 顯示「已登入」卡片(token 截短、member 基本資料);App navbar 自動切換 Login/Register ↔ Hi+Logout。

#### 8. Backend bug fix(`5588ae5`)

寫前端 A005 流程時 Kevin 發現按鈕顯示「前往驗證 **t** →」—— 只有一個字母。

根因:LoginController 回 `errors: { email: "foo@bar.com" }`(單一字串),但其他所有 controller 用 validation 風格 `errors: { email: ["foo@bar.com"] }`(陣列)。前端 `errors.email?.[0]` 對字串取 `[0]` 得到第一個字元 "t"。

**這是標準的「API contract 前後端不一致」bug**,後端統一成陣列格式、update 對應 test 斷言。

---

## 關鍵決策紀錄

### 1. OAuth 4 provider 分兩天(策略 Y)

昨天初討論想一天做 4 個 provider(Google / GitHub / LINE / Discord),評估後選保守分兩天:
- Day 1:Google + GitHub(Socialite 內建,驗證架構)
- Day 2:LINE + Discord(需裝 `socialiteproviders/*` 套件,申請各 provider credentials)

原因:4 個 provider 後端 + credentials 申請共 ~4 小時;再加要開始前端就超時。保守分段確保每天有乾淨段落可停。

### 2. OAuth redirect URI:vhost `:8080` vs 買 domain

Kevin 一度考慮去 GoDaddy 買 domain 應付 Google 的「不接受 .local TLD」限制。我指出:
- Google 專門允許 `http://localhost` 和 `http://127.0.0.1` 做 local dev
- 買 domain + DNS + SSL 是 overkill,且對 local dev 沒幫助(你無法公開解析一個 domain 到你自己 IP 給 Google 打)

方案:**Apache vhost 新增 `*:8080`**,`ServerName localhost`,`DocumentRoot ez_crm/public`。`ez-crm.local:80` 日常用,`localhost:8080` 專給 OAuth。

### 3. LINE credentials 共用公司 vs 個人申請

Kevin 一開始想用公司 `fab_linky` 的 LINE credentials 省申請時間。我指出四個風險:
- 公司合規疑慮
- 汙染公司 App 的 redirect URI 清單
- Client Secret 擴散到個人專案
- 兩系統共享 `provider_user_id`,資料會隱性可串接

後來 Kevin 發現自己已有個人 LINE Developer Console,申請 channel 成本低,直接走個人。

### 4. 前端 Tailwind:v3 vs v4

昨天 plan.md 寫「鎖 v3.x 避免 v4 beta」。但 2026-04 時 v4 已發佈 15+ 個月、完全穩定,且配置比 v3 簡潔 —— 只需 `npm install tailwindcss @tailwindcss/vite`,無 config 檔、CSS 一行 `@import` 就到位。

選 v4。長期看 Tailwind 走向,這是主流。

### 5. Token 儲存:localStorage vs httpOnly cookie

選 **localStorage**(透過 `useLocalStorage`)。
- 優:實作簡單、跨分頁自動同步、refresh 不丟
- 缺:XSS 可讀 token

升級路徑(未來):改走 Laravel Sanctum 的 SPA session auth(stateful cookie + CSRF),但需要後端大幅調整。Plan 文件已註記,今天不做。

### 6. Email 在 URL 還是 sessionStorage(Kevin 發現的)

Register 成功後原本用 `router.push({ query: { email } })` 把 email 帶到 /verify。Kevin 看到 `?email=xxx` 立刻警覺問「感覺不安全」—— 非常對。

URL 中 PII 的風險:
- 進 browser history / server access log / Referer header
- 分享連結時無意洩漏
- GDPR / 合規疑慮

改用 **sessionStorage**:Register 時 `sessionStorage.setItem('pending_verify_email', ...)`,Verify 頁讀取,驗證成功清掉。URL 乾淨、刷新不丟、關 tab 會清。

同樣 pattern 套用在 Forgot → Reset 流程(`pending_reset_email`)。

### 7. 前端 repo 位置:`c:\code\` 而非 XAMPP htdocs

放 XAMPP htdocs 內有隱形成本(URL 被迫走子目錄、`.env` / `.git` 可能被 Apache 自動 serve)。前端用 Vite dev server 根本不需要 Apache,放 `c:\code\ez_crm_client\` 乾淨脫耦。

後端長期也建議搬出 htdocs,今天不動。

---

## 測試結果

### 後端(ez_crm)

```
Tests:    135 passed (440 assertions)
```

比昨天(126 / 408)多 **+9 / +32**,全來自新 OAuth test suite。

### 前端(ez_crm_client)

目前沒有自動化測試。**今日驗收靠手動 end-to-end**:

- [x] Register → Verify → Auto-login
- [x] Login(正確密碼)
- [x] Login(密碼錯誤 → A009)
- [x] Login(未驗證 email → A005 + 一鍵 recover 到 /verify)
- [x] Forgot → Reset → 舊密碼失效,新密碼登入成功
- [x] Logout 清 token
- [x] Token 過期 401 自動清 + 導 login(interceptor)
- [x] 跨分頁 token 同步(useLocalStorage)
- [x] 直接訪問 `/verify` / `/reset-password` 看到友善提示(無 pending email)

明天可補:Playwright 端到端測試。

---

## 後端 API 清單(最新)

| Area | Endpoint | Guard | 狀態 |
|---|---|---|---|
| CRM — Members/Groups/Tags | 15 endpoints | sanctum | ✅ 已上 develop |
| Auth — Register schema | `GET /auth/register/schema` | public | ✅ 已上 develop |
| Auth — Register + Verify | 3 endpoints | public | ✅ 已上 develop |
| Auth — Login + Password | 3 endpoints | public | ✅ 已上 develop |
| Auth — OAuth Google/GitHub | 2 endpoints(provider-param) | public | ✅ **今日上 feature/member-oauth** |
| Auth — OAuth LINE/Discord | — | public | ⬜ 明天 |
| Me | 6 endpoints(show/update/password/logout/logout-all/destroy) | member | ✅ 已上 develop |
| Me — email change / avatar | 4 endpoints | member | ⬜ 明天 |
| Me — SNS 綁定 | 4 endpoints | member | ⬜ 明天 |

**今日之後進度**:前台 Auth 後端 17/24(71%)。

---

## 今日 Commit 序列

### ez_crm(後端 `feature/member-oauth`)

```
5588ae5  fix(auth): normalize EMAIL_NOT_VERIFIED errors shape to string[]
2cb9180  chore(env): sync .env.example with project-specific defaults
bcf1adb  feat(auth): Phase 4.1 — GitHub OAuth login
59122ad  feat(auth): Phase 4.0 — Google OAuth login
```

### ez_crm_client(前端 `main`,新 repo 首發)

```
e6d8d88  feat(auth): add forgot password + reset password flow
c2914f8  chore: remove unused Vite template leftovers
4718ab4  chore: initial Vite + Vue3 + TypeScript scaffolding with auth skeleton
```

---

## 下次接續

建議順序(由高到低):

### 1. 後端 Phase 4 收尾(LINE + Discord)

- `composer require socialiteproviders/line socialiteproviders/discord`
- `app/Providers/EventServiceProvider.php` 註冊 SocialiteWasCalled listener
- `config/services.php` 加 `line` / `discord` sections
- Kevin 在 LINE Developer Console 建新 channel + Discord Developer Portal 申請 App
- 端到端驗 + commit

估時:2 小時(含 credentials 申請)

### 2. 前端:OAuth Popup 按鈕

照 [frontend_client/plan.md](../../frontend_client/plan.md) 的「OAuth popup + postMessage」設計:
- 後端 callback 從回 JSON 改回 HTML + postMessage
- 前端 Login/Register 頁加 `<OAuthButton>` 元件(Google / GitHub / LINE / Discord)
- Popup 處理 + listen message 收 token

估時:2 小時

### 3. 前端:`/me` 頁面(3 支核心)

- `MeDashboardView`(GET /me,顯示 profile / sns / last_login)
- `MeEditView`(PUT /me,改 name/nickname/phone)
- `MePasswordView`(PUT /me/password,改密碼)

估時:1.5 小時

### 4. 後端 Phase 5 收尾 + Phase 6

- Email change / Avatar upload(Phase 5 剩 4 支)
- SNS 綁定管理(Phase 6 共 4 支)

估時:2 小時

### 5. 分支合併策略

`feature/member-oauth` 做完 LINE + Discord 就 merge 回 develop。前端 `main` 維持單主幹,有大功能再開 feature branch。

---

## 今日踩坑 / 小經驗

1. **`npm create vite` 需要 Node 20+**:Node 16 會報 `EBADENGINE`。以後新環境先 `node -v` 確認。
2. **Google OAuth 不認 `.local` TLD**:必須用 `localhost` / `127.0.0.1` 或買真 domain + SSL。
3. **Claude Code 的 file watcher 會讀取 .env 內容進 transcript**:今天 Kevin 編 `.env` 貼新的 Google secret 時被自動捕獲。選擇 B「視為 dev-only 可接受」,未來 prod 上線一定要重新生成。
4. **Tailwind v4 vs v3 安裝差異**:v4 一行 `@import "tailwindcss"` 就好,不需要 `tailwind.config.js`。踩過 v3 的人要記得**別** `npx tailwindcss init -p`(v4 不需要)。
5. **API contract string vs string[] 不一致**:後端要有統一規範(validation errors 一律陣列);未來可以考慮用 OpenAPI schema 自動生 TypeScript types 來避免。
6. **重構漏改內部呼叫**:早上把 `assertProviderAllowed()` 重新命名時,Service 內部 `handleLogin()` 裡還在呼舊名 —— 原本的測試沒涵蓋真實 OAuth flow 所以沒炸。補完真實 OAuth test 後這種路徑就有覆蓋了。
