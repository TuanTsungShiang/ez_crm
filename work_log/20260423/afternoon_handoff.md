# 2026-04-23 (Thu) — 交接紀錄(午後離線)

> 紀錄時間:2026-04-23 下午 ~13:10
> 目的:Kevin 臨時要離開,這份文件讓回來時 3 分鐘內能續航
> 協作:Kevin + Claude Code

---

## 🎯 回來後 **第一件事** 做這個

T6 前端程式碼已寫完,dev server 還開著(`http://localhost:5173`),但**還沒手動測、沒 commit**。
打開瀏覽器照下面 4 個情境走一輪,都綠了就 commit + merge develop,再接 T5。

```
# dev server 若已被殺,重開:
cd /c/code/ez_crm_client
npm run dev
```

### 要測的 4 個情境(from Claude 的交接訊息)

1. **Golden path**:Dashboard → 🔌 綁定管理 → 綁一個新 provider(例如已用 email 註冊的帳號,綁 Google)→ 看 list 刷新 + 綠色 flash
2. **Unbind 非最後一個**:解綁剛綁的,看 list 掉一個 + 灰色 flash
3. **Last login method 擋下**(需 email 未驗證的 SNS-only 帳號測):最後一個 SNS + email 未驗證的狀態,解綁 → 應該看到 A012 紅色錯誤訊息
4. **偷換帳號防護**(選測):用 A 帳號登入,點綁定 Google,在 popup 用 B 帳號的 Google 登入 → 應看到紅色錯誤「這個 google 帳號對應到另一個 ez_crm 會員」

都 OK 的收尾指令模板:

```bash
cd /c/code/ez_crm_client
git add src/api/me.ts src/composables/useOAuthPopup.ts src/views/MeSnsView.vue src/router/index.ts src/views/HomeView.vue src/views/MeView.vue
git commit -m "feat(me): add /me/sns page — bind via popup + unbind with safety guard"
git checkout develop
git merge --no-ff feature/me-sns -m "Merge branch 'feature/me-sns' into develop"
git branch -d feature/me-sns
git push origin develop
```

---

## ✅ 今天上午到午後已完成

### 後端 — feature/me-webhook-events(已 merged + pushed)

| Task | 狀態 | 備註 |
|---|---|---|
| T1 MemberUpdated event | ✅ | diff 透過 `fill → getDirty → getOriginal` 在 `save()` 之前抓;無變動時不發事件 |
| T2 MemberDeleted event | ✅ | 從 `/me` DELETE 觸發;用 in-memory `$member`(不能 `fresh()`,SoftDeletes scope 會擋) |
| T3 OAuthUnbound + `DELETE /me/sns/{provider}` | ✅ | 「last login method」守門:若是唯一 SNS 且 email 未驗證 → A012 / 409 擋下 |

- ez_crm `develop` 最新 commit:`3cdf879` Merge feature/me-webhook-events
- feature branch 已砍(local + 沒推 remote)
- 新增 6 tests(UnbindSnsTest)+ 3 tests(DispatchWebhookTest)
- **Full regression: 175 passed / 572 assertions**(上一次是 166)
- EventServiceProvider 順便清掉一個 unused `use Illuminate\Support\Facades\Event;`

### 前端 — feature/me-sns(程式碼完成,**未 commit**)

T6 `/me/sns` 頁已實作完成,尚未 commit。目前在 `feature/me-sns` branch,working tree 有 5 個 modified + 1 個新檔:

```
modified:   src/api/me.ts                    (新增 unbindSns)
modified:   src/composables/useOAuthPopup.ts (抽 openOAuthPopup + 新增 bind())
modified:   src/router/index.ts              (加 /me/sns route)
modified:   src/views/HomeView.vue           (🔌 綁定管理 tile 解鎖)
modified:   src/views/MeView.vue             (已綁定登入方式區塊加「管理 →」連結)
new file:   src/views/MeSnsView.vue          (主頁面)
```

#### 關鍵設計決策(留給 code review 時回想)

1. **綁錯帳號防護**(`useOAuthPopup::bind`)
   已登入者點「綁定 Google」,popup 回來若 `member.uuid !== 當前 uuid`,throw,不碰 auth store。
   這發生在 Google 帳號的 email 對應到**另一個 ez_crm 帳號**的情況 — 後端 callback 會去綁對方然後發對方的 token。前端要擋掉,不然會被「切換成另一個人」。

2. **Last login method 雙層保險**
   - UI:若唯一 SNS + email 未驗證,畫面最上方顯示 amber banner + 解綁前 `confirm()` 警告
   - API:後端仍會以 A012 擋下;前端只是友善前置提醒

