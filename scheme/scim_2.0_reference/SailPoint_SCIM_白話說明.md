# SailPoint SCIM 整合 — 白話說明

> 原始文件：China_SailPoint_Integration.pdf
> 白話改寫日期：2026-04-13

---

## 一句話解釋

**SailPoint 是 Solventum 公司的「帳號總管家」。**
它負責決定「誰可以用哪些系統」，而我們的系統要提供 API 讓這個管家來操作帳號。

---

## 用比喻解釋

想像你開了一家公司，有很多內部系統（ERP、CRM、請假系統等等）。
以前每個系統都自己管帳號，員工入職要在 10 個系統各開一個帳號，離職要一個一個關。

**SailPoint 就是統一管理所有系統帳號的「中控台」。**

```
以前（沒有 SailPoint）：
  HR 通知 → IT 手動到系統 A 開帳號
                → IT 手動到系統 B 開帳號
                → IT 手動到系統 C 開帳號
                → ...（很累，容易漏）

現在（有 SailPoint）：
  HR 通知 → SailPoint 自動呼叫系統 A 的 API → 帳號開好了
                        呼叫系統 B 的 API → 帳號開好了
                        呼叫系統 C 的 API → 帳號開好了
                        ...（全自動，不會漏）
```

---

## 跟我們的關係

我們的 Solventum EWS 2.0 就是上面的「系統 A」。
我們需要提供 API（一個門），讓 SailPoint 這個管家進來幫我們：

- **開帳號**（新員工/新經銷商加入）
- **改帳號**（換部門、改 Email）
- **關帳號**（離職、停權）
- **分配角色**（你是 Admin、你是業務、你是經銷商）

---

## 什麼是 SCIM 2.0？

SCIM = System for Cross-domain Identity Management（跨域身分管理系統）

白話講就是：**一套「帳號管理 API 的統一格式」。**

就像 USB 是充電線的統一規格一樣，SCIM 是帳號管理 API 的統一規格。
SailPoint 說：「你照這個格式寫 API，我就能對接你。」

所以不管你用 Java、PHP、Python，只要 API 的輸入輸出符合 SCIM 格式就行。

---

## 我們要做什麼？（具體工作）

### 要建的 API 一覽

用餐廳比喻：SailPoint 是客人，我們是餐廳，API 是菜單上的菜。

| API | 動作 | 白話說明 | 餐廳比喻 |
|---|---|---|---|
| `POST /scim/v2/Users` | 建立使用者 | SailPoint 說：「幫我開一個帳號」 | 客人說：「我要點一份牛排」 |
| `GET /scim/v2/Users` | 查詢使用者列表 | SailPoint 說：「讓我看看你那邊有哪些帳號」 | 客人說：「菜單給我看一下」 |
| `PATCH /scim/v2/Users/123` | 更新使用者 | SailPoint 說：「這個人的 Email 換了」 | 客人說：「牛排要改全熟」 |
| `DELETE /scim/v2/Users/123` | 停用使用者 | SailPoint 說：「這個人離職了，關掉他」 | 客人說：「牛排不要了，取消」 |
| `GET /scim/v2/Groups` | 查詢群組列表 | SailPoint 說：「你們有哪些角色？」 | 客人說：「有哪些套餐？」 |
| `PATCH /scim/v2/Groups/456` | 修改群組成員 | SailPoint 說：「把這個人加到 Admin 群組」 | 客人說：「把薯條加到 A 套餐」 |
| `POST /token` | 取得 API 金鑰 | SailPoint 先證明自己的身份 | 客人先出示會員卡 |

### 認證方式：OAuth 2.0 Client Credentials

```
白話流程：

1. SailPoint 拿著一組帳密（client_id + client_secret）來敲我們的 /token API
2. 我們確認帳密正確 → 發一把「通行證」(access_token) 給它
3. SailPoint 之後每次呼叫 API 都帶著這把通行證
4. 我們每次都檢查通行證是否有效

就像進大樓：
  1. 訪客到一樓櫃台登記（/token）
  2. 櫃台給他一張臨時門禁卡（access_token）
  3. 訪客拿門禁卡刷各樓層的門（帶 Bearer token 呼叫 API）
  4. 門禁系統每次刷卡都驗證卡片是否有效
```

