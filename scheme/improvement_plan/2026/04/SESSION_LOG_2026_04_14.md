# ez_crm 工作日誌 — 2026-04-14

> 分支：`feature/optimize-member-search` → 已 merge 回 `develop`
> 協作：Kevin + Claude Code

---

## 今日完成項目

### 1. API Response Code 規範制定

- Kevin 提供 Legacy 版 status code（xlsx），Claude 出 Laravel 版提案
- 合併為最終版 [API_RESPONSE_CODE_FINAL.md](API_RESPONSE_CODE_FINAL.md)
- 採用 Kevin 的前綴分類系統（S/V/A/N/C/R/T/D/I）
- 驗證類 HTTP 從 400 調整為 422（對齊 Laravel FormRequest）
- 定義 39 個 code，現階段實作 12 個

### 2. API Response Code 系統實作

| 檔案 | 說明 |
|---|---|
| `app/Enums/ApiCode.php` | 常數類，含 Laravel rule → V code 自動對應 |
| `app/Http/Traits/ApiResponse.php` | `success()` / `created()` / `error()` 統一回傳 |
| `app/Http/Requests/Api/V1/BaseApiRequest.php` | 所有 API FormRequest 的 base，統一 422 格式 + V code 判定 |
| `app/Exceptions/Handler.php` | 401(A001) / 404(N001/N002) 統一攔截 |
| `app/Http/Resources/Api/V1/MemberCollection.php` | Search 回傳加上 `code: S200` |

### 3. Member Create API（CRM 後台用）

| 項目 | 內容 |
|---|---|
| Endpoint | `POST /api/v1/members` |
| 認證 | `auth:sanctum` |
| 驗證 | name 必填，email/phone 至少一個，unique 檢查，group/tag exists 檢查 |
| 邏輯 | 建 member → 建 profile → attach tags（DB transaction） |
| 回傳 | `201` + `S201` + MemberResource |
| Status 預設 | `1`（正常），CRM 管理員建的不走驗證流程 |

涉及檔案：
- `app/Http/Requests/Api/V1/MemberCreateRequest.php`（新增）
- `app/Services/MemberCreateService.php`（新增）
- `app/Http/Controllers/Api/V1/MemberController.php`（加 store）
- `routes/api.php`（加 POST members）
- `tests/Feature/Api/V1/MemberCreateTest.php`（新增，12 個測試）

### 4. Swagger 文件更新

- Search 的 `@OA\Get` 加上 `code` 欄位
- Create 的 `@OA\Post` 完整註解（request body + 201/401/422 response）
- `php artisan l5-swagger:generate` 重新生成

### 5. 路由整理（延續上次）

- `GET /api/v1/members/search` → `GET /api/v1/members`（RESTful 標準化）
- MemberResource 移除所有自增 ID，只對外暴露 uuid

### 6. Auth 錯誤處理修復

- `Authenticate.php`：API 路徑不 redirect 到 login
- `Handler.php`：API 路徑統一回 JSON 401，不回 HTML 錯誤頁

### 7. Feature Branch Merge

- `feature/optimize-member-search` → merge 回 `develop`（--no-ff）
- 已 push 到 remote

---

## 測試結果

```
Tests:    33 passed (112 assertions)
Duration: 4.62s
```

| 測試檔 | 數量 | 說明 |
|---|---|---|
| MemberCreateTest | 12 | 成功建立、驗證失敗（V001~V006）、認證(A001) |
| MemberSearchTest | 14 | 搜尋、篩選、分頁、驗證、認證 |
| MemberSearchServiceTest | 3 | sort fallback unit test |
| ExampleTest (Unit + Feature) | 2 | Laravel 預設 |
| **合計** | **33** | |

---

## 目前 API 清單

| Method | Endpoint | 說明 | Status |
|---|---|---|---|
| GET | `/api/v1/members` | 搜尋會員（分頁、篩選、排序） | ✅ |
| POST | `/api/v1/members` | 建立會員（CRM 後台） | ✅ |
| GET | `/api/v1/members/{uuid}` | 查看單一會員 | 待做 |
| PUT | `/api/v1/members/{uuid}` | 更新會員 | 待做 |
| DELETE | `/api/v1/members/{uuid}` | 刪除會員（軟刪除） | 待做 |

---

## 討論紀錄

### CRM API vs 前台 Register API

確認 `/api/v1/` 定位為 **CRM 後台 API**（auth:sanctum 保護）。前台會員 Register / Login 未來獨立設計，不混在同一組路由。

### Create Member 的 Status 預設值

Schema 預設 `2`（待驗證），但 CRM Create 改為預設 `1`（正常）。管理員手動建立的會員不需要走驗證流程。

### keyword 搜尋 uuid

目前 keyword 只搜 name/nickname/email/phone。後續可加 uuid 精確匹配（用 `orWhere('uuid', $keyword)` 不用 LIKE）。

### 公司定版 Status Code 比較

比較了 Kevin 版（前綴分類 + HTTP Status 對應）與公司 MAX 版（模組前綴 + 場景綁定）。ez_crm 採用 Kevin 版設計，更適合 RESTful API 架構。

---

## 下次接續

1. 從 `develop` 開 `feature/member-crud`
2. 實作 Show / Update / Delete
3. 每支 API 同步補 Swagger + Test
4. 全部完成後走 `release/1.0.0` → `main`
