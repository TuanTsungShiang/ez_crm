# 2026-04-24 (Fri) — 交接紀錄(今日收尾前)

> 紀錄時間:2026-04-24 下午(Kevin 在忙別的專案,Claude 順序執行完 T6/T5/T7/T8/B1 後寫這份)
> 目的:Kevin 結束今日別的專案後,需要一份對錶文件快速接回

---

## 🎯 回來後 **第一件事**

**T5/T7/T8 UX 驗收** — commit `2887ad8` on `ez_crm_client@feature/me-password-destroy`(已 push remote,未 merge develop)。

### 測試動線

```
# 確保兩邊都起來:
cd /c/code/ez_crm_client && git status    # 應該在 feature/me-password-destroy
npm run dev                                # http://localhost:5173
# 後端 XAMPP Apache + MySQL
```

登入 `zongyongduan23@gmail.com`(或任何 verified 帳號):

**T5 /me/password**
1. Home Dashboard 第 3 顆「🔑 更改密碼」tile 可點,不再 disabled
2. `/me/password` 頁:
   - 填錯 current_password → 在該欄位下方看到 **inline 紅字**「目前密碼錯誤」(不是 top banner)
   - 填正確 + new 密碼 + 確認不一致 → 「確認新密碼」欄位下紅字「兩次輸入不一致」
   - 全對 → 綠色大 banner「密碼已更新 🔐」 → 1.5 秒後跳 `/login`
   - 用新密碼登入成功
3. 其他裝置(開匿名視窗已登入)的 token 應該失效(可選測)

**T7 註銷帳號**
4. `/me` 頁底下有紅色「危險操作」卡,按「註銷帳號…」→ modal 開啟
5. 不輸入 email 時「確認註銷」disabled;輸入**正確** email 後按鈕啟用
6. 按確認 → 跳 /login,**用同一個 email/password 登入應該失敗(帳號已軟刪除)**

**T8**
7. Dashboard 4 顆 tile 全都可點

### 通過後收尾指令

```bash
cd /c/code/ez_crm_client
git checkout develop
git merge --no-ff feature/me-password-destroy -m "Merge branch 'feature/me-password-destroy' into develop"
git push origin develop
git branch -d feature/me-password-destroy
git push origin --delete feature/me-password-destroy
```

---

## ✅ 今日已自動完成的(Claude 代為)

### 後端(有 feature test 自驗,已 merge develop)

1. **T6 前端 merge**:4/23 留下的 `feature/me-sns` merge 到 client develop(`8fee5b5`),情境 1/2 沒實測。驗收時順便巡一下 /me/sns 看 LINE/Discord 綁定還能走。
2. **B1 SMS Phase 8.0 骨架**(`b5ec984`):
   - SmsDriver interface + LogDriver + NullDriver + SmsManager
   - notification_deliveries table(不叫 notifications 避開 Laravel 內建衝突)
   - 2 個 public endpoint + 10 個 feature test
   - Full regression **185 passed / 605 assertions**

### 前端(未 UX 驗收,push 到 feature branch)

3. **T5 /me/password** + **T7 /me 註銷 modal** + **T8 🔑 tile 解鎖** 一起在 `feature/me-password-destroy` → `2887ad8`

### 文件

4. [SESSION_LOG_2026_04_23.md](../../scheme/improvement_plan/2026/04/SESSION_LOG_2026_04_23.md) — 補寫昨天的
5. [SESSION_LOG_2026_04_24.md](../../scheme/improvement_plan/2026/04/SESSION_LOG_2026_04_24.md) — 今天的
6. [t6_verification_checklist.md](t6_verification_checklist.md) — 早上寫的 T6 驗收清單

---

## 📊 狀態快照

### 分支衛生
| Repo | develop HEAD | Open feature branches |
|---|---|---|
| ez_crm | `b5ec984` SMS skeleton merge | — |
| ez_crm_client | `8fee5b5` T6 merge | **`feature/me-password-destroy`**(待 UX merge) |

### 測試
- 後端:185 passed / 605 assertions(今天 +10)
- 前端:無自動測試

---

## 🔜 下一步候選(回來時決定)

1. **收尾 T5/T7/T8 驗收 + merge**(~15 分)— 最優先
2. **SMS LogDriver 手動驗證**:登入 `zongyongduan23@gmail.com`,打 `POST /api/v1/auth/verify/phone/send` with phone,去 `storage/logs/laravel.log` 看 `[SMS:log] delivered` 是否出現、拿到 OTP code、再 POST /auth/verify/phone 驗掉
3. **加個 `/me/phone` 驗證頁**(Phase 8.1 的前端):`/me/edit` 存完 phone 後,有個 badge 顯示「未驗證 → 點此驗證」
4. **Filament 補 NotificationDeliveryResource**:後台看 SMS 發送紀錄(成本追蹤 / debug)
5. **Phone OTP 併進 `/me/edit` 流程**:改 phone 時跳 OTP 驗證

---

## 📝 Kevin 回來時 prompt 範本

```
對錶:讀 work_log/20260424/evening_handoff.md + git status 兩邊 repo
走完 T5/T7/T8 UX 驗收 → merge develop → 清分支
順便試玩一下 phone OTP:laravel.log 應該能看到驗證碼
```
