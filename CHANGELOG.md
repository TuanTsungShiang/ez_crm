# Changelog

All notable changes to `ez_crm` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/zh-TW/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/lang/zh-TW/spec/v2.0.0.html).

---

## [1.0.0] - 2026-04-15

### Release Theme

**第一個正式版本：Member 模組完整 CRUD + 統一 API 回應規範**

### Added — 新增功能

#### Member CRUD API

- `GET /api/v1/members` — 會員列表搜尋，支援多條件篩選、排序、分頁
- `POST /api/v1/members` — 建立會員（CRM 後台用）
- `GET /api/v1/members/{uuid}` — 查看單一會員詳細資料（含 profile、sns、驗證狀態）
- `PUT /api/v1/members/{uuid}` — 部分更新會員（不傳 = 不動，`tag_ids` 採 sync 語意）
- `DELETE /api/v1/members/{uuid}` — 軟刪除會員

#### 資料模型

- `members` 主表（UUID、Soft Delete、驗證時間戳）
- `member_profiles` 延伸個人資料（一對一）
- `member_sns` 第三方登入綁定（一對多）
- `member_groups` 會員分群
- `tags` 標籤系統（多對多 via `member_tag`）
- 預備但尚未啟用：`member_addresses`、`member_verifications`、`member_login_histories`、`member_devices`

#### 認證與授權

- Laravel Sanctum token-based 認證
- 所有 `/api/v1/*` 路由需要有效 Bearer Token
- API 路徑的錯誤統一回傳 JSON 格式（不再 redirect 到 login 頁）

#### API 回應規範

- 統一回應結構 `{ success, code, data?, message?, errors? }`
- 39 組 API Response Code 定義（現階段實作 12 組）
  - `S200` / `S201` — 成功類
  - `V001`~`V006` — 驗證類（根據 Laravel validation rule 自動對應）
  - `A001` — 認證類
  - `N001` / `N002` — 資源不存在類
  - `I000` — 內部錯誤兜底
- 完整規範見 [API_RESPONSE_CODE_FINAL.md](scheme/improvement_plan/2026/04/API_RESPONSE_CODE_FINAL.md)

#### 安全性

- 對外只暴露 UUID，不回傳資料庫自增 ID（避免洩漏資料量）
- 更新 email/phone 時**自動清空** `email_verified_at` / `phone_verified_at`（KYC 合規）
- `sort_by` 雙層防禦（FormRequest + Service whitelist）

#### 效能

- `members` 表新增索引：`status`、`created_at`、`last_login_at`
- `member_tag` 表新增索引：`tag_id`
- `tag_ids` 篩選從多個 `whereHas` 改為單一 subquery + `HAVING COUNT`

#### 開發工具

- 整合 L5 Swagger（darkaonline/l5-swagger）
- 所有 API 皆有 OpenAPI 3.0 註解
- Swagger UI：`/api/documentation`

#### 測試

- 獨立測試資料庫 `ez_crm_testing`（不污染開發 DB）
- 63 個 Feature / Unit Test，204 個 assertions

### Changed — 變更

- 路由 `GET /api/v1/members/search` → `GET /api/v1/members`（RESTful 標準化）
- `MemberResource` 移除所有自增 ID，只回傳 UUID + 名稱

### Security

- 關閉公開的 Member Search API（原先無認證即可存取全體會員資料）
- Authentication middleware 對 API 路徑回傳 401 JSON 而非 redirect

---

## 版本規則

- `Major`：破壞性變更（例：API 結構改版、移除舊 endpoint）
- `Minor`：向下相容的功能新增（例：新增新的 API）
- `Patch`：向下相容的 bug 修正
