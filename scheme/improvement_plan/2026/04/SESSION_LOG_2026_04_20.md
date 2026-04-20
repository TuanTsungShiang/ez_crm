# ez_crm 工作日誌 — 2026-04-20

> 分支：`feature/member-auth-api`（從 develop 開，尚未 merge）
> 協作：Kevin + Claude Code
> 前情：上週五（4/17）收尾了 `feature/group-tag-management`；今早先把它 merge 回 develop 並清掉殘留分支

---

## 今日完成項目

### 前段：環境面收尾

#### 1. Group/Tag Swagger + Filament 收尾 → merge 回 develop

上週留下的 3 件收尾：

| Commit | 內容 |
|---|---|
| `4820406` docs(api) | Group/Tag 的 `@OA` 註解（原本 CRUD commit 漏掉） |
| `2e4ed3a` docs(scheme) | Member Auth API 落地方案 + SCIM 2.0 reference |
| `f239abc` feat(admin) | Filament MemberGroup + Tag Resource |

走 Gitflow：`feature/group-tag-management` → `develop`（--no-ff），同時順手清掉 `feature/filament-admin`、`feature/member-crud`、`feature/optimize-member-search`、`feature/member-search-api` 四個殘留分支。現在本地 + remote 都只剩 `develop` + `main`。

#### 2. 環境乾淨化：XAMPP subfolder → virtual host

**原本**：`http://localhost/ez_crm/public/admin`
**現在**：`http://ez-crm.local/admin`

Livewire 在 subfolder 部署會 hardcode `/livewire/livewire.js` 這種 root-relative URL → 404。根因是 Livewire 不知道自己活在 `/ez_crm/public` 下。兩個解法：
- A：讓 URL 沒有子目錄（vhost / `artisan serve`）
- B：patch Livewire config + `setUpdateRoute`

走 A。在 `C:\xampp\apache\conf\extra\httpd-vhosts.conf` 新增兩個 vhost（localhost 保留原行為 + 新 `ez-crm.local` docroot 指到 `public/`），`.env` 改 `APP_URL=http://ez-crm.local`。副作用：`.env` / `.git` 不再暴露於 htdocs 自動目錄 serving。

#### 3. Mailtrap 接通

原本想用 SendGrid，但現已 trial 限制嚴格。改用 Mailtrap 的 Email Testing Sandbox（免費永久）：開發寄信全部被攔截在 sandbox inbox，不污染真實信箱也不會誤寄。重點：跟 Sending 產品（真送真客戶）不是同個東西，Kevin 一開始走錯頁。

**TOKEN 外洩事件（輕度）**：Kevin 第一次貼 Mailtrap credentials 時連 password 都貼到對話裡。立即走 4/10 token_security SOP：Reset Credentials → 新密碼直接進 `.env`、不再貼回對話。

---

### 主戰場：前台會員 Auth API（Phase 0–3 + Phase 5 部分）

依 [ez_crm_auth_api_plan.md](../../../api/ez_crm_auth_api_plan.md) 開 `feature/member-auth-api` 從 develop 起。

#### Phase 0：前置地基（`5cdb86e`）

| 項目 | 做了什麼 |
|---|---|
| Socialite | `composer require laravel/socialite`（今天還沒用，Phase 4 才會）|
| `member` guard | `config/auth.php` 加 `sanctum` + `member`（driver=sanctum / provider=members）|
| Member Model | 改繼承 `Authenticatable`、加 `HasApiTokens` / `Notifiable`、`password` cast 為 `hashed`、加 `verifications/addresses/loginHistories/devices` 關聯、加 `hasVerifiedEmail()` / `markEmailAsVerified()` helper |
| 新 Models | `MemberVerification` / `MemberAddress` / `MemberLoginHistory` / `MemberDevice`（table 原本就有，Model 沒建）|

驗收：`Member::first()->createToken('...')` 成功發 Sanctum token。

#### Phase 1：Register Schema API（`0afd5e6`）

`GET /api/v1/auth/register/schema`（public）—— 讓前端 dynamic render 註冊表單。回傳 fields 定義、rules、oauth_providers。

#### Phase 2：註冊 + Email OTP 驗證（`03a6cce`）

三支 endpoint：
- `POST /api/v1/auth/register` —— 建 pending member + 寄 OTP
- `POST /api/v1/auth/verify/email/send` —— 重發 OTP（60s cooldown）
- `POST /api/v1/auth/verify/email` —— 驗證成功 → `status=ACTIVE` + 發 Sanctum token

新檔：
- `OtpService`（generate / verify / isThrottled）
- `SendOtpNotification`（Mail 通道，subject 三態：email / password_reset / email_change）
- 3 個 FormRequest（extends BaseApiRequest，`authorize=true`）
- 3 個 Controller

**重要 bug fix**：`Member::STATUS_SUSPENDED = 2` 命名錯 —— DB migration comment 明寫 `2=待驗證`，而非「停用」。改名 `STATUS_PENDING`。零外部 caller，安全。

Mailtrap 端到端驗收通過。

#### Phase 3：登入 + 密碼忘記/重設（`fd9f81e`）

