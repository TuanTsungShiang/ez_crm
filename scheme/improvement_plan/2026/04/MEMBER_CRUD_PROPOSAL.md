# Member CRUD 實作提案（Show / Update / Delete）

> 版本：v1.1
> 建立日期：2026-04-15
> 分支：`feature/member-crud`
> 狀態：提案，討論後更新

---

## 範圍

完成 RESTful CRUD 的後 3 支 API：

| Method | Endpoint | 說明 |
|---|---|---|
| `GET` | `/api/v1/members/{uuid}` | Show — 查看單一會員（含 profile） |
| `PUT` | `/api/v1/members/{uuid}` | Update — 更新會員資料 |
| `DELETE` | `/api/v1/members/{uuid}` | Delete — 軟刪除會員 |

Search 與 Create 已完成，不動。

---

## 設計決策（已確認）

| # | 決策 | 理由 |
|---|---|---|
| 1 | 獨立 `MemberDetailResource` | List / Detail 未來差異會擴大，互不影響 |
| 2 | `tag_ids` 用 **sync** 語意 | 完整覆蓋，前端送最終狀態，API 單純 |
| 3 | 不做 Restore API | 現階段優先度低，未來有需求再加 |
| 4 | Route Model Binding 用 `{member:uuid}` | 對外永遠以 uuid 為識別，不暴露自增 ID |
| 5 | PUT 採 partial update，不傳 = 不動 | 跟 Salesforce / HubSpot 一致，符合 CRM 編輯表單直覺 |
| 6 | Update email/phone 自動清空 `email_verified_at` / `phone_verified_at` | KYC 合規 + 防止 account takeover，對齊 Stripe / Auth0 / Cognito |
| 7 | Delete 回 `200` + `S200` + `{ uuid, deleted_at }` | 統一回應格式，前端不用針對 DELETE 寫特殊 parser |

---

## Part A. Show API

### A1. Endpoint

```
GET /api/v1/members/{uuid}
Authorization: Bearer {token}
```

### A2. Response 200

`MemberDetailResource` 回傳比 `MemberResource` 更完整的資料：

```json
{
    "success": true,
    "code": "S200",
    "data": {
        "uuid": "550e8400-...",
        "name": "王小明",
        "nickname": "Ming",
        "email": "ming@example.com",
        "phone": "0912345678",
        "status": 1,
        "email_verified_at": "2026-01-15T08:30:00+00:00",
        "phone_verified_at": null,
        "group": {
            "name": "一般會員"
        },
        "tags": [
            { "name": "潛力客", "color": "#3B82F6" }
        ],
        "profile": {
            "avatar": null,
            "gender": 1,
            "birthday": "1990-05-15",
            "bio": null,
            "language": "zh-TW",
            "timezone": "Asia/Taipei"
        },
        "sns": [
            { "provider": "google" }
        ],
        "has_sns": true,
        "last_login_at": "2026-04-10T08:00:00+00:00",
        "created_at": "2026-01-01T00:00:00+00:00",
        "updated_at": "2026-04-10T08:00:00+00:00"
    }
}
```

### A3. 錯誤處理

| 情境 | Status | Code |
|---|---|---|
| 會員不存在 / UUID 無效 | 404 | `N001` |
| 已被軟刪除的會員 | 404 | `N001`（不區分，一律回 Not Found） |
| 未認證 | 401 | `A001` |

> **軟刪除策略：** 用 `Member::findOrFail` 搭配 Route Model Binding，預設不會撈到 `deleted_at` 非 NULL 的記錄，直接拋 `ModelNotFoundException` → Handler 攔截為 `N001`。

### A4. 涉及檔案

- `app/Http/Resources/Api/V1/MemberDetailResource.php`（新增）
- `app/Http/Controllers/Api/V1/MemberController.php`（加 `show` method）
- `routes/api.php`（加路由）

---

## Part B. Update API

### B1. Endpoint

```
PUT /api/v1/members/{uuid}
Authorization: Bearer {token}
Content-Type: application/json
```

