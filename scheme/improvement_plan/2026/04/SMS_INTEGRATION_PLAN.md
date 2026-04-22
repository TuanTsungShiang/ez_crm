# SMS 簡訊整合計畫 — ez_crm Phase 8

> 版本：v0.1(草案)
> 建立日期：2026-04-22
> 狀態：規劃(尚未實作)
> 靈感來源:[linky_360_xampp](C:\xampp\htdocs\linky_360_xampp) 的 SMS 模組(legacy PHP)
> 三竹(Mitake)SMS API:http://smsapi.mitake.com.tw/api/mtk/SmSend

---

## 🎯 為什麼需要 SMS

linky_360(legacy 系統)有完整 SMS 模組,ez_crm 完全沒碰。對一個 CRM 產品這是**基本配備**:

| 使用情境 | SMS 的必要性 |
|---|---|
| **手機 OTP 驗證** | email 被搶佔 / 使用者打錯 / 增加第二身分維度,SMS 才能穩 |
| **登入異常警示** | 「有人從新 IP 登入」即時發給使用者 |
| **交易通知** | 付款成功 / 退款 / 到貨(配合 Phase 7 金流) |
| **行銷推播** | 生日優惠 / 促銷碼 / 會員日(B2B 客戶 core demand) |
| **自動化流程** | 事件觸發(如「加入 30 天沒動作 → 發召回簡訊」) |

---

## 📊 linky_360 vs ez_crm(phone/SMS 差距分析)

| 功能 | linky_360(legacy PHP)| ez_crm 現狀 | 優先級 |
|---|---|---|---|
| `phone` 欄位 | ✅ | ✅ | — |
| `phone_verified_at` 欄位 | ❌ | ✅(但沒用)| — |
| Phone OTP 驗證流程 | ❌ | ⬜ 只有 `type='phone'` 常數 | 🔴 高 |
| SMS Gateway 串接(Mitake)| ✅ | ⬜ | 🔴 高 |
| SMS Template 管理 | ✅ `sms_template*.php` | ⬜ | 🟡 中 |
| 單次發送(手動)| ✅ `message_add_sms.php` | ⬜ | 🟡 中 |
| 定期發送(cron)| ✅ `periodic_add_sms_message.php` | ⬜ | 🟢 低 |
| 觸發式發送(event)| ✅ `trigger_add_sms_message.php` | ⬜ | 🟡 中(可綁 webhook 事件) |
| 自動化流程 | ✅ `automation_sms_content.php` | ⬜ | 🟢 低 |
| 發送結果追蹤 | ✅ `message_result_sms.php` | ⬜ | 🔴 高(成本控管)|

---

## 🏗️ 架構決策

### 1. 選供應商:**三竹(Mitake)**為主,抽象層可切換

| 供應商 | 優 | 缺 |
|---|---|---|
| **三竹(Mitake)** | 台灣 #1,API 穩,已是 linky_360 用的 | 計費複雜 |
| **Every8D** | 界面新,API JSON | 價格略高 |
| **雲簡訊(DB)** | 小批量便宜 | API 不穩 |
| **Twilio** | 國際 #1 | 台灣號碼貴,部分功能在台被擋 |

走 **Mitake 主 + abstract driver interface**。`SmsDriver` interface 定義 `send() / query()`,以後加新 provider 只要實作一個 class,不動 controller。

### 2. 重用現有 OtpService

ez_crm 的 `OtpService` 已經處理 email OTP。**不要複製一份**,擴充既有 class 支援 phone:

```php
// Before (current)
$otpService->generate($member, MemberVerification::TYPE_EMAIL);
// After
$otpService->generate($member, MemberVerification::TYPE_PHONE);
```

`MemberVerification` table 本來就有 `type` 欄位區分 email / phone。這是昨天就預留的設計,現在剛好用上。

### 3. 發送方式:**Notification 走 SMS channel**

Laravel 的 `Notification` 系統可以自訂 channel。現有 `SendOtpNotification` 只支援 `mail` channel,擴充成多 channel:

```php
public function via($notifiable): array
{
    return $notifiable->prefers_sms ? ['sms'] : ['mail'];
    // 或根據 OTP type: phone → sms, email → mail
}

public function toSms($notifiable): string
{
    return "【ez_crm】您的驗證碼是 {$this->code},5 分鐘內有效。";
}
```

自訂 `SmsChannel`,內部呼叫 `SmsDriver::send()`。

### 4. 發送追蹤(成本控管必需)

SMS 每條 1-3 元起跳,**一定要追蹤**:
- 誰發、何時發、發給誰、內容、Mitake 回傳代碼(成功 / 空號 / 黑名單)、計費 credits

新表 `sms_messages`(類似 `webhook_deliveries`):

| 欄位 | 說明 |
|---|---|
| id | |
| member_id | nullable(行銷發給一堆人,不一定個別 member)|
| to_phone | E.164 格式(+886...)|
| content | 內容快照 |
| driver | mitake / every8d / ... |
| purpose | otp_login / otp_register / marketing / transaction / alert |
| status | queued / sent / delivered / failed / bounced |
| provider_message_id | Mitake 的 msgid |
| credits_used | Decimal,三竹扣幾點 |
| error_message | null 除非 failed |
| sent_at / delivered_at | |
| created_at | |

