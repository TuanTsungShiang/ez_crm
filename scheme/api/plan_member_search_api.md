# Member Search API — 實作計畫

> 版本：v1.0
> 建立日期：2026-03-30
> 說明：會員搜尋 Demo API，提供多條件查詢、分頁回傳。

---

## Endpoint

```
GET /api/v1/members/search
```

---

## Request 參數

| 參數 | 型別 | 必填 | 說明 |
|---|---|---|---|
| keyword | string | 否 | 模糊搜尋：name / nickname / email / phone |
| status | integer | 否 | 會員狀態：1=正常 0=停用 2=待驗證 |
| group_id | integer | 否 | 會員分群 ID |
| tag_ids | array | 否 | 標籤 ID 陣列，AND 條件（同時符合所有標籤）|
| gender | integer | 否 | 性別：1=男 2=女 0=不提供（需 join profiles）|
| has_sns | boolean | 否 | 是否有綁定第三方登入 |
| created_from | date | 否 | 註冊日期起（YYYY-MM-DD）|
| created_to | date | 否 | 註冊日期迄（YYYY-MM-DD）|
| per_page | integer | 否 | 每頁筆數，預設 15，最大 100 |
| page | integer | 否 | 頁碼，預設 1 |
| sort_by | string | 否 | 排序欄位：created_at / last_login_at / name，預設 created_at |
| sort_dir | string | 否 | 排序方向：asc / desc，預設 desc |

---

## Response 結構

### 成功 200

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "uuid": "550e8400-e29b-41d4-a716-446655440000",
        "name": "王小明",
        "nickname": "Ming",
        "email": "ming@example.com",
        "phone": "0912345678",
        "status": 1,
        "group": {
          "id": 1,
          "name": "一般會員"
        },
        "tags": [
          { "id": 2, "name": "潛力客", "color": "#FF5733" }
        ],
        "has_sns": true,
        "last_login_at": "2026-03-29T12:00:00+08:00",
        "created_at": "2026-01-01T00:00:00+08:00"
      }
    ],
    "pagination": {
      "total": 150,
      "per_page": 15,
      "current_page": 1,
      "last_page": 10
    }
  }
}
```

### 驗證失敗 422

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "per_page": ["The per page must not be greater than 100."]
  }
}
```

---

## 架構規劃

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── V1/
│   │           └── MemberController.php       ← 接收請求，呼叫 Service
│   ├── Requests/
│   │   └── Api/
│   │       └── V1/
│   │           └── MemberSearchRequest.php    ← 驗證 request 參數
│   └── Resources/
│       └── Api/
│           └── V1/
│               ├── MemberResource.php         ← 單筆會員格式
│               └── MemberCollection.php       ← 列表 + pagination 格式
├── Services/
│   └── MemberSearchService.php                ← 搜尋邏輯
└── Models/
    └── Member.php                             ← Eloquent Model

routes/
└── api.php                                    ← 路由定義
```

---

## 各層職責說明

### MemberSearchRequest
- 定義所有 query 參數的驗證規則
- 回傳統一 422 格式

### MemberSearchService
- 接收 validated data，組合 Eloquent query
- 處理 keyword 模糊搜尋（name / nickname / email / phone）
- 處理 tag_ids AND 條件（whereHas 多次或 subquery）
- 處理 has_sns / gender join
- 回傳 LengthAwarePaginator

### MemberResource / MemberCollection
- 統一 API response 欄位格式
- 隱藏敏感欄位（password）
- 格式化日期為 ISO 8601

### MemberController
- 僅負責呼叫 Request 驗證 → Service → Resource 回傳
- 不含業務邏輯

---

## 路由定義

```php
Route::prefix('v1')->group(function () {
    Route::get('members/search', [MemberController::class, 'search']);
});
```

---

## 待建立檔案清單

| 檔案 | 說明 |
|---|---|
| `app/Models/Member.php` | Member Eloquent Model |
| `app/Http/Controllers/Api/V1/MemberController.php` | Controller |
| `app/Http/Requests/Api/V1/MemberSearchRequest.php` | Form Request |
| `app/Http/Resources/Api/V1/MemberResource.php` | 單筆 Resource |
| `app/Http/Resources/Api/V1/MemberCollection.php` | 列表 Collection |
| `app/Services/MemberSearchService.php` | 搜尋服務 |
| `routes/api.php` | 路由更新 |

---

## 實作順序

1. `Member` Model（含 relations：group, tags, sns, profile）
2. `MemberSearchRequest` 驗證規則
3. `MemberSearchService` 查詢邏輯
4. `MemberResource` / `MemberCollection` 回傳格式
5. `MemberController` 串接
6. `routes/api.php` 路由註冊