### B2. Request Body

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| `name` | string | 否 | 不傳就不動 |
| `nickname` | string | 否 | 不傳就不動，傳 `null` 才會清空 |
| `email` | string | 否 | unique 檢查需排除當前會員自己 |
| `phone` | string | 否 | unique 檢查需排除當前會員自己 |
| `password` | string | 否 | 傳了才會更新，min 8 |
| `status` | integer | 否 | `0` / `1` / `2` |
| `group_id` | integer | 否 | exists + nullable（傳 `null` 代表移出群組） |
| `tag_ids` | array | 否 | **sync 語意**：傳了就完整覆蓋，不傳不動，傳 `[]` 代表清空所有 tag |
| `profile.gender` | integer | 否 | 部分更新 |
| `profile.birthday` | date | 否 | 部分更新 |
| `profile.bio` | string | 否 | 部分更新 |
| `profile.avatar` | string | 否 | 部分更新 |
| `profile.language` | string | 否 | 部分更新 |
| `profile.timezone` | string | 否 | 部分更新 |

### B3. Partial Update 策略

**只更新 request 有帶的欄位**，沒帶的欄位保持原值。符合業界 CRM 產品線（Salesforce / HubSpot / Shopify）的做法。

| 語意 | 行為 |
|---|---|
| 不傳欄位 | 不動 |
| 傳 `null` | 清空（例外：`password` 為 null 代表不動） |
| `tag_ids: []`（空陣列） | 清空所有 tag |
| `tag_ids` 不傳 | 不動 |

實作方式：
```php
// MemberUpdateService
$member->fill($request->only(['name', 'email', 'phone', ...]));

// KYC 安全：email / phone 變動時自動清空對應的 verified_at
if ($member->isDirty('email')) {
    $member->email_verified_at = null;
}
if ($member->isDirty('phone')) {
    $member->phone_verified_at = null;
}

$member->save();

// profile 同樣邏輯（partial update）
$member->profile->fill($request->input('profile', []));
$member->profile->save();

// tags 特殊：有帶就 sync，沒帶就不動
if ($request->has('tag_ids')) {
    $member->tags()->sync($request->input('tag_ids', []));
}
```

### B3-1. KYC 安全策略（新增）

當管理員更新 `email` 或 `phone` 時，系統**自動清空**對應的驗證時間：

| 動作 | 自動效果 |
|---|---|
| 更新 `email`（值有變動） | `email_verified_at` 設為 `null` |
| 更新 `phone`（值有變動） | `phone_verified_at` 設為 `null` |
| 更新的 `email` 與原值相同 | `email_verified_at` 不動 |

**理由：**
- **KYC 合規**：Email 是身份驗證的一部分，換 email 等於換身份，舊驗證不應延續
- **防止 account takeover**：避免攻擊者改 email 卻保留已驗證狀態，繞過「忘記密碼」驗證流程
- **業界最佳實踐**：對齊 Stripe、AWS Cognito、Auth0 的行為

如果未來需要「管理員強制標記已驗證」的功能，另開 `PATCH /members/{uuid}/verify-email` 處理。

### B4. `unique` 規則排除自己

```php
'email' => ['nullable', 'email', 'max:191', Rule::unique('members', 'email')->ignore($member->id)],
'phone' => ['nullable', 'string', 'max:20', Rule::unique('members', 'phone')->ignore($member->id)],
```

### B5. Response 200

回傳更新後的完整資料（複用 `MemberDetailResource`）：

```json
{
    "success": true,
    "code": "S200",
    "data": { ...MemberDetailResource... }
}
```

### B6. 錯誤處理

| 情境 | Status | Code |
|---|---|---|
| 會員不存在 | 404 | `N001` |
| email / phone 重複（非自己） | 422 | `V006` |
| group_id / tag_ids 不存在 | 422 | `V005` |
| status 非法值 | 422 | `V004` |
| password 太短 | 422 | `V003` |
| 未認證 | 401 | `A001` |

### B7. 涉及檔案

- `app/Http/Requests/Api/V1/MemberUpdateRequest.php`（新增）
- `app/Services/MemberUpdateService.php`（新增，DB transaction）
- `app/Http/Controllers/Api/V1/MemberController.php`（加 `update` method）
- `routes/api.php`（加路由）

---