Index: `(member_id, created_at)`, `(status, sent_at)`, `(purpose)`

### 5. Rate Limit + 配額控管

- **單一 member 每小時最多 5 次 OTP 請求**(防濫用 / 刷 credits)
- **全站每分鐘 100 SMS**(防 bug 把 credits 燒光)
- 接近 credits 用完時發告警(環境變數設門檻)

---

## 📦 程式碼結構

```
app/
├── Services/Sms/
│   ├── Contracts/
│   │   └── SmsDriver.php              ← interface: send() / query() / balance()
│   ├── Drivers/
│   │   ├── MitakeDriver.php           ← 三竹實作
│   │   └── NullDriver.php             ← 測試用 / 未設定 credentials 時的 fallback
│   ├── SmsManager.php                 ← 依 config 決定用哪個 driver
│   └── SmsMessageLogger.php           ← 寫入 sms_messages 稽核
├── Notifications/Channels/
│   └── SmsChannel.php                 ← 串 Laravel Notification 系統
├── Http/Controllers/Api/V1/Auth/
│   └── SendPhoneOtpController.php     ← POST /auth/verify/phone/send
│   └── VerifyPhoneOtpController.php   ← POST /auth/verify/phone
└── Models/
    └── SmsMessage.php
```

---

## 🛣️ 新 API endpoints(Phase 8 階段追加)

| Method | Path | Guard | 說明 |
|---|---|---|---|
| POST | `/api/v1/auth/verify/phone/send` | public | 寄 OTP 到手機 |
| POST | `/api/v1/auth/verify/phone` | public | 驗證手機 OTP |
| POST | `/api/v1/me/phone/change/request` | member | 改手機需 OTP |
| POST | `/api/v1/me/phone/change/verify` | member | 同上 |

加起來 4 支,補齊「phone」這條線跟「email」等價。

---

## ⚡ 與 Webhook 系統的銜接(漂亮設計)

我們已有 `trigger_add_sms_message.php`(linky_360)的對應:**webhook event → SMS 發送**。

具體:
- 下游服務不用自己串 Mitake,只要**訂閱 webhook 事件**
- ez_crm 有個**內建的 SMS subscriber**(URL 指回自己),處理邏輯是「把 event 轉換成 SMS 發出去」
- 等於把 SMS 當成 webhook 的一個 consumer

```
member.created ──webhook──▶ ez_crm internal SMS handler ──▶ Mitake ──▶ 會員手機
                       │
                       └▶ game-hub(外部訂閱者)
                       └▶ SendGrid(發歡迎信)
                       └▶ 其他...
```

---

## 💰 成本優化策略:多通道 Fallback 設計

**SMS 每則 0.8 元,每天發 1 萬則就是 8 千元**。成本控管不是 nice-to-have,是必需。

### Channel 優先序(免費 → 付費)

`SmsManager::send($member, $message)` 內部要依序嘗試:

```
1. 檢查 member 有沒有 LINE 綁定?
   YES → 用 LINE Notify / LINE Messaging API 推播(★ 免費 ★)
   NO  → 下一步

2. 檢查 member 有沒有 APP push token?(未來有 app)
   YES → 用 FCM / APNs(★ 免費 ★)
   NO  → 下一步

3. 這則訊息是「必達」(OTP / 付款通知)還是「可延後」(行銷)?
   必達 → SMS
   可延後 → email(★ 0 元 ★)

4. SMS(Mitake)— 最後 fallback
```

### 為什麼這個設計是好東西

| 面向 | 說明 |
|---|---|
| **成本** | 假設 50% 會員綁 LINE → SMS 量直接砍半 → 每月省數千到數萬元 |
| **UX** | LINE 送達率高於 SMS(SMS 常被當垃圾訊息不開啟)|
| **合規** | LINE 不受 NCC 的 24:00-06:00 行銷時段限制 |
| **可觀測性** | 同一張 `notifications` 表記錄「這則訊息走哪個 channel 成功」,分析成本結構用 |

### 資料模型補充

`sms_messages` 重新命名為 **`notifications`**(含 SMS 只是 channel 之一):

| 欄位 | 說明 |
|---|---|
| ... 既有欄位 ... | |
| `channel` | `sms` / `line` / `fcm` / `email` / `webhook` |
| `fallback_attempts` | JSON array,紀錄嘗試過哪幾個 channel 各自的結果 |

### Phase 8 實作順序(優先級已調整)