3. **Refactor `useOAuthPopup`**:抽 `openOAuthPopup()` 共用 popup + postMessage 邏輯,`login` / `bind` 都用它但對 auth store 行為不同。

### 順手修的

- Dashboard 第 4 個 tile(🔌 綁定管理)之前是 disabled,現在連到 `/me/sns`
- MeView 的「已綁定登入方式」卡片右上加「管理 →」快捷連結

---

## 📊 狀態快照

### 兩個 repo 的 commit 序列(今天)

#### ez_crm(後端)
```
3cdf879  Merge feature/me-webhook-events into develop
9d9e96b  feat(webhooks): add MemberUpdated / MemberDeleted / OAuthUnbound events
```

#### ez_crm_client(前端)
```
(feature/me-sns 尚未 commit)
c4f3eff  Merge feature/me-edit: /me/edit page + Dashboard tile unlock   ← develop HEAD
```

### 測試狀態

- 後端:**175 passed / 572 assertions**(+9 from 166)
- 前端:Vue/TS typecheck 有個 pre-existing `baseUrl deprecated` warning(不是我引入的,不阻擋 dev / build)

### 分支衛生

- ez_crm:`develop` clean,no feature branch
- ez_crm_client:**`feature/me-sns` 還在**(上面有 T6 的 uncommitted 變更)

---

## 📋 今日計畫剩下 — 依 morning_plan.md 的打勾狀況

- [x] T1 MemberUpdated
- [x] T2 MemberDeleted
- [x] T3 OAuthUnbound + DELETE /me/sns
- [~] T4 `/me/edit` ← 昨天就做完了,今天不用動
- [ ] T5 `/me/password`(45 分)
- [x] T6 `/me/sns`(實作完,**待測 + commit + merge**)
- [ ] T7 `/me/destroy`(30 分)
- [x] T8 Dashboard 解鎖(🔌 那顆已經在 T6 順手開了;其餘 3 顆要等 T5/T7 做完)
- [ ] B1 SMS Phase 8.0 骨架(1.5-2h bonus)

### 推薦的回來續航順序

1. **T6 驗收 + 收尾**(~15 分)— 上面 4 個情境測一輪 + commit + merge
2. **T5 `/me/password`**(~45 分)— 直接用 PUT `/api/v1/me/password`,後端已存在,記得成功後要清 auth store 跳 `/login`(因為 revoke 了其他 token,當前 token 其實會被保留但 UX 上讓 user 重登比較乾淨)
3. **T7 `/me/destroy`**(~30 分)— modal 二次確認 + DELETE `/api/v1/me`
4. **T8 最後一顆 tile**:Dashboard 把「🔑 更改密碼」從 disabled 放開 → `/me/password`
5. **Session log 收工**:寫 `scheme/improvement_plan/2026/04/SESSION_LOG_2026_04_23.md`

如果 T5+T7 做完還有時間 → B1 SMS 骨架(但那個建議留完整時段,1.5h 起跳)。

---

## 🧠 回來前值得複習的 3 件事

1. **T6 綁定的「切換成另一個人」陷阱** — 看上面「綁錯帳號防護」那段。如果 Kevin 回來想改成「擋住但**不吃 token**」,要檢查 `openOAuthPopup` 是否需要在 bind 流程裡阻止 backend 發 token(目前是發了但前端不寫 store;更嚴謹的話後端 callback 要多一個 `?mode=bind&expected_uuid=...` query,但今天不動)。

2. **`hasVerifiedEmail()` 判斷的實際行為** — MeController@unbindSns 用 `! $member->hasVerifiedEmail()` 判定是否擋。這是 Laravel `MustVerifyEmail` trait 的標準方法,看 `email_verified_at` 是否 not null。OAuth-only 帳號(例如 Discord 不回 email 的情境)沒有 email_verified_at,會被視為「未驗證」→ 擋住解綁最後 SNS,符合預期。

3. **後端跟 Filament 選項** — morning_plan.md 有提到 `WebhookSubscriptionResource::availableEvents()` 要加 3 個新 event 選項,我今天**沒有動**。檢查 Filament 的訂閱建立表單,看 `oauth.unbound` / `member.updated` / `member.deleted` 是否出現在 checkbox。若沒有,補一下 `app/Filament/Resources/WebhookSubscriptionResource.php`。**這是我今天漏掉的小尾巴**。

---

## 📝 Kevin 回來時的 prompt 範本(複製即可)

```
早安 / 午安,我回來了,先對錶:
- 讀 work_log/20260423/afternoon_handoff.md
- 確認 feature/me-sns 還在 + dev server 還活著
- 走完 T6 的 4 個測試情境
- 全綠就 commit + merge + 清分支
- 然後接 T5 /me/password
```
