# SCIM 2.0 整合計畫 — ez_crm Phase 9 / v2.0

> 版本：v0.1 (草案)
> 建立日期：2026-04-21
> 狀態：規劃階段（尚未實作)
> 前情：Solventum EWS 2.0 專案 POC(2026-04-13)已驗證 SCIM 2.0 協議可行

---

## 🎯 定位

**SCIM 是給後台 operator / employee 用的,不是前台 member。**

| 面向 | `members`(前台會員) | `users`(後台 operator)|
|---|---|---|
| 身份來源 | 自主註冊 / OAuth | 企業 IdP(Okta / Azure AD / SailPoint)|
| 認證 | Sanctum `member` guard | Sanctum `web` / `sanctum` guard + **SCIM 供應** |
| 新增方式 | `/api/v1/auth/register` | **`POST /scim/v2/Users`** |
| 誰是使用者 | 企業產品的消費者 | 企業內部員工 / 客服 / 管理員 |
| 本計畫範圍 | ❌ 不碰 | ✅ 本計畫焦點 |

---

## 📐 業務情境(為什麼要做)

```
[HR System / IdP]
       │ 員工入職
       ▼
[SailPoint / Okta / Azure AD]
       │ SCIM 2.0 provisioning
       ▼
[ez_crm /scim/v2/Users endpoint]
       │ 建/改/停/刪 user + role
       ▼
[users table + Filament admin 權限自動就緒]
```

**B2B 銷售關鍵訊息**:
> 「我們支援 SCIM 2.0,你的員工在 Okta 新增 → ez_crm 自動開通帳號;離職 → 自動停用。零手動操作、零離職帳號殘留。」

這是**企業 tier 差異化賣點**,也是台灣多數 CRM 還沒做的。

---

## 🏗️ 架構決策

### 1. 不裝第三方 SCIM 套件,自己寫

Laravel 生態中的 SCIM 套件(`rackbeat/laravel-scim-server` 等)大多:
- 已 2-3 年沒更新
- 抽象層太重,難客製 PATCH operations
- 不支援 SailPoint 的特殊行為

既然 POC 已跑通,把 `scim.php` 的核心邏輯**用 Laravel 方式重寫**最划算。

### 2. `scim/v2` 路由群組獨立於 `api/v1`

原因:
- Content-Type 不同:SCIM 用 `application/scim+json`,不是 `application/json`
- 錯誤格式不同:SCIM 有自己的 `ScimException` schema
- 認證機制不同:走 client_credentials,不是使用者 bearer token
- 不該跟我們自己設計的 REST API 混用

### 3. 認證:OAuth 2.0 Client Credentials

SailPoint / Okta 的標準認證方式(不是用「員工」的 token,是用「整合應用」的 token)。

```
SailPoint:     POST /scim/oauth/token  + client_id/secret  → access_token
SailPoint:     GET  /scim/v2/Users     + Bearer access_token → 200 OK
```

POC 已有 `token.php` 參考實作。

### 4. 儲存位置

SCIM 操作**直接打真實 `users` 表**,不建 shadow table。
- `externalId`(SCIM 欄位)需要新增一欄在 `users` 表
- 權限管理用 Spatie Laravel Permission(Role ↔ SCIM Group 對應)

---

## 🗄️ 資料庫調整預告

> 這些欄位要在 migration 加,**今天 users 表可以先預留欄位的話 migration 更乾淨**。

### `users` 表新增

| 欄位 | 型別 | 說明 |
|---|---|---|
| `external_id` | `VARCHAR(255) NULL UNIQUE` | SCIM `externalId`,IdP 端的 ID(例如 SailPoint 的 employee ID)|
| `active` | `BOOLEAN DEFAULT TRUE` | SCIM `active`,停用(而非刪除)用 |
| `scim_provisioned_at` | `TIMESTAMP NULL` | 第一次被 SCIM 建立的時間 |
| `scim_last_sync_at` | `TIMESTAMP NULL` | 最後一次被 SCIM 更新 |

### 新表:`scim_oauth_clients`

儲存 IdP 憑證(每個接入 IdP 一組):

```php
Schema::create('scim_oauth_clients', function (Blueprint $table) {
    $table->id();
    $table->string('name', 100)->comment('例: SailPoint Production');
    $table->string('client_id', 100)->unique();
    $table->string('client_secret_hash');
    $table->boolean('is_active')->default(true);
    $table->timestamp('last_used_at')->nullable();
    $table->timestamps();
});
```

### 新表:`scim_access_tokens`

```php
Schema::create('scim_access_tokens', function (Blueprint $table) {
    $table->id();
    $table->foreignId('scim_oauth_client_id')->constrained();
    $table->string('access_token', 128)->unique();
    $table->timestamp('expires_at');
    $table->timestamp('created_at')->useCurrent();
});
```

