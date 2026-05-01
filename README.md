# ez_crm

[![PHPUnit](https://github.com/TuanTsungShiang/ez_crm/actions/workflows/phpunit.yml/badge.svg?branch=develop)](https://github.com/TuanTsungShiang/ez_crm/actions/workflows/phpunit.yml)

> **版本：v1.0.0**  
> 詳細變更紀錄見 [CHANGELOG.md](CHANGELOG.md)

`ez_crm` 是一個以 Laravel 10 為基礎的 CRM / 會員管理系統，採 API-first 架構，同時提供 Filament 後台管理介面。系統分為**前台會員端**（/api/v1/auth、/api/v1/me）與**後台管理端**（/api/v1/admin）兩套 API，並以 Spatie RBAC 管控權限。

## 已完成功能

### 會員認證（前台）

- Email / Phone OTP 驗證流程
- 帳號密碼登入、登出
- Google / Facebook OAuth 第三方登入（綁定 / 解綁）
- 忘記密碼 / 重設密碼
- OAuth-only 帳號首次設密碼流程

### 會員自助管理（/me）

- 查看 / 更新個人資料
- 修改密碼 / 首次設密碼

### 後台管理 API（admin，需 Sanctum + RBAC）

- 會員完整 CRUD（Search / Create / Show / Update / Delete）
  - 路由繫結採 UUID（不暴露 auto-increment ID）
  - FormRequest 驗證、軟刪除
- 會員群組（Groups）CRUD
- 標籤（Tags）CRUD

### 點數系統（開發中，Phase 2.1）

- `PointTransaction` model + migration
- `PointService::adjust` — 單一異動入口，保證：
  - `lockForUpdate` 防止併發過度扣點
  - `idempotency_key` 冪等防重（DB UNIQUE + 併發 TOCTOU 保護）
  - 點數異動 + balance cache 同一 transaction 原子提交
  - `PointAdjusted` event（replay-safe，actor 從 tx row 讀取）
- 226 個測試通過（Unit + Feature）

### 基礎設施

- **Webhook**：訂閱管理、事件派送、交付記錄
- **SMS**：SmsManager + Log / Null driver（可擴充真實 provider）
- **RBAC**：Spatie Laravel Permission，角色 super-admin / admin / operator / viewer
- **Filament 後台**：會員、群組、標籤、Webhook、通知管理介面；RBAC 頁面控管
- **Static Analysis**：Larastan level 5，PHPStan baseline 10 個 ignore
- **CI**：GitHub Actions PHPUnit workflow

## 技術棧

- PHP 8.2 / Laravel 10
- Laravel Sanctum
- Spatie Laravel Permission
- Filament v3
- PHPUnit 10
- Larastan (PHPStan level 5)
- Vite

## 本機安裝

```bash
composer install
cp .env.example .env
php artisan key:generate
```

若需要前端資源：

```bash
npm install
npm run dev
```

## 資料庫初始化

```bash
php artisan migrate
php artisan db:seed
```

重建資料庫：

```bash
php artisan migrate:fresh --seed
```

## 啟動專案

```bash
php artisan serve
```

## 測試

```bash
php artisan test
```

## API 概覽

### 前台會員認證（無需登入）

| Method | Path | 說明 |
|--------|------|------|
| GET | `/api/v1/auth/register/schema` | 取得註冊欄位 schema |
| POST | `/api/v1/auth/register` | 會員註冊 |
| POST | `/api/v1/auth/verify/email/send` | 發送 Email OTP |
| POST | `/api/v1/auth/verify/email` | 驗證 Email OTP |
| POST | `/api/v1/auth/verify/phone/send` | 發送 Phone OTP |
| POST | `/api/v1/auth/verify/phone` | 驗證 Phone OTP |
| POST | `/api/v1/auth/login` | 登入 |
| POST | `/api/v1/auth/password/forgot` | 忘記密碼 |
| POST | `/api/v1/auth/password/reset` | 重設密碼 |
| GET | `/api/v1/auth/oauth/{provider}/redirect` | OAuth 重導 |
| GET | `/api/v1/auth/oauth/{provider}/callback` | OAuth 回呼 |

### 前台會員自助（需 `auth:member`）

| Method | Path | 說明 |
|--------|------|------|
| GET | `/api/v1/me` | 取得個人資料 |
| PUT | `/api/v1/me` | 更新個人資料 |
| PUT | `/api/v1/me/password` | 修改密碼 |
| POST | `/api/v1/me/password/set` | 首次設密碼（OAuth-only 帳號）|
| POST | `/api/v1/me/logout` | 登出 |

### 後台管理（需 `auth:sanctum` + RBAC）

| Method | Path | 說明 |
|--------|------|------|
| GET | `/api/v1/members` | 搜尋會員（分頁 + 多條件篩選）|
| POST | `/api/v1/members` | 建立會員 |
| GET | `/api/v1/members/{uuid}` | 取得單一會員 |
| PUT | `/api/v1/members/{uuid}` | 更新會員 |
| DELETE | `/api/v1/members/{uuid}` | 軟刪除會員 |
| * | `/api/v1/groups` | 群組 CRUD |
| * | `/api/v1/tags` | 標籤 CRUD |

## 目錄概覽

```text
app/
  Events/Webhooks/       — Webhook 事件（含 PointAdjusted）
  Exceptions/Points/     — 業務例外
  Filament/              — 後台管理介面
  Http/
    Controllers/Api/V1/  — Auth / Me / Member / Group / Tag
    Requests/Api/V1/
    Resources/Api/V1/
  Models/
  Services/
    Auth/                — 登入、OTP、密碼
    OAuth/               — Google / Facebook OAuth
    Points/              — PointService（點數原子異動）
    Sms/                 — SmsManager + drivers
database/
  migrations/
  seeders/
routes/
  api.php
scheme/                  — 設計文件、roadmap、ADR
tests/
  Feature/
  Unit/
```

## 設計文件

- [`scheme/improvement_plan/2026/04/SENIOR_ROADMAP.md`](scheme/improvement_plan/2026/04/SENIOR_ROADMAP.md) — 整體開發路線圖
- [`scheme/improvement_plan/2026/04/POINTS_INTEGRATION_PLAN.md`](scheme/improvement_plan/2026/04/POINTS_INTEGRATION_PLAN.md) — 點數系統設計
- [`scheme/improvement_plan/2026/04/ENGINEERING_INFRASTRUCTURE_ROADMAP.md`](scheme/improvement_plan/2026/04/ENGINEERING_INFRASTRUCTURE_ROADMAP.md) — 工程基礎設施計畫