三支 endpoint：
- `POST /api/v1/auth/login` —— email+password，發 token
- `POST /api/v1/auth/password/forgot` —— 寄 password_reset OTP（不存在 email 也回 200 不洩漏）
- `POST /api/v1/auth/password/reset` —— 驗 OTP，更新密碼，**撤銷所有 token**

Login 檢查順序（回 code）：
1. credentials → A009 (422)
2. INACTIVE → A004 (403)
3. email not verified → A005 (403) + email hint
4. OK → 發 token + 記 history

每次登入（成功/失敗）都寫 `member_login_histories`（ip / user_agent / platform / login_method / status）。

#### Phase 5（部分）：/me 自助資料管理（`fca1a0c`）

三支 endpoint（走 `auth:member` middleware）：
- `GET /api/v1/me` —— 用 `MemberDetailResource`（跟 admin show 同一個 Resource）
- `PUT /api/v1/me` —— partial update name / nickname / phone，phone unique 時 ignore self
- `PUT /api/v1/me/password` —— `current_password` 驗證，成功後**撤銷其他 token 但保留當前**

Phase 5 計畫書原有 10 支，今天只做 3 支（show / update / update-password）。剩下 7 支（email change / avatar / logout / logout-all / destroy）留下次。

Swagger 重新 generate：25 operations（CRM 15 + Auth 7 + Me 3）。

---

### 後段：Feature Tests（`a4714d8`）

31 個新測試 / 126 assertions。覆蓋今天 10 支 endpoint：

| Test 檔 | 數量 | 重點 |
|---|---|---|
| `AuthRegisterSchemaTest` | 2 | 結構 / public access |
| `AuthRegisterTest` | 4 | happy + Notification assertion / dup email V006 / 缺 agree_terms / weak password |
| `AuthEmailVerificationTest` | 8 | verify happy + wrong/replay/unknown-email A007；resend happy + already-verified A006 + cooldown A008 + unknown silent |
| `AuthLoginTest` | 5 | happy login + 記 history + 更新 last_login_at；wrong password 記 failure A009；unknown 無 history；inactive A004；unverified A005 |
| `AuthPasswordResetTest` | 4 | forgot 有/無 email（Notification::fake + assertNothingSent）；reset happy + 撤銷 tokens；reset wrong code A007 保留舊密碼 |
| `MeTest` | 8 | 401 without token；GET /me；PUT partial；phone ignore-self；phone V006 against other；password wrong-current A009；password same-as-current V002 "different"；password success 撤銷其他 token |

技巧：
- `Notification::fake()` + `assertSentTo()` 驗證 mail 副作用，不打 SMTP
- `Sanctum::actingAs($member, [], 'member')` 測 member guard

---

## 測試結果

```
Tests:    121 passed (391 assertions)
Duration: 83s
```

從昨天 `90 passed / 265 assertions` → 今天 `121 passed / 391 assertions`（+31 / +126）。

---

## 關鍵決策紀錄

### 1. `STATUS_PENDING` 命名取代 `STATUS_SUSPENDED`

DB migration 的 `status` 欄位 comment 寫 `1=正常 0=停用 2=待驗證`，值 `2` 的語意是「等待 email 驗證」，不是「被處分停用」。Plan 從別處複製時寫成 `STATUS_SUSPENDED = 2`，命名上會讓 reader 誤判。趁沒人依賴時改掉。

### 2. 為何不把 `current_password` 擺進 `ApiCode::$ruleMapping`

Laravel 有內建 `current_password` validation rule，但 BaseApiRequest 的 rule→code 對應表只處理通用 rules。把「當前密碼錯」映射成 V002（INVALID_FORMAT）語義不對；映射到專屬 A 系列又只為單一場景。選擇在 Controller 手動 `Hash::check` + return `A009 INVALID_CREDENTIALS`，message 寫「目前密碼錯誤」讓前端區分是登入失敗還是改密碼失敗。

### 3. 撤銷其他 token vs 全部 token

- 忘記密碼重設：**全撤**（包括當前）—— 通常是別處重設，需全裝置重新登入
- 改密碼（/me/password）：**只撤其他**，保留當前 —— UX 不想讓正在操作的裝置也掉 session

Sanctum `currentAccessToken()->id` 配合 `where('id', '!=', ...)` 實現。

### 4. 不存在的 email 回 200 vs 404

`password/forgot` 和 `verify/email/send` 對不存在 email 都回 200 不透露。經典 account enumeration 防護。`/login` 則對 wrong credentials + unknown email 都回 A009 同樣避免洩漏。但 `/verify/email` 對 unknown email 回 A007（因為這條路徑本來就是「email+code 都對才通」，統一一種失敗 code 也合理）。

### 5. 為何選 A 方案（vhost）而不是 C（hack Livewire config）

Livewire 的 subfolder 問題即使 patch 了，未來 Socialite OAuth callback、mail link、file upload 等還會踩類似坑（每個套件都要補 prefix）。vhost 是一次解到底。

### 6. Mailtrap 的產品選型