> ⚠️ 不跟 Sanctum 的 `personal_access_tokens` 混,因為:
> - SCIM token 是「application-to-application」,不是 user-scoped
> - Token 可能同時活百把張(不同 IdP)

---

## 📁 程式碼結構

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Scim/
│   │       ├── OAuthController.php       ← token endpoint
│   │       ├── UserController.php        ← /Users CRUD
│   │       ├── GroupController.php       ← /Groups
│   │       └── ServiceProviderConfigController.php
│   ├── Middleware/
│   │   └── ScimBearerAuth.php            ← 驗 Bearer token
│   └── Resources/
│       └── Scim/
│           ├── ScimUserResource.php      ← formatUser() Laravel 版
│           └── ScimGroupResource.php
├── Services/
│   └── Scim/
│       ├── ScimPatchApplier.php          ← 處理 PATCH Operations(核心難點)
│       ├── ScimFilterParser.php          ← 解析 SCIM filter 語法
│       └── ScimTokenService.php
├── Exceptions/
│   └── ScimException.php                 ← 統一 SCIM 錯誤格式
└── Models/
    ├── ScimOauthClient.php
    └── ScimAccessToken.php
```

---

## 🛣️ 路由設計

```php
// routes/scim.php(新檔,在 RouteServiceProvider 註冊)

use App\Http\Controllers\Scim\OAuthController;
use App\Http\Controllers\Scim\UserController;
use App\Http\Controllers\Scim\GroupController;
use App\Http\Controllers\Scim\ServiceProviderConfigController;

// Token endpoint(不需認證)
Route::post('/scim/oauth/token', [OAuthController::class, 'token']);