### SCIM 的資料格式長什麼樣？

SailPoint 要建立一個使用者時，會送這樣的 JSON 給我們：

```json
{
  "schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"],
  "externalId": "701984",
  "userName": "cchiang2@solventum.com",
  "name": {
    "familyName": "Chiang",
    "givenName": "Claire"
  },
  "emails": [
    {
      "value": "cchiang2@solventum.com",
      "type": "work",
      "primary": true
    }
  ],
  "active": true
}
```

我們收到後要：
1. 在資料庫建立這個使用者
2. 回傳 SCIM 格式的 JSON（包含我們產生的 UserId）

---

## 群組（Group）= 角色（Role）

在我們的系統裡，Group 就是角色：

| Group（SailPoint 看到的） | 角色（我們系統裡的） | 能做什麼 |
|---|---|---|
| twpw_admin | TWPW 管理員 | 後台全部功能 |
| solventum_sales | Solventum 業務 | 前端：產品註冊、更新、報修、歸還、查詢 |
| dsr | 經銷商業務代表 | 前端：同業務，但綁定特定經銷商 |
| hospital_engineer | 醫院工程人員 | 前端：只能查詢，不能改 |

SailPoint 會透過 `PATCH /scim/v2/Groups/xxx` 來把使用者加到對應的群組。

---

## 完整流程範例

### 場景：新業務人員 Amy 入職

```
1. HR 在 SailPoint 上申請：「Amy 需要 EWS 2.0 的 Solventum Sales 權限」

2. SailPoint 主管審批通過

3. SailPoint 自動執行：
   a. 先呼叫 POST /token → 拿到 access_token
   b. 呼叫 POST /scim/v2/Users → 在我們系統建立 Amy 的帳號
   c. 呼叫 PATCH /scim/v2/Groups/solventum_sales → 把 Amy 加到業務群組

4. Amy 用企業帳號登入我們的系統，就能使用業務的功能了
```

### 場景：Amy 離職

```
1. HR 在 SailPoint 上標記 Amy 離職

2. SailPoint 自動執行：
   a. 呼叫 DELETE /scim/v2/Users/amy_id → 我們系統停用 Amy 的帳號

3. Amy 再也無法登入我們的系統
```

---

## 重點注意事項

1. **我們不管使用者怎麼來的** — SailPoint 負責決定誰有權限，我們只負責「接收指令」
2. **B2C 用戶不走 SailPoint** — 這套只管 Solventum 內部員工和經銷商
3. **UserId 和 GroupId 一旦建立就不能改** — 這是 SCIM 規範
4. **Group 由我們建立和維護** — SailPoint 只會來「讀取」我們有哪些 Group，不會幫我們建
5. **需要跟 global SailPoint 團隊確認可行性** — 文件有提到「有些方案 SailPoint 支持但 global 不一定支持」
6. **SailPoint 有 sandbox 環境** — 可以跟我們的 DEV/QA 環境對接測試

---

## 對我們 PHP 開發的影響

| 項目 | 影響 |
|---|---|
| 路由 | 需要處理 RESTful 路由（GET/POST/PATCH/DELETE 同一個 URL），Lava 的 `_ajax.php?sel=xxx` 模式不適用，需要另外寫一個 SCIM 入口 |
| JSON 格式 | 輸入輸出都是 SCIM 規範的 JSON，不是我們自己定義的 |
| 認證 | 要實作 OAuth 2.0 token 發放和驗證 |
| 測試 | 需要能被外部（SailPoint sandbox）呼叫，所以開發環境要有公網可達的 URL 或用 ngrok |
| PHP SDK | 沒有現成的，要自己寫，但 API 數量不多（7~10 支），手刻可行 |
