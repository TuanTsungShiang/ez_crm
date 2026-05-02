# ez_crm API Response Code 規範

> 版本：v1.0
> 建立日期：2026-04-14
> 來源：Kevin Legacy 版 + Claude 版合併
> 狀態：✅ 已實作於 `app/Enums/ApiCode.php`

---

## 設計原則

1. **HTTP Status Code 負責傳輸層語意**（200 / 201 / 401 / 404 / 422 / 500）
2. **`code` 欄位負責業務層語意**（串接方用 `code` 做程式判斷，不 parse `message`）
3. **`message` 是給人看的**（可多語系替換，`code` 不變）
4. **所有 API 回傳統一結構**
5. **Code 格式採分類前綴 + 三位數字**（Kevin 版設計，易於程式分類判斷）

---

## 統一回傳結構

### 成功（有資料）

```json
{
    "success": true,
    "code": "S200",
    "data": { ... }
}
```

### 成功（建立資源）

```json
{
    "success": true,
    "code": "S201",
    "data": { ... }
}
```

### 失敗

```json
{
    "success": false,
    "code": "V001",
    "message": "缺少必填欄位",
    "errors": {
        "name": ["The name field is required."]
    }
}
```

### 結構欄位說明

| 欄位 | 型別 | 必定存在 | 說明 |
|---|---|---|---|
| `success` | boolean | 是 | `true` / `false` |
| `code` | string | 是 | 分類前綴 + 三位數字 |
| `message` | string | 失敗時 | 人類可讀的錯誤描述 |
| `data` | object | 成功時 | 回傳資料 |
| `errors` | object | 驗證失敗時 | 各欄位的錯誤明細 |

---

## Code 定義總表

### S — 成功類

| Code | HTTP | Title | 說明 | 現階段實作 |
|---|---|---|---|---|
| `S200` | 200 | OK | 查詢成功、更新成功、刪除成功 | ✅ |
| `S201` | 201 | Created | 資源建立成功（POST） | ✅ |

> **關於 204 No Content：** 不使用。刪除成功統一回 `S200` + `200`，確保所有回應都有 `success` + `code` 結構。

---

### V — 驗證類

| Code | HTTP | Title | 說明 | 現階段實作 |
|---|---|---|---|---|
| `V001` | 422 | Missing Field | 缺少必填欄位 | ✅ |
| `V002` | 422 | Invalid Format | 格式不符（email 格式、日期格式等） | ✅ |
| `V003` | 422 | Out of Range | 數值超出範圍（per_page > 100 等） | ✅ |
| `V004` | 422 | Invalid Enum | 不支援的枚舉值（status 傳 9 等） | ✅ |
| `V005` | 422 | Invalid Relation | 關聯資源不存在（group_id / tag_ids 不存在） | ✅ |
| `V006` | 422 | Duplicate Field | 欄位需唯一（email / phone 重複） | ✅ |

> **與 Kevin 原版差異：**
> - HTTP 從 400 改為 **422**（Laravel FormRequest 預設行為，避免 override 成本）
> - `V005` 從 `Invalid State Param` 改為 `Invalid Relation`（更符合 CRM 的 FK 驗證場景）

---

### A — 認證 / 授權類

| Code | HTTP | Title | 說明 | 現階段實作 |
|---|---|---|---|---|
| `A001` | 401 | No Token | 未帶 Token 或 Token 無效 | ✅ |
| `A002` | 401 | Token Expired | Token 已過期 | 保留 |
| `A003` | 403 | Forbidden | 已登入但無權限（未來 RBAC 用） | 保留 |
| `A004` | 401 | Invalid Signature | 簽章無效（未來 webhook / API key 用） | 保留 |
| `A005` | 401 | MFA Required | 需要多因子驗證 | 保留 |

> **現階段 Sanctum 只能區分「有 token / 沒 token」，`A001` 涵蓋大部分情境。** `A002`~`A005` 等認證機制更複雜時再啟用。

---

### N — 資源不存在類