// SCIM resources
Route::prefix('scim/v2')->group(function () {

    // 服務配置(SailPoint 會先打這個探測能力,不需 token)
    Route::get('ServiceProviderConfigs', [ServiceProviderConfigController::class, 'show']);
    Route::get('Schemas', [ServiceProviderConfigController::class, 'schemas']);
    Route::get('ResourceTypes', [ServiceProviderConfigController::class, 'resourceTypes']);

    // 以下都需要 SCIM Bearer token
    Route::middleware('scim.auth')->group(function () {
        // Users
        Route::get('Users', [UserController::class, 'index']);
        Route::post('Users', [UserController::class, 'store']);
        Route::get('Users/{id}', [UserController::class, 'show']);
        Route::put('Users/{id}', [UserController::class, 'update']);
        Route::patch('Users/{id}', [UserController::class, 'patch']);
        Route::delete('Users/{id}', [UserController::class, 'destroy']);

        // Groups
        Route::get('Groups', [GroupController::class, 'index']);
        Route::get('Groups/{id}', [GroupController::class, 'show']);
        Route::patch('Groups/{id}', [GroupController::class, 'patch']);

        // Bulk operations(可選,Phase 9.5)
        Route::post('Bulk', [BulkController::class, 'execute']);
    });
});
```

---

## 🔥 核心難點:PATCH Operations

這是 SCIM 跟一般 REST 最大的差異。SCIM PATCH **不是 JSON Merge Patch**,是自己的 Operations 語法:

```json
{
  "schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
  "Operations": [
    {
      "op": "replace",
      "path": "active",
      "value": false
    },
    {
      "op": "add",
      "path": "emails[type eq \"work\"].value",
      "value": "new@example.com"
    },
    {
      "op": "remove",
      "path": "members[value eq \"abc123\"]"
    }
  ]
}
```

**`ScimPatchApplier` 服務要處理**:
- `op` 三種:`add` / `replace` / `remove`
- `path` 的 filter 語法(`emails[type eq "work"].value`)
- Nested attributes(`name.givenName`、`addresses[0].country`)
- 無 `path` 的操作(整個 resource 替換)

建議用測試驅動開發,先把 RFC 7644 section 3.5.2 的每個範例寫成測試,再實作。

---

## 🔐 `ScimBearerAuth` Middleware 關鍵邏輯

```php
public function handle(Request $request, Closure $next)
{
    $authHeader = $request->header('Authorization')
        ?? $request->server('REDIRECT_HTTP_AUTHORIZATION') // Apache
        ?? '';

    if (! preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        return $this->scimError('invalidSyntax', 401);
    }

    $token = ScimAccessToken::where('access_token', $m[1])
        ->where('expires_at', '>', now())
        ->first();

    if (! $token) {
        return $this->scimError('unauthorized', 401);
    }

    $request->attributes->set('scim_client', $token->client);
    return $next($request);
}
```

**Apache Authorization header 被吃掉的坑**(POC 已踩過):
- `.htaccess` 加 `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` 或
- PHP-FPM 環境改用 `REDIRECT_HTTP_AUTHORIZATION`
- **建議 production 走 nginx** 免得踩這坑

---

## 🧪 測試策略

| 測試層級 | 重點 |
|---|---|
| Unit | `ScimPatchApplier::apply()` 的每個 RFC 範例 |
| Unit | `ScimFilterParser::parse()` 的 `eq/ne/co/sw/ew/pr/gt/ge/lt/le` 各情境 |
| Feature | Users CRUD 全流程(建→改 PATCH→停 active=false→再啟用)|
| Feature | Groups membership sync(PATCH add/remove members)|
| Feature | Token endpoint(正確/錯誤 client_credentials)|
| Integration | 實際用 `curl` 跑過 SailPoint 標準測試腳本(POC 已有 `test.php` 可參考)|

目標:**覆蓋 RFC 7644 section 8「典型操作情境」** 每一條。

---

## 🗺️ 實作階段拆分

| Phase | 工作 | 估時 |
|---|---|---|
| **9.0** 基礎設施 | migrations + Models + ScimException + Middleware + token endpoint | 2 天 |
| **9.1** Users read | GET /Users、GET /Users/{id}、ServiceProviderConfigs + Schemas | 2 天 |
| **9.2** Users write | POST / PUT + **PatchApplier**(難點)+ DELETE(轉 soft delete)| 3 天 |
| **9.3** Groups | GET + PATCH(membership sync)+ Spatie Role 橋接 | 2 天 |
| **9.4** Filter / Pagination | `filter=` / `startIndex` / `count` / `sortBy` 全支援 | 1 天 |
| **9.5**(可選)Bulk | POST /Bulk + 完整錯誤隔離 | 1 天 |
| **9.6** 合規驗證 | 跑 SailPoint / Okta 官方驗證工具確認 100% conformant | 1 天 |
| **合計** | | **12 天 / ~2.5 週** |

---

## ⚠️ 已知踩坑清單(從 Solventum POC)

1. **Apache 吃掉 Authorization header** → `.htaccess` 修正或上 nginx
2. **Content-Type 必須 `application/scim+json`** → response 一定要帶,RFC 強制
3. **`userName` 是必填且 unique** → email 當 userName 最單純
4. **`id` 不可變更** → 永遠用 UUID,別用自增
5. **SailPoint 特殊行為**:
   - 某些欄位會送 `null`(要視為 remove)
   - 會先打 ServiceProviderConfigs 確認能力
   - PATCH 的 `value` 有時是陣列有時是字串,要判斷
6. **停用 vs 刪除**:
   - SCIM `active: false` → 我們軟停用(status flag)
   - SCIM `DELETE /Users/{id}` → 一樣轉軟停用,**不要真的 `$user->delete()`**(稽核需要保留)

---

## 🎬 啟動條件(何時可以開始 Phase 9)

- [ ] 會員 Auth 後端 Phase 4-6 全部完成並 merge 到 `main`
- [ ] 前端 `ez_crm_client` MVP(含 OAuth + /me)上線
- [ ] Release v1.x 已發過至少一次(證明現有流程穩)
- [ ] 決定 Spatie Laravel Permission 裝哪版 / 或自建 role 機制
- [ ] 有明確企業客戶 / 履歷 demo 需求驅動

---

## 📚 RFC 參考

| RFC | 說明 |
|---|---|
| [RFC 7643](https://datatracker.ietf.org/doc/html/rfc7643) | SCIM Core Schema(User / Group 資料結構)|
| [RFC 7644](https://datatracker.ietf.org/doc/html/rfc7644) | SCIM Protocol(CRUD / Filter / Patch 語義)|
| [RFC 7642](https://datatracker.ietf.org/doc/html/rfc7642) | SCIM 定義與使用情境(賣點說明用)|

---

## 🧭 未來延伸

- **SCIM Events(Webhook)** — ez_crm 端內部變動推回 IdP(員工在 Filament 改資料,回推 Okta)
- **Multi-tenant SCIM** — 不同企業客戶各有一組 scim_oauth_client + 資料隔離
- **Azure AD 特化** — MS 有些 non-standard 行為(例如 softDelete),做 Phase 9.7 專門處理
- **SCIM for Members?** — 理論上不推薦(SCIM 是 enterprise 協議),但如果 B2B2C 場景有需求,可針對 members 開第二組 endpoint

---

## 📌 結論

- **做不做:做**(差異化賣點 + 履歷硬實力)
- **何時做:至少 2-3 週後**(Phase 4-6 + 前端 MVP 後)
- **多久做完:2.5 週 full implementation + 1 週合規驗證**
- **今天的行動項**:
  - ☑ 本計畫寫好
  - ☐ 下次 `users` 表有 migration 時,預留 `external_id` 欄位
  - ☐ 決定要不要裝 Spatie Laravel Permission(早點裝早適應,可以平常就開始用)