## Part C. Delete API

### C1. Endpoint

```
DELETE /api/v1/members/{uuid}
Authorization: Bearer {token}
```

### C2. 行為

- 走 **soft delete**（`deleted_at` 欄位）
- 不真正移除資料庫記錄
- 連動行為：
  - `member_profiles` / `member_sns` 等關聯資料**不動**（soft delete 只標記主表）
  - tag pivot 不動
  - 之後 Search / Show 都不會回傳這筆

### C3. Response 200

不使用 204（因為我們的統一格式需要有 body）：

```json
{
    "success": true,
    "code": "S200",
    "data": {
        "uuid": "550e8400-...",
        "deleted_at": "2026-04-15T10:00:00+00:00"
    }
}
```

### C4. 錯誤處理

| 情境 | Status | Code |
|---|---|---|
| 會員不存在 | 404 | `N001` |
| 會員已被軟刪除（重複 delete） | 404 | `N001`（Route Model Binding 本來就撈不到） |
| 未認證 | 401 | `A001` |

### C5. 涉及檔案

- `app/Http/Controllers/Api/V1/MemberController.php`（加 `destroy` method，邏輯簡單直接寫 inline，不開 service）
- `routes/api.php`（加路由）

> **設計決策：** Delete 邏輯單純（一行 `$member->delete()`），不需要另開 `MemberDeleteService`。Update 比較複雜才開 Service。

---

## Part D. Route Model Binding 設定

### D1. 路由格式

```php
Route::get('members/{member:uuid}',    [MemberController::class, 'show']);
Route::put('members/{member:uuid}',    [MemberController::class, 'update']);
Route::delete('members/{member:uuid}', [MemberController::class, 'destroy']);
```

`{member:uuid}` 告訴 Laravel：**用 uuid 欄位查詢 Member model**，不是預設的 id。

### D2. Controller signature

```php
public function show(Member $member) { ... }
public function update(MemberUpdateRequest $request, Member $member) { ... }
public function destroy(Member $member) { ... }
```

Laravel 會自動用 `Member::where('uuid', $uuid)->firstOrFail()` 注入進來。找不到直接拋 `ModelNotFoundException`，Handler 會統一攔截回 `N001`。

---

## Part E. 測試計畫

### E1. MemberShowTest

| 測試 | 驗證 |
|---|---|
| 成功取得會員詳細資料 | 200 + S200 + 完整結構（含 profile / sns） |
| 會員不存在（UUID 隨機） | 404 + N001 |
| 已軟刪除的會員 | 404 + N001 |
| 未認證 | 401 + A001 |

### E2. MemberUpdateTest

| 測試 | 驗證 |
|---|---|
| 成功更新單一欄位（name） | 200 + S200 + 回傳更新後資料 |
| 部分更新 profile（只改 gender） | 其他 profile 欄位不變 |
| tag_ids sync 語意 | 傳 `[2,4]` 完整覆蓋原本的 `[1,2,3]` |
| tag_ids 傳空陣列 | 清空所有 tag |
| tag_ids 不傳 | 完全不動 |
| email 改為別人已用的 email | 422 + V006 |
| email 改為自己原本的 email | 200 成功（不會誤判 unique 衝突） |
| password 傳了會被 hash 儲存 | DB 裡的密碼是 hash 過的 |
| password 不傳 | 原密碼保留 |
| group_id 改為 null | 成功移出群組 |
| group_id 不存在 | 422 + V005 |
| **更新 email 自動清空 `email_verified_at`** | DB 裡 `email_verified_at` 變為 null |
| **更新 phone 自動清空 `phone_verified_at`** | DB 裡 `phone_verified_at` 變為 null |
| **更新 email 為原值（未變動）** | `email_verified_at` 保持不動 |
| 會員不存在 | 404 + N001 |
| 未認證 | 401 + A001 |

### E3. MemberDeleteTest

| 測試 | 驗證 |
|---|---|
| 成功軟刪除 | 200 + S200 + deleted_at 有值 |
| 被刪除的會員不會出現在 Search | Search 列表撈不到 |
| 被刪除的會員 Show 回 404 | 404 + N001 |
| 重複 delete 同一個會員 | 404 + N001 |
| 未認證 | 401 + A001 |

