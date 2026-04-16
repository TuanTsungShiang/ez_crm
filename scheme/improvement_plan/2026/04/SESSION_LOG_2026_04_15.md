# ez_crm 工作日誌 — 2026-04-15

> 分支：`feature/filament-admin`（從 develop 開）
> 協作：Kevin + Claude Code

---

## 今日完成項目

### 1. Member CRUD 完成（Show / Update / Delete）

#### Show API — `GET /api/v1/members/{uuid}`

- 新增 `MemberDetailResource`（獨立於列表用的 `MemberResource`）
- 回傳完整資料：基本資料 + profile + sns + tags + verified_at + timestamps
- Route Model Binding 用 `{member:uuid}`，Laravel 自動用 uuid 查詢
- 軟刪除的會員自動回 404（不需額外處理）

**為什麼獨立 Resource？**
- 列表只需要摘要（name, email, status, group）
- 詳情需要完整資料（profile, sns, verified_at, updated_at）
- 兩個 Resource 各自發展，互不影響

#### Update API — `PUT /api/v1/members/{uuid}`

- `MemberUpdateRequest`：所有欄位用 `sometimes`，只更新有傳的欄位
- `MemberUpdateService`：partial update + profile auto-create + tag sync
- `Rule::unique()->ignore($member->id)`：email/phone unique 排除自己

**KYC 安全策略（重要設計決策）：**
```
email 變動 → 自動清空 email_verified_at
phone 變動 → 自動清空 phone_verified_at
email 未變動（傳同值） → verified_at 保留不動
```
用 Eloquent 的 `isDirty('email')` 判斷值是否真的改變。
理由：KYC 合規 + 防止 account takeover，對齊 Stripe / Auth0 / Cognito。

**tag_ids sync 語意：**
```
tag_ids: [2, 4]    → 完整覆蓋（移除舊的，保留重疊，新增沒有的）
tag_ids: []         → 清空所有 tag
tag_ids 不傳        → 完全不動
```

**Partial Update 語意（跟 Salesforce / HubSpot 一致）：**
```
不傳欄位 → 不動
傳 null  → 清空
```

#### Delete API — `DELETE /api/v1/members/{uuid}`

- 走 soft delete（`deleted_at` 欄位）
- 回傳 `200 + { uuid, deleted_at }` 不用 204
- 被刪除的會員：Search 不出現、Show 回 404、重複 Delete 回 404

**為什麼不用 204？**
- 我們的 API 統一格式要求每個回應都有 `{ success, code, data }`
- 204 不允許有 body，會破壞格式一致性
- 前端不用針對 DELETE 寫特殊 parser

### 2. Swagger 文件補齊

三支 API 都加了 `@OA` 註解（Show / Update / Delete），重新生成 `api-docs.json`。

### 3. 測試資料庫分離（重要基礎建設）

**問題：** 每次跑 `php artisan test`，`RefreshDatabase` 會清掉開發用的資料和 token。

**解法：**
- 建立獨立測試資料庫 `ez_crm_testing`
- 修改 `phpunit.xml` 讓測試指向 `ez_crm_testing`
- 開發用的 `ez_crm` 資料庫永遠不被測試污染

```xml
<!-- phpunit.xml -->
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="ez_crm_testing"/>
```

**效果：** token 產一次就可以一直用，不會被測試清掉。

### 4. Release v1.0.0

依照 Gitflow 規範完成第一個正式版本：

```
develop → release/1.0.0 → merge to main（打 tag v1.0.0）→ 同步回 develop → 刪除 release branch
```

**釋出內容：**
- 完整 Member CRUD（5 支 API）
- 統一 API Response Code 系統（S/V/A/N/I）
- 63 tests / 204 assertions
- Swagger 文件
- CHANGELOG.md

### 5. API 設計討論與決策

#### CRM API vs Register API

確認 `/api/v1/` 定位為 CRM 後台 API（auth:sanctum）。前台 Register / Login 未來獨立設計。

#### Partial Update 業界做法

| 做法 | 代表 |
|---|---|
| PUT 完整覆蓋 + PATCH 部分更新 | Stripe, GitHub |
| PUT 也做 partial update | Salesforce, HubSpot, Shopify |

我們採用第二種（CRM 業界標準）。

#### sync vs attach

| 方式 | 效果 |
|---|---|
| **sync**（我們用的） | 完整覆蓋：前端送什麼，最終就是什麼 |
| attach | 單純新增：需要配合 detach 使用，前端更複雜 |

