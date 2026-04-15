# API Response Code 規範（Claude 版）

> 版本：v1.0
> 建立日期：2026-04-14
> 狀態：提案，待與使用者版本合併

---

## 設計原則

1. **HTTP Status Code 負責傳輸層語意**（200 / 201 / 401 / 404 / 422 / 500）
2. **`code` 欄位負責業務層語意**（串接方用 `code` 做程式判斷，不 parse `message`）
3. **`message` 是給人看的**（可多語系替換，`code` 不變）
4. **所有 API 回傳統一結構**，成功與失敗格式一致

---

## 統一回傳結構

### 成功

```json
{
    "success": true,
    "code": "OK",
    "data": { ... }
}
```

### 成功（建立資源）

```json
{
    "success": true,
    "code": "CREATED",
    "data": { ... }
}
```

### 失敗

```json
{
    "success": false,
    "code": "VALIDATION_ERROR",
    "message": "Validation failed",
    "errors": { ... }
}
```

---

## Code 定義表

### 成功類

| Code | HTTP Status | 使用情境 |
|---|---|---|
| `OK` | 200 | 查詢成功、更新成功、刪除成功 |
| `CREATED` | 201 | 資源建立成功（POST） |

### 認證 / 授權類

| Code | HTTP Status | 使用情境 |
|---|---|---|
| `UNAUTHENTICATED` | 401 | 未帶 token 或 token 無效 |
| `FORBIDDEN` | 403 | 已登入但無權限（未來 RBAC 用） |

### 驗證類

| Code | HTTP Status | 使用情境 |
|---|---|---|
| `VALIDATION_ERROR` | 422 | 欄位驗證失敗（FormRequest 攔截） |

### 資源類

| Code | HTTP Status | 使用情境 |
|---|---|---|
| `NOT_FOUND` | 404 | 資源不存在（UUID 找不到、已被軟刪除） |
| `DUPLICATE_ENTRY` | 409 | unique 欄位衝突（email / phone 重複） |
| `GONE` | 410 | 資源已被永久刪除（選用，大部分可用 NOT_FOUND 代替） |

### 系統類

| Code | HTTP Status | 使用情境 |
|---|---|---|
| `SERVER_ERROR` | 500 | 非預期錯誤 |

---

## 各情境對應範例

### 查詢會員列表

```json
// GET /api/v1/members → 200
{
    "success": true,
    "code": "OK",
    "data": {
        "items": [...],
        "pagination": {...}
    }
}
```

### 建立會員成功

```json
// POST /api/v1/members → 201
{
    "success": true,
    "code": "CREATED",
    "data": {
        "uuid": "550e8400-...",
        "name": "王小明",
        ...
    }
}
```

### 驗證失敗

```json
// POST /api/v1/members → 422
{
    "success": false,
    "code": "VALIDATION_ERROR",
    "message": "Validation failed",
    "errors": {
        "email": ["The email has already been taken."],
        "name": ["The name field is required."]
    }
}
```

### 未認證

```json
// 任何 API 未帶 token → 401
{
    "success": false,
    "code": "UNAUTHENTICATED",
    "message": "Unauthenticated."
}
```

### 資源不存在

```json
// GET /api/v1/members/{uuid} → 404
{
    "success": false,
    "code": "NOT_FOUND",
    "message": "Member not found"
}
```

### Email/Phone 重複

```json
// POST /api/v1/members → 409
{
    "success": false,
    "code": "DUPLICATE_ENTRY",
    "message": "The email has already been taken.",
    "errors": {
        "email": ["The email has already been taken."]
    }
}
```

---

## 實作方式建議

### 統一回傳的實作位置

不在每個 Controller 手寫，而是透過以下方式統一：

1. **成功回傳**：在 `MemberCollection` / `MemberResource` 的 `with()` 方法加入 `success` + `code`
2. **驗證失敗**：在 `FormRequest::failedValidation()` 統一格式（現有 `MemberSearchRequest` 已做）
3. **401 / 404 / 500**：在 `Handler.php` 統一攔截，加上 `code` 欄位

### 需要修改的現有檔案

| 檔案 | 修改內容 |
|---|---|
| `MemberCollection.php` | `with()` 加入 `code: OK` |
| `MemberSearchRequest.php` | `failedValidation()` 加入 `code: VALIDATION_ERROR` |
| `Handler.php` | `unauthenticated()` 回傳加 `code`，加 `render()` 處理 404/500 |

---

## 保留彈性

- `code` 欄位是 **string**，未來可以擴充業務特定 code（例如 `MEMBER_QUOTA_EXCEEDED`）
- 目前先定義通用 code，模組特定 code 等功能擴充時再加
- `GONE` (410) 為選用，初期可以全部用 `NOT_FOUND` 代替

---

## 不做什麼

- 不做數字 code（例如 `10001`、`20003`）— 可讀性差，維護成本高
- 不在 code 裡帶模組前綴（例如 `MEMBER_VALIDATION_ERROR`）— 初期不需要這種粒度
- 不在成功回傳裡放 `message`（`code: OK` 已足夠，多了冗餘）
