# ez_crm 工作日誌 — 2026-04-23 (Thu)

> 分支狀態(ending):
> - ez_crm `develop` = `3cdf879`(feature/me-webhook-events merged);兩份 handoff 文件稍後隔天 4/24 早上才寫
> - ez_crm_client `feature/me-sns` = `bc957bf`(push 到 remote,未 merge develop)
> 協作:Kevin + Claude Code
> 前情:昨天(4/22)完成 Webhook Phase 1+2 + Filament admin + 後端 4 個 event 上線

---

## 🎯 今日主戰場:Webhook 事件全員到齊 + 前端 `/me/sns`

對照 morning_plan.md 的計畫,今天**上午衝後端 T1+T2+T3 事件**、**午後起前端 T6**,但因為 Kevin 下午臨時有別的專案,T6 code 完成後沒來得及瀏覽器驗收就下線。

---

## 📋 完成項目

### T1 MemberUpdated webhook event

**file**:`app/Events/Webhooks/MemberUpdated.php`
**觸發點**:`MeController@update`

關鍵實作決策 — **diff 在 `save()` 之前抓**:

```php
$member->fill($request->validated());
$changes = [];
foreach ($member->getDirty() as $field => $newValue) {
    $changes[$field] = [
        'from' => $member->getOriginal($field),
        'to'   => $newValue,
    ];
}
$member->save();

if (! empty($changes)) {
    event(new MemberUpdated($member->fresh(), $changes));
}
```

**為什麼這樣寫**:
- `fill` 後 `getDirty()` 拿到的是 pending changes;`getOriginal()` 還是原值
- `save()` 之後 `getDirty()` 會變空,`getOriginal()` 會變新值 → 拿不到 diff
- 「送一樣的值」不發事件,避免下游收一堆 no-op

payload 結構:`{ event, occurred_at, data: { uuid, email, changes: {field: {from, to}} } }`

### T2 MemberDeleted webhook event

**file**:`app/Events/Webhooks/MemberDeleted.php`
**觸發點**:`MeController@destroy`(軟刪除)

關鍵**陷阱 & 解法**:

```php
$member = $request->user();
$member->tokens()->delete();
$member->delete();  // soft delete, deleted_at 會寫到 $member 記憶體

event(new MemberDeleted($member)); // ← 用 $member, NOT $member->fresh()
```

- `fresh()` 會再查一次 DB,但 Member 有 SoftDeletes trait → 預設 scope 濾掉 deleted_at → 查不到 → `null`
- 直接用 in-memory `$member`,deleted_at 已經在這個 instance 上了
- payload 帶 `{ uuid, email, name, deleted_at }` snapshot,下游用來清自己的紀錄、寄告別信、停訂閱等

### T3 OAuthUnbound + `DELETE /me/sns/{provider}`

**files**:
- `app/Events/Webhooks/OAuthUnbound.php`
- `MeController@unbindSns` 方法
- `routes/api.php` 新 route
- 6 新 tests(`tests/Feature/Api/V1/Me/UnbindSnsTest.php`)

**「Last login method」守門邏輯**(最重要的設計決策):

```php
$remainingSnsCount = $member->sns()->where('id', '!=', $sns->id)->count();
if ($remainingSnsCount === 0 && ! $member->hasVerifiedEmail()) {
    return $this->error(ApiCode::LAST_LOGIN_METHOD, '...', 409, ['provider' => [$provider]]);
}
```

為什麼這樣規:
- 使用者如果只綁 1 個 SNS、又沒驗證過 email(只能靠這個 SNS 登入)→ 解掉就鎖在外面
- 有驗證 email → 可以走忘記密碼救回 → 放行
- 純 password 帳號(沒綁任何 SNS)→ 根本沒這個路徑,不會碰到

對應新 ApiCode:`LAST_LOGIN_METHOD` = `A012`

**payload**:`{ member_uuid, member_email, provider, provider_user_id }` — `provider_user_id` 在 `$sns->delete()` 之前就抓出來,下游可以反查自己資料庫裡該 OAuth user 的記錄去清除。

### EventServiceProvider + Filament

EventServiceProvider 3 個新 event 都接上 `DispatchWebhook` listener;順便清掉未使用的 `use Illuminate\Support\Facades\Event;`。

Filament `WebhookSubscriptionResource::availableEvents()` 預先就已經有 7 個 event 選項(包含這 3 個新的),這部份不用改動。

**Full regression**:175 passed / 572 assertions(較前日 166 增 9)