| Code | HTTP | Title | 說明 | 現階段實作 |
|---|---|---|---|---|
| `N001` | 404 | Not Found | 資源不存在（UUID 找不到、已被軟刪除） | ✅ |
| `N002` | 404 | Endpoint Not Found | API 端點不存在 | ✅ |

---

### C — 衝突類

| Code | HTTP | Title | 說明 | 現階段實作 |
|---|---|---|---|---|
| `C001` | 409 | Conflict | 重複建立（同一筆資料被同時操作） | 保留 |
| `C002` | 409 | Version Conflict | 版本衝突（樂觀鎖 / concurrent update） | 保留 |
| `C003` | 423 | Locked | 資源被鎖定 | 保留 |

> **現階段 CRM 尚無並發控制需求。** email / phone 重複的情境由 `V006` 在驗證層攔截，不需要到 409。未來有並發寫入需求時再啟用 C 系列。

---

### R — 速率限制類

| Code | HTTP | Title | 說明 | 現階段實作 |
|---|---|---|---|---|
| `R001` | 429 | Too Many Requests | 請求過於頻繁 | 保留 |
| `R002` | 429 | Account Locked | 嘗試過多，帳號暫鎖 | 保留 |
| `R003` | 429 | Cooldown | 發送太頻繁，請稍後 | 保留 |
| `R004` | 429 | Daily Cap Reached | 今日已達上限 | 保留 |
| `R005` | 409 | In Progress | 處理中（防重複提交） | 保留 |

> **全部保留定義，現階段不實作。** 等加上 Rate Limit middleware 或前台 Register（OTP 發送頻率限制）時再啟用。

---

### T — 業務規則類

| Code | HTTP | Title | 說明 | 現階段實作 |
|---|---|---|---|---|
| `T001` | 422 | Business Rule | 業務規則不成立 | 保留 |
| `T002` | 422 | Quota Exhausted | 額度 / 次數 / 名額用罄 | 保留 |
| `T003` | 422 | Precondition Failed | 缺前置條件（未驗證、資料未補等） | 保留 |
| `T004` | 422 | Not Eligible | 資格不符（等級、地區、黑名單、KYC） | 保留 |
| `T005` | 422 | Flow Restricted | 資格、時窗、流程規則限制 | 保留 |
| `T006` | 422 | State Transition Blocked | 狀態機轉換被擋 | 保留 |
| `T007` | 422 | Insufficient Balance | 餘額 / 點數 / 庫存不足 | 保留 |

> **Kevin 原版的 T004 `Verification Not Required` 改名為 `Not Eligible`（更泛用），T005 `Not Eligible` 改名為 `Flow Restricted`（避免重複）。**
> 全部保留定義，等 CRM 有會員等級、行銷活動、點數系統時再啟用。

---

### D — 依賴類

| Code | HTTP | Title | 說明 | 現階段實作 |
|---|---|---|---|---|
| `D001` | 502 | DB Unavailable | 資料庫無法連線 | 保留 |
| `D002` | 504 | Upstream Timeout | 上游逾時 | 保留 |
| `D003` | 502 | Provider Error | 外部服務錯誤 | 保留 |
| `D004` | 424 | Dependency Failed | 依賴失敗（通用） | 保留 |

> **保留定義，現階段不實作。** 等串接第三方服務（金流、簡訊、OAuth Provider）時再啟用。

---

### I — 內部錯誤類

| Code | HTTP | Title | 說明 | 現階段實作 |
|---|---|---|---|---|
| `I000` | 500 | Unknown Error | 未預期錯誤（兜底） | ✅ |
| `I001` | 500 | Null Dereference | 非預期空值 | 保留 |
| `I002` | 500 | Type Mismatch | 型別不相符 | 保留 |
| `I003` | 500 | Serialization Error | 序列化錯誤 | 保留 |
| `I004` | 500 | Config Error | 設定錯誤 | 保留 |
| `I005` | 500 | File System Error | 檔案系統錯誤 | 保留 |