---

## Part F. Swagger 文件

三支 API 各補 `@OA` 註解：
- `@OA\Get` for Show
- `@OA\Put` for Update
- `@OA\Delete` for Destroy

每支都要含：
- Path parameter `{uuid}`
- Response 200 schema（Show / Update 用 DetailResource 的完整結構）
- Response 401 / 404 / 422 的 error 結構

---

## 實作順序建議

```
1. MemberDetailResource（底層資料結構先定）
2. Show API（GET /members/{uuid}）
   ├── Controller show method
   ├── Route
   └── MemberShowTest
3. Update API（PUT /members/{uuid}）
   ├── MemberUpdateRequest
   ├── MemberUpdateService
   ├── Controller update method
   ├── Route
   └── MemberUpdateTest
4. Delete API（DELETE /members/{uuid}）
   ├── Controller destroy method
   ├── Route
   └── MemberDeleteTest
5. Swagger 註解（3 支一起補）
6. 全部測試跑過
7. Commit + Push + Merge to develop
```

---

## 預估工時

| 項目 | 時間 |
|---|---|
| Show | 30 分鐘 |
| Update | 1 小時（驗證規則、sync 邏輯、自己排除 unique） |
| Delete | 15 分鐘 |
| 測試（3 個檔，約 20 個 test case） | 1 小時 |
| Swagger | 30 分鐘 |
| **合計** | **約 3.5 小時** |

---

## 風險與注意事項

### 1. email / phone unique 排除自己

常見陷阱：`unique:members,email` 會把**自己當前的 email** 也視為衝突。必須用 `Rule::unique()->ignore($member->id)` 排除自己，否則會員想保留原 email 送 PUT 就會失敗。

### 2. Partial update 的 null 語意

要先決定：**傳 `null` 代表「清空」還是「不動」？**

本提案採用：
- **不傳欄位** = 不動
- **傳 `null`** = 清空（例如 `nickname: null` 會清空暱稱）

但有例外：
- `tag_ids: []`（空陣列）= 清空所有 tag
- `tag_ids` 不傳 = 不動

這個規則要在 Swagger 和測試裡講清楚。

### 3. Update 時 email / phone 變動的副作用

**自動處理（已納入 Phase）：**
- 清空 `email_verified_at` / `phone_verified_at`（見 B3-1）

**不做（避免 scope 爆炸）：**
- 不發驗證信
- 不通知會員
- 不觸發 `member_verifications` 新 OTP 記錄

如果未來要做完整的驗證流程（發驗證信、追蹤 OTP），另外開 `PATCH /members/{uuid}/email` 或 `POST /members/{uuid}/send-verification` 這種專門的 endpoint。

### 4. 時區處理（跨國系統提醒）

現階段採用：
- **儲存 / 傳輸一律 UTC**（Laravel 預設 + `toIso8601String()` 輸出 `+00:00`）
- **顯示由前端根據使用者 timezone 轉換**

**已知潛在問題（跨國時才會爆）：**
- Search API 的 `created_from` / `created_to` 目前用 `whereDate()` 比對，會受 server timezone 影響
- 跨國上線時需改為接受帶時區的完整 ISO 8601（例如 `2026-04-15T00:00:00+08:00`），後端轉 UTC 後再比對

**現階段不處理**，待跨國需求明確時再重構。

---

## Acceptance Criteria

全部符合才算完成：

- [ ] `GET /api/v1/members/{uuid}` 回傳完整會員資料（含 profile）
- [ ] `PUT /api/v1/members/{uuid}` 支援 partial update
- [ ] `PUT` 的 `tag_ids` 採用 sync 語意
- [ ] `PUT` 的 email/phone unique 排除自己
- [ ] `DELETE /api/v1/members/{uuid}` 為軟刪除
- [ ] 被軟刪除的會員不會出現在 Search 或 Show
- [ ] 所有錯誤回傳統一 code 格式（S/V/A/N）
- [ ] Swagger 文件三支都有完整註解
- [ ] 測試全部通過（新增 ~20 個 test case）
- [ ] Merge 回 develop