| 階段 | 原定 | 新定 | 說明 |
|---|---|---|---|
| 8.0 | Mitake driver | **NullDriver + Log driver**(dev 免費)| dev 時用 log,不發真 SMS |
| 8.1 | Phone OTP | Phone OTP(用 LogDriver)| 同上 |
| 8.2 | Channel | **Notification channel 抽象**(先 SMS + email)| 建立 multi-channel 架構 |
| 8.3 | Rate limit | **加 LINE Notify channel** | 免費 channel 先到位 |
| 8.4 | Filament UI | Filament UI | |
| 8.5 | — | **加 Mitake driver**(production 上線前才做)| 需要申請企業帳號 |

**意涵**:dev 期間完全免費,需求驗證完才投錢買 Mitake credits。

---

## 📬 Email / SMS 部署對照(回答「乾脆 SendGrid?」)

很多人會想:既然 SMS 用 Twilio,email 為何還 Mailtrap 不 SendGrid?解釋兩層分工:

| 階段 | Email | SMS |
|---|---|---|
| **Dev** | Mailtrap(免費 sandbox)| **LogDriver**(我們自刻)or Twilio trial |
| **Staging** | SendGrid free tier(100/day)| Twilio trial($15)|
| **Production** | SendGrid / SES / Postmark | Mitake / Every8D |

一句話記:**「開發期攔截(不真送)、正式期走真的 provider」**。Mailtrap 跟 Mitake 都是「dev 攔截」的工具,雖然前者是專業沙盒、後者是我們自己做的 log 而已。

這避免 SMS 邏輯散落各 controller,用統一的事件驅動架構。

---

## 🎬 實作階段

| Phase | 內容 | 估時 |
|---|---|---|
| **8.0** 基礎設施 | `SmsDriver` interface + `MitakeDriver` 基本發送 + `sms_messages` migration + Model | 1 天 |
| **8.1** Phone OTP 驗證 | 擴充 `OtpService` 支援 phone + 2 支新 endpoint + feature test | 1 天 |
| **8.2** Notification Channel | 自訂 `SmsChannel`,`SendOtpNotification` 支援 sms / mail 切換 | 半天 |
| **8.3** Rate Limiter + 配額 | 濫用防護 + 低餘額告警 | 半天 |
| **8.4** Filament UI | `SmsMessageResource`(唯讀 + 篩選 + 成本統計 widget)| 1 天 |
| **8.5**(選配)SMS Template | 後台管 template + 前端呼叫 `sendByTemplate($name, $data)` | 1 天 |
| **8.6**(選配)Marketing 發送 | 批次發送 + 排程 + 客群篩選 | 2 天 |
| **合計(MVP 8.0-8.4)** | | **3-4 天** |
| **合計(含 8.5-8.6)** | | **6-7 天** |

MVP 先做 8.0-8.4,行銷功能看商業需求再開 8.5-8.6。

---

## 🧪 測試策略

### Unit
- `MitakeDriver::send()` with mocked Http::fake(),確認 URL / payload / parsed response 正確
- `SmsManager` 依 config 切 driver
- OTP purpose 在 `sms_messages` 正確記錄

### Feature
- `POST /auth/verify/phone/send` → 建 `MemberVerification(type=phone)` + `sms_messages` row + 呼叫 driver
- `POST /auth/verify/phone` → 驗碼成功 flip `phone_verified_at`
- Rate limit:同 member 6 次 OTP 請求,第 6 次被 A008 THROTTLED 擋

### Integration(要真 credentials,dev 時用假帳號)
- 用 Mitake 測試帳號發一封到真手機,確認收到

---

## ⚠️ 已知踩坑(從 linky_360 和業界經驗)

1. **Mitake 的編碼是 Big5**(不是 UTF-8),超級煩。要在 driver 裡做 `mb_convert_encoding`。
2. **簡訊字數**:英文 160 字 / 中文 70 字一條,超過會自動分段並**分段計費**。
3. **黑名單**:使用者填 0912345678(測試號)會被當空號,計費照算。正式環境要過濾明顯假號。
4. **政策**:台灣 NCC 法規,**24:00-06:00 不得發行銷簡訊**。要在 scheduler 做時段限制。
5. **狀態回報**:Mitake 有 callback URL 可以通知「delivered / failed / bounced」,但要另外申請。不開 callback 就永遠是 `sent` 狀態。
6. **成本**:三竹 0.8 元 / 條(一般量),10 萬條以上可談。OTP 簡訊會燒錢,要嚴格 rate limit。

---

## 📌 結論

- **做不做:做**(CRM 基本盤,跟 linky_360 比 SMS 是功能線差距最大的一塊)
- **何時做:Phase 7 金流整合前或後都可**(兩者獨立,但金流完成後做 SMS 可以交易通知一併接)
- **今天不動**:計畫留著,明天後天看優先級

---

## 📚 延伸閱讀

- [Mitake 三竹 API 文件](https://sms.mitake.com.tw/)(要登入)
- [linky_360 SMS 模組原始碼](C:\xampp\htdocs\linky_360_xampp\back\function\sms_process.php) — 雖 legacy,邏輯可參考
- [Laravel Notification Channels](https://laravel.com/docs/10.x/notifications)
- [NCC 簡訊管理辦法](https://www.ncc.gov.tw/)(時段限制條文)
