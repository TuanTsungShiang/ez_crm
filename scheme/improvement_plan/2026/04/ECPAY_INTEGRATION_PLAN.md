# ECPay(綠界)金流整合計畫 — ez_crm Phase 7

> 版本：v0.1(草案)
> 建立日期：2026-04-22
> 狀態：規劃(尚未實作)
> 對應 Roadmap:**Phase 7 — 金流整合**
> 依賴:**Webhook Receiver 模組**(本計畫會一併納入)

---

## 🎯 為什麼選綠界(ECPay)

| 面向 | 評估 |
|---|---|
| **市佔** | 台灣第一或前二(另一是藍新金流 Newebpay)|
| **申請門檻** | 測試環境**免簽約免文件**,產文件要營業登記 |
| **支援** | 信用卡 / ATM 轉帳 / 超商代碼 / Apple Pay / Google Pay / Line Pay |
| **串接方式** | HTTP POST form + Server-to-Server 通知(webhook)|
| **文件品質** | 繁中文件完整,有 PHP / Node / Python SDK 範例 |
| **免費測試** | `vendor-stage.ecpay.com.tw`,公開測試 MerchantID/HashKey/HashIV |
| **社群** | 台灣開發者幾乎人人用過,踩坑經驗豐富 |

替代方案:
- **藍新金流(Newebpay)** — 市佔第二,功能類似,可作 Phase 7.5 雙 gateway 支援
- **紅陽(ESUN Red)**、**歐付寶(O'Pay)** — 相對冷門,不建議優先

---

## 🔁 業務流程概觀

```
[使用者在前端點「結帳」]
          │
          ▼
[前端呼叫 ez_crm POST /api/v1/payments]
          │
          ▼
[ez_crm 建 Order + 組 ECPay form + 回傳 HTML form data]
          │
          ▼
[前端 auto-submit form 到 ECPay 頁面]
          │
          ▼
[使用者在 ECPay 輸入卡號 / 選 ATM / 超商]
          │
          ▼
[付款完成,ECPay 做兩件事:]
  (1) 302 redirect 使用者 → ReturnURL(ez_crm 前端頁)
  (2) POST 通知 ez_crm backend → PaymentInfoURL ★這就是收 webhook
          │
          ▼
[ez_crm 收到 webhook → 驗簽 CheckMacValue → 更新 Order + 發 member.payment_completed 事件]
          │
          ▼
[ez_crm Webhook 系統 → 派送事件給下游(發票、積點、通知等)]
```

**關鍵觀察**:ECPay 的 PaymentInfoURL 其實就是**我們要收的 webhook**。這跟 Phase 1 做的 webhook 系統是**對偶方向**:

| 方向 | 誰是 sender | 誰是 receiver |
|---|---|---|
| 我們已做(4/22) | ez_crm | 下游服務 |
| 本計畫要做 | **ECPay** | **ez_crm**(新增 receiver 模組) |

---

## 🏗️ 架構決策

### 1. Webhook Receiver 模組(一併設計)

在 `app/Http/Controllers/Webhooks/` 下建 **通用** receiver controller,不是 ECPay 專屬。未來 LINE / SendGrid / 其他 provider 都走同一套。

```
app/Http/Controllers/Webhooks/
├── ECPayWebhookController.php   ← 驗 CheckMacValue + 轉 Order 狀態
├── (LINE, SendGrid 等未來追加)
└── (每個 provider 一個 Controller,各自驗簽規則)
```

### 2. 資料表新增

#### `orders` — 訂單主表

> 欄位設計已對應 2026-04-22 實測 smoke test(`ecpay-test/index.php`)ECPay sandbox 回傳的實際結構,不是純紙上規劃。

| 欄位 | 型別 | 對應 ECPay 回傳 | 說明 |
|---|---|---|---|
| id | BIGINT PK | — | |
| uuid | CHAR(36) UNIQUE | — | 對外公開 |
| member_id | BIGINT FK | — | 下單會員 |
| merchant_trade_no | VARCHAR(20) UNIQUE | `MerchantTradeNo` | 我們端產的,ECPay 硬限 ≤20 字 |
| provider_trade_no | VARCHAR(50) NULL | `TradeNo` | ECPay 自己的交易編號(例:`2604221743201915`)|
| amount | INT | `TotalAmount` / `TradeAmt` | 新台幣,整數 |
| currency | CHAR(3) DEFAULT 'TWD' | — | |
| status | ENUM | 由 `RtnCode` 決定 | pending / processing / paid / failed / refunded / cancelled |
| payment_method | VARCHAR(30) | `PaymentType` | `Credit_CreditCard` / `ATM_LAND` / `CVS_OK` / `BARCODE_BARCODE` 等 |
| items | JSON | — | 商品明細快照 |
| metadata | JSON | — | 自由擴充 |
| rtn_code | SMALLINT NULL | `RtnCode` | `1` = 成功,其他數字對應 ECPay 錯誤碼 |
| rtn_msg | VARCHAR(255) NULL | `RtnMsg` | `Succeeded` / 錯誤訊息 |
| trade_date | DATETIME NULL | `TradeDate` | ECPay 收單時間(不是我們送單時間)|
| paid_at | TIMESTAMP NULL | `PaymentDate` | 實際付款完成時間 |
| payment_type_charge_fee | INT NULL | `PaymentTypeChargeFee` | ECPay 抽成金額 |
| simulate_paid | BOOLEAN NULL | `SimulatePaid` | sandbox 會是 `1`,正式環境永遠 `0` |
| expires_at | TIMESTAMP NULL | — | 付款期限(ATM/CVS 有)|
| timestamps | — | — | |

Index: `(member_id, status)`, `(status, expires_at)`, `(merchant_trade_no)`, `(provider_trade_no)`

#### `order_payment_notifications` — ECPay 通知紀錄(稽核用)

| 欄位 | 型別 | 說明 |
|---|---|---|
| id | BIGINT PK | |
| order_id | BIGINT FK nullable | 對應到我們的 order(可能找不到)|
| merchant_trade_no | VARCHAR(20) | 從 ECPay 回傳 |
| raw_payload | JSON | ECPay 送的完整 POST data |
| check_mac_valid | BOOLEAN | 驗簽結果 |
| processed_at | TIMESTAMP NULL | 我們處理完的時間 |
| ip_address | VARCHAR(45) | 來源 IP(驗證是不是 ECPay IP)|
| created_at | TIMESTAMP | |

### 3. ECPay 特殊細節

- **CheckMacValue** 是 ECPay 自家簽章,不是 HMAC。演算法:
  1. 所有參數按 key 字母序排序
  2. 前後加 HashKey / HashIV:`HashKey=xxx&...&HashIV=xxx`
  3. URL-encode(特殊字元照 .NET URL-encode 規則,非標準)
  4. 轉小寫
  5. 取 SHA256(或舊版 MD5)大寫
- **CheckMacValue 必須由 package 處理**,自己刻會踩很多坑。用官方 SDK `ecpay/ecpay_logistics_sdk` 或 `greenworld/ecpay_payment` composer package。

### 4. 非同步 + 重試

- ECPay 的 PaymentInfoURL 會**重試直到收到 `1|OK`**。所以我們 receiver controller 驗簽完後**立即回 `1|OK`**,然後用 queue job 處理業務邏輯(更新 Order + 派 webhook)。
- 冪等(idempotency):同一 merchant_trade_no 多次通知只更新一次 → 靠 `orders.status` 狀態機阻擋。

---

## 🛣️ 路由設計

```php
// routes/api.php

// 會員自己用的 API (auth:member)
Route::prefix('v1/payments')
    ->middleware('auth:member')
    ->group(function () {
        Route::post('/', [PaymentController::class, 'store']);   // 建單 + 回 ECPay form data
        Route::get('{order:uuid}', [PaymentController::class, 'show']);
        Route::get('/', [PaymentController::class, 'index']);    // 我的訂單列表
    });

// ECPay → ez_crm 的 webhook(公開,靠 CheckMacValue 驗身)
Route::post('/webhooks/ecpay/payment', [ECPayWebhookController::class, 'paymentInfo']);
```

---

## 📦 程式碼結構

```
app/
├── Http/Controllers/
│   ├── Api/V1/
│   │   └── PaymentController.php
│   └── Webhooks/
│       └── ECPayWebhookController.php
├── Services/Payment/
│   ├── ECPayClient.php            ← 組 form、驗簽
│   ├── OrderService.php           ← 建 / 更新 order 狀態
│   └── PaymentNotificationHandler.php  ← receiver 邏輯
├── Jobs/
│   └── ProcessPaymentNotification.php  ← 收到 webhook 後異步處理
├── Events/Webhooks/
│   └── MemberPaymentCompleted.php  ← 付款完成觸發我們自己的 webhook
└── Models/
    ├── Order.php
    └── OrderPaymentNotification.php
```

---

## 🎬 實作階段拆分

| Phase | 內容 | 估時 |
|---|---|---|
| **7.0 — Receiver 框架** | 通用 `/webhooks/{provider}` 路由 + Job 分派 + 稽核表 | 1 天 |
| **7.1 — Order 資料層** | `orders` + `order_payment_notifications` migrations + Model + 基本 Service | 1 天 |
| **7.2 — 測試串接** | 裝 `greenworld/ecpay_payment` SDK + build form + 跳轉到 sandbox + ReturnURL | 1 天 |
| **7.3 — PaymentInfo Receiver** | 收 ECPay 通知 + CheckMacValue 驗簽 + 狀態機 + 冪等保護 | 1.5 天 |
| **7.4 — 事件串接** | 付款成功發 `MemberPaymentCompleted` 到 Phase 1 webhook 系統 | 半天 |
| **7.5 — Feature test** | 用 ECPay sandbox 跑 4 種情境(信用卡 / ATM / CVS / 取消)| 1 天 |
| **7.6 — Filament UI** | `OrderResource` / `PaymentNotificationResource`(唯讀 + 驗簽結果 badge)| 1 天 |
| **合計** | | **7 天 / ~1.5 週** |

---

## 🧪 測試策略

### Unit
- `ECPayClient::sign($data, $key, $iv)` 對 ECPay 官方測試資料產出正確 CheckMacValue
- `OrderService::stateTransition()` 狀態機(pending → paid 可、paid → pending 擋)

### Feature
- 前端呼叫 `/payments` → 回 form HTML 含正確 CheckMacValue
- POST `/webhooks/ecpay/payment` 帶正確簽章 → Order 變 paid
- 同樣 payload 重送 → Order 不重複更新(冪等)
- 錯誤簽章 → 記 `check_mac_valid=false`,不動 Order,仍回 `1|OK`(ECPay 拿到非 1|OK 會重試淹死我們)

### Integration
- 手動走完 sandbox 信用卡測試(用測試卡號 `4311-9511-1111-1111`)
- 手動走完 ATM 虛擬帳號 + 手動觸發 sandbox 發 PaymentInfo

---

## ⚠️ 已知踩坑

1. **CheckMacValue 的 URL-encode 規則是 .NET 版的**,不是 RFC 3986。不同語言實作會不一致,**必須用官方 SDK**。
2. **TradeNo 限 20 字**,要壓縮(通常用 date prefix + random suffix + encode)。
3. **PaymentInfoURL 要回 `1|OK` 純文字**,不是 JSON。其他任何回應 ECPay 都當失敗並重試,可能打爆你 server。
4. **ECPay 測試環境沒有 SSL 強制**,但**正式環境必須 HTTPS**。
5. **`ReturnURL` 和 `OrderResultURL` 不同**:前者是 PaymentInfo webhook,後者是使用者導回(UX)。文件常混用,要看清楚。
6. **ATM / CVS 是「先給代碼,幾天後才付」**,要寫 `expires_at` + 排程 expire order。
7. **退款(Refund)** 需要另外的 API,不是在 checkout form 裡的設定。

---

## 🔐 安全考量

- CheckMacValue 所有 payment info 驗簽必過(失敗 log + 不動資料)
- `raw_payload` 整包存以便稽核(PCI-DSS compliance 不要存卡號)
- **絕不在 log / 資料表存卡號、CVV、expiry**(ECPay 負責,我們只看 trade_no + 金額)
- Rate limiting `/webhooks/ecpay/payment`:若同 merchant_trade_no 短時間被打超過 N 次,代表有 bot,反擊
- IP 白名單:ECPay 正式發 IP 清單,只接受那幾個 IP(測試環境放寬)

---

## 🔗 銜接到現有架構

**可直接重用我們的 webhook 系統(當 sender)**:
- 收到 ECPay payment info 後:
  ```php
  event(new Webhooks\MemberPaymentCompleted($order, $member, $amount));
  ```
- 下游(行銷工具 / 會員點數系統 / 發票系統)訂閱 `member.payment_completed`,各自處理

**新增一個對偶機制(當 receiver)**:
- `/webhooks/ecpay/payment` 獨立走 receiver middleware / controller
- 驗簽流程跟發 webhook 的 HMAC 不同,**不共用 code**(刻意分開 service class)

---

## 📌 結論

- **做不做:做**(金流是 ez_crm 作為 CRM 平台的基本要求)
- **何時做:Phase 5-6 前台會員自助完整後**(明天 2026-04-23 後起算 2-3 週內可以開工)
- **要先補一個基石**:Webhook Receiver 模組(本計畫 7.0)
- **今天的行動項**:
  - ☑ 本計畫寫好
  - ☐ 下次 `users` / `members` 表 migration 時可以加預留欄位(`stripe_customer_id` / `ecpay_credit_token` 等,支援未來綁卡)

---

## 📚 延伸閱讀

- [綠界開發者文件](https://developers.ecpay.com.tw)
- [綠界 PHP SDK (Composer)](https://github.com/ECPay/ECPay.AIO.PHP)
- [CheckMacValue 驗簽規則詳解](https://developers.ecpay.com.tw/?p=2902)
- [RFC 5849 Section 3.6](https://tools.ietf.org/html/rfc5849#section-3.6) — ECPay URL encoding 的原始依據
