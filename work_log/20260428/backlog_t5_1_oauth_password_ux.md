# Backlog T5.1 — `/me/password` 對 OAuth-only 用戶友善化

> 建立日期:2026-04-28
> 狀態:📋 backlog(尚未排程)
> 估時:後端 1–2 h + 前端 1–2 h ≈ 半天
> 優先級:中(UX 漏洞,但不阻擋 happy path;有逃生路徑)
> 觸發者:Kevin 在 T5 UX 驗收時提問「OAuth 註冊的帳號要怎麼改密碼?」

---

## 一、問題

OAuth-only 註冊的會員(沒走過 `/register` + email OTP 流程的)進 `/me/password`,**永遠卡在「目前密碼錯誤」**,因為他根本沒設過密碼。

## 二、根因

OAuth 註冊流程裡後端硬塞 60 字元亂碼當 placeholder 密碼:

[app/Services/OAuth/OAuthService.php:86](../../app/Services/OAuth/OAuthService.php#L86)
```php
'password' => Str::random(60), // 隨機佔位,要改需走忘記密碼
```

對應 `/me/password` 後端的驗證:

[app/Http/Controllers/Api/V1/Me/MeController.php:125](../../app/Http/Controllers/Api/V1/Me/MeController.php#L125)
```php
if (! Hash::check($request->current_password, $member->password)) {
    return $this->error(ApiCode::INVALID_CREDENTIALS, '目前密碼錯誤', 422);
}
```

→ OAuth-only 用戶不知道 random 60 字元是什麼,輸入任何 current_password 都會被打回。

## 三、影響範圍

- **誰受影響**:全部走 OAuth Phase 4(Google / GitHub / LINE / Discord)註冊的會員,且**未透過 forgot-password 流程設過密碼**的
- **症狀**:點 dashboard「🔑 更改密碼」tile → 進 `/me/password` → 試任何密碼都「目前密碼錯誤」
- **業務風險**:用戶覺得自己的帳號壞了 / 不能改密碼 / 無法強化帳號安全

## 四、既有逃生路徑(目前的「正解」)

走 `/forgot-password` → 收 reset email → `/reset-password` 設新密碼。
此路徑**不需要 current_password**,所以 OAuth 用戶能用這條路第一次設自己的密碼。

**前提**:OAuth 拿到的 email 必須是 verified(`OAuthService::createMemberFromOAuth` 邏輯:有真實 email → 預設視同 verified;LINE/Discord 沒給 email 時用 placeholder `provider_id@oauth.local` → 未驗證 → 此路徑斷掉)。

## 五、建議解法(三選一,由輕到重)

### 方案 A — 純前端引導(最輕,1h)

在 `/me/password` 頁掛一個小 banner / 提示文字:
> 「如果你是用 Google / GitHub / LINE / Discord 註冊的,你的密碼是隨機產生,請改走 [忘記密碼] 流程」

**優點**:零後端改動。
**缺點**:不夠精準(密碼註冊的用戶也會看到這句廢話)。

### 方案 B — 後端 flag + 前端分流(中等,半天)⭐ 推薦

1. 後端 Member 加欄位 `password_set_at` (timestamp, nullable)
   - `/register` + `/reset-password` + `/me/password` 成功時更新此欄位
   - OAuth 註冊不寫(代表 password 是 random placeholder)
2. `GET /me` response 加上 `has_local_password: bool`(計算欄位:`password_set_at !== null`)
3. 前端 `/me/password` 進入時先檢查:
   - `has_local_password === false` → 換成「設定密碼」UI(只要 new + confirm,不要 current),POST 到一個新 endpoint `/me/password/set`(僅在 has_local_password=false 時可用,避免 reset path)
   - `has_local_password === true` → 維持現狀

**優點**:UX 最乾淨,不混到既有 reset 流程。
**缺點**:多一個 endpoint + migration。

### 方案 C — 統一走 reset-password(後端零改動,2h)

`/me/password` 偵測 OAuth-only 用戶 → 自動寄 reset email → 跳「請查收信件」頁。
但前端怎麼判定 OAuth-only?還是要靠後端給 flag → 等同方案 B 的步驟 1+2,只是 UX 統一走 reset。

**優點**:不用新 endpoint。
**缺點**:UX 上被導去 email 點 link 很跳,不如方案 B 的 inline「設定密碼」直覺。

## 六、決定 / 暫緩理由

- **暫緩到 Phase 1 RBAC 之後**(預計 2026-05-07 後)
- 不阻擋目前所有 happy path 驗收(已通過 ✅)
- 有逃生路徑可用(forgot-password)
- Phase 1 RBAC 是面試標準的硬要求,優先級更高

## 七、做這個之前的依賴

無。可以獨立進行,不卡別的任務。

## 八、相關 commit / 檔案

- OAuth 占位密碼產生:`app/Services/OAuth/OAuthService.php` 由 `9926941` 引入
- `/me/password` 後端:`app/Http/Controllers/Api/V1/Me/MeController.php` 由 `a438d76 feat(auth): Phase 5 (cont.)` 引入
- `/me/password` 前端:`src/views/MePasswordView.vue` 由 `2887ad8` 引入(今天剛 merge `c3e8e54`)
