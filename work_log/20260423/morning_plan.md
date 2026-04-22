# 2026-04-23 (Thu) — 開工計畫

> 準備時間:2026-04-22 下午 16:09(提早寫好,明天進到 repo 就能直接動工)
> 目標時段:9:30-12:30(上午 3 小時)+ 13:30-18:00(下午 4.5 小時)

---

## 📌 起點 context(明天 3 分鐘看完就進狀態)

### 昨天收工在哪
- ez_crm `develop` 最新 commit:`0c978b7 Merge chore/queue-database-driver`
- ez_crm_client `develop` 最新 commit:`161080e docs: add 2026-04-22 afternoon work log`
- 雙 repo 分支都乾淨,只剩 `develop + main`
- Tests:166 passed / 538 assertions(後端)
- 昨天 session log:[scheme/improvement_plan/2026/04/SESSION_LOG_2026_04_22.md](../../scheme/improvement_plan/2026/04/SESSION_LOG_2026_04_22.md)

### 現有產品狀態
- **後端**:前台 Auth API 完整(register / verify / login / forgot-reset / OAuth×4 / me×6)+ Webhook 系統 Phase 1+2 完整
- **前端**:Home dashboard / Login / Register / Verify / Forgot / Reset / /me 看自己資料
- **Webhook 4 events 已接**:`member.created`, `member.email_verified`, `member.logged_in`, `oauth.bound`

### 還沒做的重要項
- 前端 `/me/edit`, `/me/password`, `/me/sns`, `/me/destroy`
- 後端 `member.updated`, `member.deleted`, `oauth.unbound` 3 個 webhook event + 觸發點
- Webhook consumer guide(給下游服務開發者看的整合文件)
- Production queue 啟動指引

---

## 🎯 明天主軸:**閉合會員自助循環 + Webhook 事件全員到齊**

### 為什麼選這個

昨天完成了**後端事件源頭**和**前端使用者基礎動作**,但:
- 前端缺「改資料 / 改密碼 / 綁解 SNS / 註銷」實作,用戶體驗不完整
- 後端缺 3 個 event 類型,下游如果要接 CRM / 行銷自動化,事件不齊全
- 兩者剛好一對一:前端補一個頁面,後端同時補對應 event

**這是一天內可收口的完整里程碑**,做完 ez_crm MVP 就幾乎 feature-complete。

---

## ⏱️ 時段安排

### 上午 09:30-12:30(3 小時)— 後端 3 個新 event

| 時段 | 任務 | 估時 |
|---|---|---|
| 09:30-09:45 | 對錶 + pull 兩個 repo 最新 + 跑 full test 確認綠 | 15 分 |
| 09:45-10:30 | `MemberUpdated` event + MeController@update 觸發 + 測試 | 45 分 |
| 10:30-11:15 | `MemberDeleted` event + MeController@destroy 觸發 + 測試 | 45 分 |
| 11:15-12:00 | `OAuthUnbound` event + 後端 `DELETE /me/sns/{provider}` endpoint + OAuthService 解綁邏輯 + 測試 | 45 分 |
| 12:00-12:30 | Commit + push + merge feature branch + 休息 | 30 分 |

### 中午 12:30-13:30 午休

### 下午 13:30-18:00(4.5 小時)— 前端 4 個 `/me` 頁面

| 時段 | 任務 | 估時 |
|---|---|---|
| 13:30-14:30 | `/me/edit` (PUT /me) + form validation + 成功 toast | 60 分 |
| 14:30-15:15 | `/me/password` (PUT /me/password) + current_password 驗證 | 45 分 |
| 15:15-16:15 | `/me/sns` 綁定管理(列表 + 綁新 provider via popup + 解綁確認)| 60 分 |
| 16:15-16:45 | `/me/destroy` 註銷確認 modal + 跳轉邏輯 | 30 分 |
| 16:45-17:15 | Home Dashboard 快速操作按鈕「編輯資料 / 更改密碼 / 綁定管理」從 disabled 放開 | 30 分 |
| 17:15-18:00 | 端到端測試 + commit + push + 明天的 session log + 收工 | 45 分 |

---

## ✅ 後端 task 清單(上午照打勾)

### T1. MemberUpdated event(45 分)

- [ ] 建 `app/Events/Webhooks/MemberUpdated.php`
  - payload 帶 diff(舊值 vs 新值)
- [ ] 在 `MeController@update` 成功儲存後 `event(new MemberUpdated($member, $changes))`
- [ ] `EventServiceProvider::$listen` 加註冊
- [ ] `WebhookSubscriptionResource::availableEvents()` 加選項
- [ ] feature test:修 nickname/phone 觸發事件,payload 包含 changes

