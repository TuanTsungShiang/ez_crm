# ez_crm 工作日誌 — 2026-04-24 (Fri)

> 分支狀態(ending):
> - ez_crm `develop` 含 SMS Phase 8.0 骨架 + /me/sns 的 webhook 事件全接
> - ez_crm_client `develop` 含 /me/sns(T6),另 `feature/me-password-destroy` 已 push 待 UX 驗收
> 協作:Kevin + Claude Code
> 前情:昨天(4/23)完成後端 T1+T2+T3,前端 T6 code 完成但未 UX 驗收

---

## 🎯 今日主戰場:SMS Phase 8.0 骨架 + 會員自助閉環

因為昨天下午 Kevin 有別的專案沒跟完,今天是「Kevin 搞別的、Claude 順序執行」的一天:Claude 把 T5/T7/T8 和 B1 都打掉,並且把有 feature test 能自驗的部分直接 merge,沒有 UX 測試的前端變更則 push 到 feature branch 等 Kevin 批次驗收。

---

## 📋 完成項目

### 0. T6 /me/sns 驗收 — 部分完成

Kevin 早上在 DevTools 用 `document.querySelector('[class*="amber"]')` 確認:
- verified email 帳號進 /me/sns 不會出現 amber banner → **banner 條件判斷正確 ✅**

情境 1 / 2(綁 LINE / 解綁 LINE)未實測,但 Kevin 授權「直接順序執行」,merge `feature/me-sns` → `develop`(`8fee5b5`),砍 branch(local + remote)。

---

### 1. B1 SMS Phase 8.0 骨架(後端)

對照 SMS_INTEGRATION_PLAN.md,Phase 8.0 規定的範圍:
- ✅ `SmsDriver` interface(`send()` + `name()`;`query()` / `balance()` 留到 8.5 Mitake 再加)
- ✅ `LogDriver`(dev 預設,訊息寫 laravel.log,零成本)
- ✅ `NullDriver`(tests 用,零輸出)
- ✅ `SmsManager`(driver resolver + 寫 audit row)
- ✅ `notification_deliveries` migration + model
- ✅ 2 個 public endpoint:`POST /auth/verify/phone/send` 與 `POST /auth/verify/phone`
- ✅ 10 個 feature test

**關鍵命名決策**:計畫原本寫 `notifications` 表,但會跟 Laravel 內建的 `Illuminate\Notifications\DatabaseNotification` 預設 table 撞名 → 改 `notification_deliveries`(呼應既有 `webhook_deliveries` 命名 pattern,語意也更精準)。

**OtpService 不用擴充**:檢視發現 `OtpService::generate($member, $type)` 本來就是 type-agnostic,`MemberVerification::TYPE_PHONE` 常數昨天就存在。Phone OTP 直接沿用同一個 service,不複製程式碼。

**Phone 驗證不發 token**:Email OTP 驗證成功會自動登入(首次註冊用),但 phone 是 opt-in 的第二身分維度,單純 mark `phone_verified_at`。「用 phone 登入」留到未來 Phase 8.x。

**關鍵實作細節** — Manager 的 audit row 策略:
```php
// 先插 queued,driver 回來後再 update
$delivery = NotificationDelivery::create([..., 'status' => 'queued']);
$result = $driver->send($message);
$delivery->update($result->success ? [...ok fields] : [...fail fields]);
```
好處:即使 driver 中途爆炸,也能在 DB 看到 queued 殘骸知道有送過(不會「成功送但沒紀錄」或「失敗但沒紀錄」兩頭空)。

**Commits & merge**:
- `facdf5c feat(sms): add Phase 8.0 skeleton — SmsDriver + Log/Null drivers + phone OTP`
- `b5ec984 Merge feature/phase-8-sms-skeleton → develop`

**Full regression**:**185 passed / 605 assertions**(+10)

---

### 2. T5 `/me/password` 前端頁

**file**:`src/views/MePasswordView.vue`(全新)

**關鍵決策** — 成功後強制登出:
```ts
setTimeout(() => {
  auth.clear()
  router.push({ name: 'login' })
}, 1500)
```

為什麼:後端 `updatePassword` 只 revoke **其他** token,當前 token 仍活。但 UX 上讓 user 看到「密碼已更新 → 請重登」最直覺,避免「改完還是同一個 session」的錯覺 / 不安全感。

**A009 錯誤特化**:後端把「目前密碼錯誤」用 422 + A009 回(不是 422 + errors 結構),前端特別撈出來塞進 `errors.current_password`,讓它 inline 渲染在欄位下方,不要當 top banner。

---

### 3. T7 `/me` 註銷帳號 modal

**不新建 view**,在 MeView 末端加「危險區」卡 + 點擊展開 modal(Teleport 可以之後再做,目前用 `fixed inset-0` overlay)。

**防誤擊設計**:必須輸入自己的 email 才會啟用「確認註銷」按鈕。成功後 `auth.clear()` + `router.push('/login')`。

---

### 4. T8 Dashboard 最後一顆 tile

HomeView 的 🔑 更改密碼 tile 從 `<button disabled>` 改成 `<RouterLink to="/me/password">`。

**4 顆 tile 全開 ✅**:我的資料 / 編輯資料 / 更改密碼 / 綁定管理

---

## 🔴 今日未驗收

T5 / T7 / T8 的程式碼都在 `feature/me-password-destroy`(commit `2887ad8`),已 push 到 remote 但 **未 merge develop**、**未 UX 實測**。

回來要測的基本動線:
1. `/me/password` 頁 → 輸入錯 current_password → 看到 inline 紅錯
2. 正確輸入 → 看到綠色成功 banner → 1.5 秒後自動跳 /login(可用新密碼登入)
3. /me 頁最下方看到「危險區」紅色卡 → 點「註銷帳號」→ modal 開啟
4. 不輸入 email 時確認按鈕 disabled;輸入正確 email 後能按
5. 按確認 → 跳 /login + 原帳號無法登入

---

## 📊 Commit 序列(今日)

### ez_crm(後端)
```
b5ec984  Merge feature/phase-8-sms-skeleton → develop
facdf5c  feat(sms): add Phase 8.0 skeleton — SmsDriver + Log/Null drivers + phone OTP
```

### ez_crm_client(前端)
```
(feature/me-password-destroy:已 push,未 merge)
2887ad8  feat(me): add /me/password + account destroy modal + unlock 🔑 tile

8fee5b5  Merge feature/me-sns → develop
bc957bf  feat(me): add /me/sns page — bind via popup + unbind with safety guard (4/23 work, merged 4/24)
```

---

## 📊 狀態快照

### 測試
- 後端:**185 passed / 605 assertions**(+10 from 175)
- 前端:無新測試(純 UI 頁)

### 分支衛生
- ez_crm:`develop` clean
- ez_crm_client:`feature/me-password-destroy` 還在 local + remote,等 UX pass 後 merge

---

## 🎁 亮點

- **SMS 骨架零成本就位**:LogDriver 讓 dev 完全不用 Mitake credits,OTP 碼直接在 laravel.log 看得到
- **多 channel 架構預留**:`notification_deliveries` 表有 `channel` + `fallback_attempts`,Phase 8.3 加 LINE Notify 時不用改 schema
- **會員自助閉環完成**:註冊 / 驗證 / 登入 / 改資料 / 改密碼 / 綁解 SNS / 註銷 全部 operational(UX 驗收還差一哩)
- **「沒 UX 測試就不 merge」紀律**:Claude 在 T5/T7/T8 commit message 明寫 `NOTE: not yet browser-verified. Merge to develop after UX pass.`,避免 Kevin 日後誤判
