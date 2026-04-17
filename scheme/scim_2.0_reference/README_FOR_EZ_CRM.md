# SCIM 2.0 — ez_crm 整合參考資料

> 來源：Solventum EWS 2.0 專案 POC（2026-04-13 驗證通過）

---

## 這是什麼

SCIM 2.0（System for Cross-domain Identity Management）是企業身分管理的標準協議。
支援 SCIM 2.0 代表 ez_crm 可以直接對接：

- **SailPoint**（Solventum / 3M 在用）
- **Okta**（市佔最高的身分管理平台）
- **Azure AD**（微軟企業方案）
- **OneLogin**、**Ping Identity** 等等

**對企業客戶的賣點：** 員工入職/離職/調部門，帳號自動同步，不需要手動管理。

---

## 檔案清單

| 檔案 | 說明 |
|---|---|
| `SailPoint_SCIM_白話說明.md` | SCIM 2.0 完整白話解說（適合給非技術人員看） |
| `config.php` | 設定檔 + SQLite 初始化（含預設群組） |
| `token.php` | OAuth 2.0 Token 端點 |
| `scim.php` | SCIM 2.0 API 主入口（Users + Groups + ServiceProviderConfigs） |
| `test.php` | 自動測試腳本（模擬完整身分管理流程） |
| `.htaccess` | URL Rewrite + Authorization header 修正 |

---

## 整合到 ez_crm（Laravel）的方向

目前的 POC 是純 PHP 寫的，整合到 Laravel 時建議：

### 路由
```php
// routes/api.php
Route::prefix('scim/v2')->group(function () {
    Route::get('ServiceProviderConfigs', [ScimController::class, 'serviceProviderConfigs']);
    Route::apiResource('Users', ScimUserController::class);
    Route::get('Groups', [ScimGroupController::class, 'index']);
    Route::patch('Groups/{id}', [ScimGroupController::class, 'update']);
});

Route::post('scim/token', [ScimAuthController::class, 'token']);
```

### Middleware
```php
// app/Http/Middleware/ScimBearerAuth.php
// 驗證 Bearer Token（從 POC 的 verifyToken 邏輯搬過來）
```

### Model
```php
// 直接用 Laravel 的 User model + Role/Permission（Spatie）
// SCIM 的 Group 對應 Spatie 的 Role
```

### 核心邏輯
```
POC 的 scim.php          → ScimUserController + ScimGroupController
POC 的 token.php         → ScimAuthController
POC 的 config.php        → Laravel .env + migration
POC 的 formatUser()      → ScimUserResource（Laravel API Resource）
```

---

## 已知的坑

1. **Apache Authorization header 被吃掉** — 需要 .htaccess 修正或改用 Nginx
2. **SCIM JSON 格式** — 要用 `application/scim+json` content type
3. **UserId / GroupId 不可變更** — 建議用 UUID 而非自增 ID
4. **PATCH 操作格式** — SCIM 的 PATCH 有自己的 Operations 格式，不是標準 JSON Merge Patch