- Email Testing (Sandboxes) = 假信箱，免費，適合 dev/test → **我們選這個**
- Email Sending (Transactional) = 真送給客戶，付費，適合 production
左側 UI 首頁預設會帶去 Transactional（付費引流），新手容易走錯。

---

## 目前 API 清單

| Area | Endpoint | Guard | 狀態 |
|---|---|---|---|
| CRM — Members | `/api/v1/members` ×5（CRUD + search） | sanctum | ✅ |
| CRM — Groups | `/api/v1/groups` ×5 | sanctum | ✅ |
| CRM — Tags | `/api/v1/tags` ×5 | sanctum | ✅ |
| Auth — Register | `/api/v1/auth/register/schema` | public | ✅ |
| Auth — Register | `/api/v1/auth/register` | public | ✅ |
| Auth — Register | `/api/v1/auth/verify/email/send` | public | ✅ |
| Auth — Register | `/api/v1/auth/verify/email` | public | ✅ |
| Auth — Login | `/api/v1/auth/login` | public | ✅ |
| Auth — Password | `/api/v1/auth/password/forgot` | public | ✅ |
| Auth — Password | `/api/v1/auth/password/reset` | public | ✅ |
| Me | `/api/v1/me` GET / PUT | member | ✅ |
| Me | `/api/v1/me/password` PUT | member | ✅ |
| Me | `/api/v1/me/email/change/...` | member | ⬜ |
| Me | `/api/v1/me/avatar` | member | ⬜ |
| Me | `/api/v1/me/logout`, `/logout-all` | member | ⬜ |
| Me | `/api/v1/me` DELETE | member | ⬜ |
| Auth — OAuth | `/api/v1/auth/oauth/*` | public | ⬜ |
| Me — SNS 綁定 | `/api/v1/me/sns/*` | member | ⬜ |

**進度**：前台 Auth API 24 支完成 10 支（41%）。

---

## 今天的 commit 序列

```
a4714d8  test(auth): add feature tests for the 10 front-end Auth endpoints
fca1a0c  feat(auth): Phase 5 (partial) — /me show / update / update-password
fd9f81e  feat(auth): Phase 3 — login + forgot/reset password
03a6cce  feat(auth): Phase 2 — register + Email OTP verification
0afd5e6  feat(auth): Phase 1 — Register Schema API
5cdb86e  feat(auth): Phase 0 — member guard, Sanctum-ready Member, aux models
```

加上早上收尾 `feature/group-tag-management` 的 3 個 commit + merge commit，今天 branch log 共 9 筆提交 + 1 個 merge。

---

## 下次接續

優先順序由高到低：

1. **Phase 4 — OAuth（Google + GitHub）**：
   - 要先在 Google Cloud Console / GitHub Developer Settings 申請 Client ID / Secret
   - Redirect URI 用 `http://ez-crm.local/api/v1/auth/oauth/{provider}/callback`（上線再換）
   - `OAuthService::handleLogin()` 三情境：已綁 / email 存在自動綁 / 全新建帳號
   - 估時：90 分（不含申請 credentials 那 15 分）

2. **Phase 5 剩 7 支**：
   - Email change（request + verify，用 cache 暫存新 email）
   - Avatar upload（用 `storage/app/public` + `storage:link`）
   - Logout / Logout-all
   - DELETE /me（soft delete）
   - 估時：2 小時

3. **Phase 6 — SNS 綁定管理**：
   - `GET /me/sns`、`{provider}/bind-url`、`bind`、`unbind`
   - 需注意：解綁唯一登入方式時擋住（需先設密碼）
   - 估時：1 小時

4. **分支策略建議**：
   - 今天的 `feature/member-auth-api` 工作量已多，累計 6 commit，Phase 4 新開 `feature/member-oauth`，Phase 5 剩下的用 `feature/member-me-advanced`，更好 review
   - 或者整條 `feature/member-auth-api` 繼續疊到全部做完，最後一次 merge 回 develop —— 但 PR diff 會很大

5. **Merge 策略**：
   - 目前 `feature/member-auth-api` 已經可以 merge —— 10 支 endpoint 都是完整功能且有測試
   - 建議：下次開工前先 merge 回 develop 再開新分支，保持 feature branch 小

---

## 今日踩坑 / 小經驗

1. **curl + bash + `$TOKEN` 含 `|`**：Sanctum token 格式是 `{id}|{string}`，在 shell `source` 檔案內容時 `|` 會被解讀成 pipe。解法：不要把 token 存檔再 source，直接 inline 在同一次 bash 呼叫裡用。
2. **Filament Resource 加了但後台 sidebar 沒顯示**：04/15 跑過 `filament:optimize`，會把當下的 Resource 清單寫進 `bootstrap/cache/filament/panels/admin.php`。之後新增 Resource 必須 `filament:optimize-clear`。
3. **Phase 5 update phone 踩 unique 約束**：因為 ignore-self 沒寫，連自己現有 phone re-save 都會 422。參考 `MemberUpdateRequest`（admin 版）已有的 `Rule::unique('members','phone')->ignore($memberId)` pattern。
