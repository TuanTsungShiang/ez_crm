# T6 `/me/sns` 驗收 checklist — 2026-04-24

> T6 前端 commit `bc957bf` on `ez_crm_client@feature/me-sns`
> 本 checklist 目的:全綠即可 merge develop,完成昨天沒走完的驗收

---

## 🛠 環境先決(每次開始測前確認)

- [ ] XAMPP Apache 已啟動 → `curl -sS -o /dev/null -w "%{http_code}\n" http://localhost:8080/api/v1/auth/register/schema` 回 `200`
- [ ] Vite dev server 已啟動 → 瀏覽器開 http://localhost:5173 有畫面
- [ ] Git:在 `ez_crm_client` 目錄,分支為 `feature/me-sns`(clean working tree)

---

## ✅ 情境 1 — Golden path(綁定新 provider)

**帳號**:`zongyongduan23@gmail.com`(DB 狀態:google + github 已綁,email verified)

步驟:
- [ ] 用 Email + 密碼登入,進入 Home
- [ ] Dashboard 第 4 個 tile「🔌 綁定管理」可點(不再 disabled)
- [ ] 點進去 → `/me/sns` 頁面載入
- [ ] 清單顯示 4 個 provider;Google / GitHub = **已綁定**(綠色 badge + 解綁按鈕);LINE / Discord = **未綁定**(灰色 + 綁定按鈕)
- [ ] 點「綁定 LINE」→ popup 開啟 → LINE 授權 → popup 自動關閉
- [ ] 回到 `/me/sns`:LINE 變「已綁定」+ 頂部出現綠色 flash「已綁定 line」
- [ ] 底部「目前綁定 N 個登入方式」數字 = 3

**通過條件**:全部 ✅

---

## ✅ 情境 2 — 解綁非最後一個

**承接情境 1 的狀態**(LINE 剛綁上)

步驟:
- [ ] 在 LINE 那列點「解綁」→ `window.confirm` 彈出「確定要解除綁定 line 嗎?」
- [ ] 按確定 → 畫面刷新,LINE 回到「未綁定」狀態
- [ ] 頂部出現灰色 flash「已解除綁定 line」
- [ ] 底部數字從 3 回到 2

**通過條件**:全部 ✅

---

## ✅ 情境 3 — Last login method 擋下(改為程式檢查 + API 測試,不改 DB)

後端邏輯已由 6 個 `UnbindSnsTest` 覆蓋(昨天 175 tests 全綠)。
這裡只需確認前端的 **amber banner 條件** + **錯誤渲染路徑** 沒壞。

### (a) Amber banner 條件檢查 ✅

**已確認(2026-04-24):**
> 用 verified 帳號進 `/me/sns`,DevTools 跑 `document.querySelector('[class*="amber"]')` → 回 `null`
> 代表 banner 在「不該出現時不會出現」,條件 `bindingCount <= 1 && !emailVerified` 運作正常

### (b) 後端 A012 錯誤路徑檢查(選做,用 Sanctum token 直接打 API)

如果想親眼看「前端渲染 A012 錯誤」長什麼樣,不改 DB 的做法:
1. 開一個匿名視窗登入一個 **只綁 1 個 SNS + email 未驗證** 的測試帳號,或
2. 臨時 tinker 改一個帳號狀態(測完 revert),或
3. 信任後端 test + 前端錯誤 render 共用 `topError` state(情境 1/2 一般錯誤能顯示就代表這條路徑活著)

目前選 **3**(省時、不污染 DB)。

**通過條件**:(a) ✅;(b) 放棄實測,code review 通過即 pass。

---

## ✅ 情境 4 — 偷換帳號防護(選測)

**跳過**:需要兩個 Google 帳號,且前端條件判斷邏輯極簡(一個 if):
```ts
if (data.member.uuid !== expectedMemberUuid) {
  throw new Error(`這個 ${provider} 帳號對應到另一個 ez_crm 會員,無法綁定到目前登入的帳號`)
}
```
Code review 已通過,暫 pass 實機測試。

---

## 🚦 全部通過後的收尾指令

```bash
cd /c/code/ez_crm_client
git checkout develop
git merge --no-ff feature/me-sns -m "Merge branch 'feature/me-sns' into develop"
git push origin develop
git branch -d feature/me-sns
git push origin --delete feature/me-sns
```

然後進 **T5 `/me/password`**。

---

## 📌 驗收時發現的 bug / 待修(測試中隨手記)

<!-- 測試過程若發現任何問題,直接寫在這下面 -->

-
