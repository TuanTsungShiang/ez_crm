# ez_crm

`ez_crm` 是一個以 Laravel 10 為基礎的早期 CRM / 會員管理專案，現階段重點放在會員資料模型、會員搜尋 API，以及後續 CRM 功能擴充所需的資料結構。

目前專案仍在開發前期與測試階段，核心方向已成形，但功能尚未完整，適合用來持續打底會員中心、標籤管理、分群搜尋與未來的後台管理能力。

## 目前已完成

- 會員核心資料表與 Model
- 會員群組 `member_groups`
- 標籤 `tags` 與 `member_tag` 關聯
- 會員延伸資料 `member_profiles`
- 第三方登入關聯 `member_sns`
- 會員搜尋 API `GET /api/v1/members/search`
- Form Request 驗證
- API Resource / Collection 回傳格式
- Seeder 基礎測試資料
- Feature Test 雛形

## 技術棧

- PHP 8.1+
- Laravel 10
- Laravel Sanctum
- PHPUnit 10
- Vite

## 本機安裝

先安裝後端依賴：

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

建立資料表並匯入測試資料：

```bash
php artisan migrate
php artisan db:seed
```

如果要重建資料庫：

```bash
php artisan migrate:fresh --seed
```

## 啟動專案

```bash
php artisan serve
```

預設開發網址通常為：

```text
http://127.0.0.1:8000
```

## 測試

執行測試前請先確認 `composer install` 已完成，且資料庫設定可供測試使用。

```bash
php artisan test
```

## 目前 API

### `GET /api/v1/members/search`

用途：

- 搜尋會員資料
- 支援分頁、排序與多種條件篩選

驗證方式：

- 需要通過 `auth:sanctum`

支援的主要查詢參數：

- `keyword`
- `status`
- `group_id`
- `tag_ids[]`
- `gender`
- `has_sns`
- `created_from`
- `created_to`
- `per_page`
- `page`
- `sort_by`
- `sort_dir`

成功回應摘要：

```json
{
  "success": true,
  "data": {
    "items": [],
    "pagination": {
      "total": 0,
      "per_page": 15,
      "current_page": 1,
      "last_page": 1
    }
  }
}
```

驗證失敗回應摘要：

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "per_page": [
      "The per page must not be greater than 100."
    ]
  }
}
```

## 目錄概覽

```text
app/
  Http/
    Controllers/Api/V1/
    Requests/Api/V1/
    Resources/Api/V1/
  Models/
  Services/
database/
  migrations/
  seeders/
routes/
scheme/
tests/
```

## 相關文件

- `scheme/schema/member.md`
- `scheme/api/plan_member_search_api.md`
- `scheme/improvement_plan/2026/04/IMPROVEMENT_PLAN.md`
- `scheme/improvement_plan/2026/04/PHASE_1_DETAILED_IMPLEMENTATION_PROPOSAL.md`

## 已知限制

- 專案仍在早期開發階段
- 目前主要對外功能仍以會員搜尋 API 為主
- 會員 CRUD、標籤管理、群組管理與更完整 CRM 流程尚未完成
- 權限模型目前僅先做到已登入可存取，尚未進入角色權限控管
- 搜尋效能優化與索引策略仍在後續規劃中