Commit:
- `9d9e96b feat(webhooks): add MemberUpdated / MemberDeleted / OAuthUnbound events`
- `3cdf879 Merge feature/me-webhook-events → develop`

---

### T6 前端 `/me/sns`(程式碼完成,未 UX 驗收)

**files**:
- `src/views/MeSnsView.vue`(全新)
- `src/composables/useOAuthPopup.ts`(refactor:抽 `openOAuthPopup`,加 `bind()`)
- `src/api/me.ts`(加 `unbindSns`)
- `src/router/index.ts`(加 `/me/sns` route)
- `src/views/HomeView.vue`(🔌 tile 解鎖)
- `src/views/MeView.vue`(加「管理 →」捷徑)

**兩個關鍵設計決策**:

**(1) 綁錯帳號防護**:已登入者點「綁定 Google」的 popup 回來時,驗證 `data.member.uuid === 當前 uuid`,不一致就 throw(代表那個 Google 帳號的 email 對應到別人的 ez_crm 會員,OAuthController callback 會發對方的 token,前端要擋下以免把自己「切換」成另一個人)。

```ts
if (data.member.uuid !== expectedMemberUuid) {
  throw new Error(`這個 ${provider} 帳號對應到另一個 ez_crm 會員...`)
}
```

**(2) Last login method 雙層保險**:
- UI:`bindingCount <= 1 && !emailVerified` 時顯示 amber banner,解綁前 `confirm()` 警示
- API:後端仍以 A012 擋下;前端只是友善前置提醒

**`useOAuthPopup` refactor**:抽出 `openOAuthPopup()` 共用 popup + postMessage + closed-poll 邏輯,`login()` 跟 `bind()` 呼叫但對 auth store 行為不同(login 一律寫入;bind 驗證 uuid 再寫入)。

Commit:`bc957bf feat(me): add /me/sns page — bind via popup + unbind with safety guard`(推到 `origin/feature/me-sns`)

---

## 🔴 今日**沒**走完的

- T6 **沒有瀏覽器實測**。4 個情境(綁 / 解綁非 last / last-login-method 擋下 / 偷換帳號防護)延到 4/24 早上對錶時驗收。
- T5 `/me/password` / T7 `/me/destroy` / T8 Dashboard 剩的 tile 都沒動到(原計畫下午 4 小時的工作量)。
- 昨天(4/22)的 session log 因時間關係沒寫;今日這份隔日補。

---

## 📝 午後 handoff 機制

下班前 Claude 留了 2 份 handoff 文件,目的是讓 Kevin 回來 3 分鐘能進狀態:

- [work_log/20260423/afternoon_handoff.md](../../../work_log/20260423/afternoon_handoff.md) — 狀態快照 + 回來第一件事 + copy-paste 收尾指令
- handoff 更新版:在 T6 push 到 remote 後同步改寫「回來第一件事」

Kevin 離開前:
1. 後端 T1+T2+T3 已 commit + merge + push
2. 前端 T6 已 commit + push(on `feature/me-sns`,未 merge)
3. Handoff 文件 push 到 ez_crm develop

隔天 4/24 早上對錶,狀態跟 handoff 完全一致,無漂移。

---

## 📊 Commit 序列(今日)

### ez_crm(後端)
```
b0993a4  docs(work_log): sync handoff after T6 pushed to feature/me-sns
e5b0a50  docs(work_log): add 2026-04-23 afternoon handoff
3cdf879  Merge feature/me-webhook-events → develop
9d9e96b  feat(webhooks): add MemberUpdated / MemberDeleted / OAuthUnbound events
```

### ez_crm_client(前端)
```
bc957bf  feat(me): add /me/sns page — bind via popup + unbind with safety guard  ← feature/me-sns
(develop 沒動,還在 c4f3eff)
```

---

## 🎯 測試狀態

- 後端:**175 passed / 572 assertions**(+9)
- 前端:`baseUrl deprecated` pre-existing warning,無新問題

---

## 🎁 亮點(收進履歷 / 週報)

- **事件系統全員到齊**:member.* 5 種 + oauth.* 2 種,下游要接 CRM / 行銷自動化不再缺事件
- **「鎖在外面」守門設計** 做成明確的 A012 ApiCode,前後端雙層保護,可直接上 production
- **async-safe diff 抓取**:`getDirty()` + `getOriginal()` 的 timing 是 Laravel 經典坑,今天收進架構
- **Handoff 機制驗證成功**:午後臨時下線,隔天早上無需重新 on-board,3 分鐘接回