#### 時間格式與跨國

- 儲存 / 傳輸用 UTC（`+00:00`）
- 顯示由前端根據使用者 timezone 轉換
- 已知潛在問題：Search 的 `whereDate()` 在跨國時會受 server timezone 影響，未來再處理

### 6. Token 安全事件與 SOP

**事件：** 修改 `.github/workflows/pr-review.yml` 需要 `workflow` scope，臨時產 PAT 貼到 Claude 對話中。

**處理：**
- 用 PAT 完成 push 後，立即改回乾淨 remote URL
- GitHub 撤銷所有 token
- 建立完整 token security SOP v2.0

**教訓：**
- AI 可以給指引，但**機密操作由人執行**
- Token 只進兩個地方：GitHub 網頁（產生）和 Git 驗證視窗（使用）

**正確的 PAT 使用方式：**
1. `git config --global credential.helper manager-core`（Windows 只需做一次）
2. 產 PAT 後，下次 push 時 Git 彈窗 → 貼入 token → 系統記住
3. Token 存在 Windows Credential Manager（加密儲存）
4. 要換 token：`控制台 → 認證管理員 → Windows 認證 → git:https://github.com → 刪除`

### 7. Filament 後台安裝

**安裝步驟：**
```bash
composer require filament/filament:"^3.0"
php artisan filament:install --panels    # Panel ID 輸入 admin
```

**User Model 加 FilamentUser 介面：**
```php
use Filament\Models\Contracts\FilamentUser;

class User extends Authenticatable implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
```

**建立管理員帳號：**
```bash
php artisan tinker
> User::create(['name'=>'Admin', 'email'=>'admin@ezcrm.local', 'password'=>bcrypt('admin123')]);
```

**生成 Member Resource：**
```bash
php artisan make:filament-resource Member --generate
```

然後客製化 MemberResource.php：
- 表單分 Section（基本資料 / 分類與狀態 / 個人資料）
- Status 用 badge（正常=綠、停用=紅、待驗=黃）
- Group 用 relationship select（可搜尋）
- Tags 用 multiple select
- Profile 用 relationship form（自動 partial update）
- 表格加 filter（狀態、群組、已刪除）
- 支援 soft delete（顯示 / 復原已刪除會員）

**效能優化：**
```bash
php artisan optimize          # config + route cache
php artisan filament:optimize # component + icon cache
php artisan view:cache        # blade template cache
php artisan icons:cache       # heroicon SVG cache
```

**Server 選擇：**

| Server | 速度 | 原因 |
|---|---|---|
| `php artisan serve` | 慢（2.5s） | 單執行緒，request 排隊 |
| **XAMPP Apache** | 快（1s） | 多執行緒，並行處理 |

Filament + Livewire 一頁會發多個 request，Apache 多執行緒可以同時回應。

**開發用 URL：**
```
後台：http://localhost/ez_crm/public/admin
API： http://localhost/ez_crm/public/api/v1/members
```

---

## 測試結果

```
Tests:    63 passed (204 assertions)
Duration: ~5s
```

| 測試檔 | 數量 |
|---|---|
| MemberSearchTest | 14 |
| MemberCreateTest | 12 |
| MemberShowTest | 5 |
| MemberUpdateTest | 18 |
| MemberDeleteTest | 7 |
| MemberSearchServiceTest (Unit) | 3 |
| ExampleTest (Unit + Feature) | 2 |
| **合計** | **63** |

---

## 目前系統全貌

### API 清單（RESTful CRUD 完整）

| Method | Endpoint | 說明 |
|---|---|---|
| GET | `/api/v1/members` | 搜尋 / 列表 |
| POST | `/api/v1/members` | 建立 |
| GET | `/api/v1/members/{uuid}` | 查看詳情 |
| PUT | `/api/v1/members/{uuid}` | 部分更新 |
| DELETE | `/api/v1/members/{uuid}` | 軟刪除 |

### 後台介面（Filament）

| 頁面 | URL |
|---|---|
| Dashboard | `/admin` |
| 會員列表 | `/admin/members` |
| 新增會員 | `/admin/members/create` |
| 編輯會員 | `/admin/members/{id}/edit` |

### 管理員帳號

```
Email:    admin@ezcrm.local
Password: admin123
```

---

## 下次接續

1. Commit + Push Filament 相關程式碼
2. 加 Group / Tag 管理介面（Filament Resource）
3. 可能做 Dashboard（統計卡片：會員數、今日新增、標籤分佈）