### T2. MemberDeleted event(45 分)

- [ ] 建 `app/Events/Webhooks/MemberDeleted.php`
- [ ] `MeController@destroy`(軟刪除)完成後 `event(new MemberDeleted($member))`
- [ ] 註冊 + Filament 選項
- [ ] feature test

### T3. OAuthUnbound event + 解綁 endpoint(45 分)

- [ ] 後端路由 `DELETE /api/v1/me/sns/{provider}` 在 `routes/api.php`
- [ ] `MeController@unbindSns` 方法
  - 檢查:若這是**唯一登入方式**(沒其他 SNS 也沒密碼)要擋(回 A012 LAST_LOGIN_METHOD)
- [ ] 建 `app/Events/Webhooks/OAuthUnbound.php`
- [ ] 解綁成功後 `event(new OAuthUnbound(...))`
- [ ] Filament 選項補齊
- [ ] feature test:happy + 擋唯一登入方式 case

---

## ✅ 前端 task 清單(下午照打勾)

### T4. `/me/edit` 頁(60 分)

- [ ] 新增 route `/me/edit` with requireAuth guard
- [ ] 新 `MeEditView.vue`:name / nickname / phone 三欄
- [ ] `api/me.ts::updateMe(payload)` 呼叫 `PUT /me`
- [ ] 成功後 `auth.setMember(...)` 更新 store,跳回 `/me`,顯示 toast
- [ ] 錯誤處理(422 validation errors inline 顯示)

### T5. `/me/password` 頁(45 分)

- [ ] 新 `MePasswordView.vue`:current_password + new_password + confirmation
- [ ] `api/me.ts::updatePassword(...)`
- [ ] A009 錯誤對應「目前密碼錯誤」
- [ ] 成功後提示「請重新登入」(所有 token revoked)+ 清 auth store + 跳 `/login`

### T6. `/me/sns` 頁(60 分)

- [ ] 新 route 列出 `member.sns` 每個 provider 當前綁定狀態
- [ ] 未綁 → 顯示「綁定」按鈕 → 開 popup 走 OAuth flow(重用 `useOAuthPopup`)
- [ ] 已綁 → 顯示「解綁」按鈕 → 呼叫 `DELETE /me/sns/{provider}`,擋 last login method 的情境用紅色提示

### T7. `/me/destroy` 註銷(30 分)

- [ ] 在 `/me` 頁底部加「註銷帳號」按鈕(紅色 danger 風格)
- [ ] Modal:輸入 email 二次確認、警語
- [ ] 呼叫 `DELETE /api/v1/me`
- [ ] 成功後清 auth store + 跳 `/login` 並 toast「帳號已註銷」

### T8. Dashboard 快速操作解鎖(30 分)

- [ ] Home `/` 的 4 個 tile 改為:
  - 我的資料 → `/me`(原就能點)
  - 編輯資料 → `/me/edit`(解鎖)
  - 更改密碼 → `/me/password`(解鎖)
  - 綁定管理 → `/me/sns`(解鎖)

---

## 🐛 明天開工前先看看有沒有要修的

- 昨天 Mailtrap rate limit 問題 → 已修(register event 先於 notify)
- 昨天發現 SendWebhookJob 沒用 previous_secret 做 rotation 雙驗證 → 記得留 TODO,或後端 T3 時順手補

---

## 🎁 如果還有時間(bonus)

1. **Webhook consumer guide**:寫一份 `scheme/api/webhook_consumer_guide.md`,含 PHP / Node / Python 3 種語言的 HMAC 驗簽範例 + idempotency 實作建議。
2. **Filament 權限雛形**:目前所有 admin 看得到所有頁面,未來要支援 multi-role。
3. **前端 toast 通用化**:`ForgotPassword` / `ResetPassword` / `MeEdit` 都需要成功 toast,抽成共用 composable `useToast`。

---

## ❌ 明天**不要**做的

- SCIM 2.0(Phase 9,太大,現在沒 bandwidth)
- 前端 i18n / dark mode(UX polish,留到 MVP 之後)
- OpenAPI generator(long-term)
- Webhook receiver(對向功能,下週)

---

## 🎯 明天結束後的狀態預期

- 前台會員 24 種情境全部 operational:註冊/驗證/登入/改資料/改密碼/綁解 SNS/註銷
- Webhook 7 個 event 全部接好:`member.{created,updated,deleted,email_verified,logged_in}` + `oauth.{bound,unbound}`
- 兩個 repo 都 clean,只留 `develop + main`
- 測試預計可達 180+ passed(今天 166 + 明天預估 +15~20)

這會是 **ez_crm v1.0 feature-complete 的前夜**。
