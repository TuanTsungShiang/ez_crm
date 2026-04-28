# 小尾巴任務清單

> 建立日期：2026-04-28
> 執行者：Claude Code（VS Code plugin）
> 估時：三項合計約 4–5 小時
> 優先序：任一項皆可獨立進行，互不相依

---

## Task 1 — T5.1：OAuth-only 用戶改密碼 UX

> 估時：後端 1–2 h + 前端 1–2 h
> 原始 backlog：`work_log/20260428/backlog_t5_1_oauth_password_ux.md`

### 問題

用 Google / GitHub / LINE / Discord 註冊的用戶進 `/me/password`，
後端塞的是 `Str::random(60)` placeholder 密碼，
輸入任何 `current_password` 都回 `A009 目前密碼錯誤`，永遠卡死。

根因：
- `app/Services/OAuth/OAuthService.php:86` → `'password' => Str::random(60)`
- `app/Http/Controllers/Api/V1/Me/MeController.php:125` → `Hash::check($request->current_password, $member->password)`

### 實作方案（方案 B）

#### 後端

1. **Migration**：`members` 表加 `password_set_at` (timestamp, nullable, after `password`)

2. **寫入時機**（以下三處加上 `$member->update(['password_set_at' => now()])`）：
   - `app/Http/Controllers/Api/V1/Auth/RegisterController.php` — 註冊成功後
   - `app/Http/Controllers/Api/V1/Auth/ResetPasswordController.php` — reset 成功後
   - `app/Http/Controllers/Api/V1/Me/MeController.php` — `updatePassword()` 成功後

3. **MemberResource**：`app/Http/Resources/Api/V1/MemberResource.php`
   加上計算欄位：
   ```php
   'has_local_password' => $this->password_set_at !== null,
   ```

4. **新 endpoint** `POST /me/password/set`（僅供 `has_local_password = false` 的用戶使用）：
   - Request 只需要 `new_password` + `new_password_confirmation`
   - 驗證通過後 `Hash::make($request->new_password)` 寫入 + 更新 `password_set_at`
   - 成功後照 T5 邏輯：回 200 + 前端跳 `/login`
   - 若 `has_local_password = true` 呼叫此 endpoint → 回 `403`

5. **Feature Tests**：
   - OAuth-only 用戶呼叫 `POST /me/password` → 422 A009
   - OAuth-only 用戶呼叫 `POST /me/password/set` → 200 + `password_set_at` 寫入
   - 一般用戶呼叫 `POST /me/password/set` → 403
   - 設定後 `GET /me` 回傳 `has_local_password: true`

#### 前端（`ez_crm_client`）

檔案：`src/views/MePasswordView.vue`

進入頁面時判斷 `auth.member.has_local_password`：

- `false` → 隱藏「目前密碼」欄位，標題改「設定密碼」，POST 到 `/me/password/set`
- `true` → 維持現狀（目前密碼 + 新密碼 + 確認），POST 到 `/me/password`

---

## Task 2 — PR Review Workflow 清理

> 估時：15–30 min
> 檔案：`.github/workflows/pr-review.yml`

### 問題

每次開 PR，`pr-review.yml` 執行時找不到 `OPENAI_API_KEY` secret，
直接 `core.setFailed` → CI 紅燈，視覺噪音。

### 實作（二選一，建議選 A）

#### 方案 A — 設定 Secret（推薦）

1. 前往 GitHub repo → **Settings → Secrets and variables → Actions**
2. 新增 Repository secret：
   - Name：`OPENAI_API_KEY`
   - Value：填入有效的 OpenAI API key
3. （選做）新增 Variable：
   - Name：`OPENAI_MODEL`
   - Value：`gpt-4.1-mini`（或 `gpt-4o-mini` 省錢版）
4. 手動觸發一次 PR 驗證綠燈

> 保留此功能理由：AI PR Review 是面試加分展示點，成本低（每次 PR diff < $0.01）

#### 方案 B — 停用 Workflow

在 `pr-review.yml` job 層加上：
```yaml
jobs:
  ai-review:
    if: false   # 暫停，保留設定供日後啟用
```

---

## Task 3 — Filament Navigation Group 優化

> 估時：1–2 h
> 影響檔案：全在 `app/Filament/Resources/`

### 問題

目前左側 nav 結構：
```
系統管理         ← RoleResource / UserResource
Webhooks         ← WebhookSubscription / WebhookEvent / WebhookDelivery
（無 group）     ← MemberResource / MemberGroupResource / TagResource
```

問題一：`customer_support` 角色沒有 webhook 權限，但 Webhooks group 標題會殘留（空 group）。
問題二：Members / Groups / Tags 沒有 group，nav 視覺凌亂。

### 實作

#### 1. Members / MemberGroups / Tags 加上 navigation group

在以下三個 Resource 加上：
```php
protected static ?string $navigationGroup = '會員管理';
protected static ?int $navigationSort = 1; // 依序調整
```
- `app/Filament/Resources/MemberResource.php`
- `app/Filament/Resources/MemberGroupResource.php`
- `app/Filament/Resources/TagResource.php`

#### 2. Webhooks group 對無權限角色隱藏

在 `WebhookSubscriptionResource`、`WebhookEventResource`、`WebhookDeliveryResource` 各加：
```php
public static function canViewAny(): bool
{
    return auth()->user()?->can('webhook_subscription.view_any') ?? false;
    // 各 Resource 換成對應 permission name
}
```

Filament 會自動隱藏所有 Resource 都 `canViewAny() = false` 的 group 標題。

#### 3. NavigationSort 建議排序

| Group | Sort | Resource |
|---|---|---|
| 會員管理 | 1 | Members / Groups / Tags |
| Webhooks | 2 | Subscription / Event / Delivery |
| 系統管理 | 3 | Users / Roles |

#### 4. （選做）NotificationDelivery Resource

SMS Phase 8.0 骨架已有 `NotificationDelivery` model，
可以加一個唯讀 Filament Resource 方便查發送紀錄：

```php
// app/Filament/Resources/NotificationDeliveryResource.php
protected static ?string $navigationGroup = '通知管理';
protected static ?string $navigationLabel = '發送紀錄';
```

只需要 `index` 頁（ListRecords），欄位：`channel`、`recipient`、`status`、`sent_at`、`created_at`。

---

## 驗收條件

| Task | 驗收標準 |
|---|---|
| T5.1 | OAuth-only 帳號進 `/me/password` 看到「設定密碼」UI，設定後可用新密碼登入；`has_local_password` flag 正確；feature tests 全綠 |
| PR Review | 下次開 PR 時 CI 全綠（含 `ai-review` job）；或 `if: false` 後紅燈消失 |
| Filament Nav | `customer_support` 角色登入後台不見 Webhooks group；左側有「會員管理」group；`super_admin` 看到完整 nav |