> **現階段只實作 `I000` 作為兜底。** `I001`~`I005` 是 debug 用的細分，等有完整 logging / monitoring 後再細分。正式環境不應對外暴露 Internal Error 細節（安全考量），這些 code 主要用於內部 log。

---

## 現階段實作清單（Phase 4 CRUD 前完成）

| Code | 觸發點 |
|---|---|
| `S200` | `MemberCollection`、`MemberResource` 的 `with()` |
| `S201` | Create 成功回傳 |
| `V001`~`V006` | `FormRequest::failedValidation()` 根據 rule 分類 |
| `A001` | `Handler::unauthenticated()` |
| `N001` | `Handler::render()` 攔截 `ModelNotFoundException` |
| `N002` | `Handler::render()` 攔截 `NotFoundHttpException` |
| `I000` | `Handler::render()` 兜底 500 |

---

## 實作方式

### 1. 建立 `ApiCode` 常數類

```php
// app/Enums/ApiCode.php
class ApiCode
{
    const OK = 'S200';
    const CREATED = 'S201';

    const MISSING_FIELD = 'V001';
    const INVALID_FORMAT = 'V002';
    const OUT_OF_RANGE = 'V003';
    const INVALID_ENUM = 'V004';
    const INVALID_RELATION = 'V005';
    const DUPLICATE_FIELD = 'V006';

    const NO_TOKEN = 'A001';
    const FORBIDDEN = 'A003';

    const NOT_FOUND = 'N001';
    const ENDPOINT_NOT_FOUND = 'N002';

    const UNKNOWN_ERROR = 'I000';
}
```

### 2. 建立 `ApiResponse` helper trait 或 class

```php
// app/Http/Traits/ApiResponse.php
trait ApiResponse
{
    protected function success($data, string $code = ApiCode::OK, int $status = 200)
    {
        return response()->json([
            'success' => true,
            'code'    => $code,
            'data'    => $data,
        ], $status);
    }

    protected function error(string $code, string $message, int $status, array $errors = [])
    {
        $response = [
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $status);
    }
}
```

### 3. 統一錯誤處理在 `Handler.php`

```php
// 401
protected function unauthenticated($request, AuthenticationException $exception)
{
    if ($request->is('api/*') || $request->expectsJson()) {
        return response()->json([
            'success' => false,
            'code'    => ApiCode::NO_TOKEN,
            'message' => 'Unauthenticated.',
        ], 401);
    }
    // ...
}
```

---

## V 系列判定邏輯

FormRequest 驗證失敗時，根據 Laravel validation rule 自動對應 code：

| Laravel Rule | 對應 Code |
|---|---|
| `required`, `required_without` | `V001` Missing Field |
| `email`, `date_format`, `string`, `integer`, `boolean`, `array` | `V002` Invalid Format |
| `min`, `max`, `between` | `V003` Out of Range |
| `in` | `V004` Invalid Enum |
| `exists` | `V005` Invalid Relation |
| `unique` | `V006` Duplicate Field |

> **如果同一個 request 有多個欄位失敗且屬於不同 V code：** 回傳第一個失敗欄位對應的 code，`errors` 裡列出全部欄位明細。串接方看 `code` 知道主要問題類型，看 `errors` 知道所有細節。

---

## 與 Kevin 原版差異總結

| 項目 | Kevin 原版 | 合併版 | 原因 |
|---|---|---|---|
| 驗證類 HTTP | 400 | **422** | Laravel FormRequest 預設行為 |
| `V005` | Invalid State Param | **Invalid Relation** | CRM 場景是 FK 驗證 |
| `S204` | 保留 | **移除** | 統一結構需要 body，204 不允許 body |
| `T004` | Verification Not Required | **Not Eligible** | 原名易與 T003 混淆 |
| `T005` | Not Eligible | **Flow Restricted** | 避免與改名後的 T004 重複 |
| `R005` | 歸在 Rate Limit | 保持 | HTTP 409 合理，防重複提交 |
| 實作範圍 | 全部 | **分階段** | 現階段只實作 12 個，其餘保留定義 |
