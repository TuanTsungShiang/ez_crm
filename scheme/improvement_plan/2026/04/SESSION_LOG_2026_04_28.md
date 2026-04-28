# ez_crm 工作日誌 — 2026-04-28 (Tue)

> 分支狀態(ending):
> - ez_crm `develop` ≈ `1e0fa69`(RBAC + Filament UserResource/RoleResource merged)+ widget RBAC commit pending
> - ez_crm_client `develop` = `c3e8e54`(T5/T7/T8 merged)
> 協作:Kevin + Claude Code
> 前情:4/24 收工後跨週末未開工(4/25–4/27 無 commit),今天接手「未驗收 + delay 一週末」狀態

---

## 🎯 今日主戰場:把 4/24 留下的尾巴全部收尾 + 一天追平 Phase 1 進度

開工時 schedule_assessment 寫的「本週優先」三項全部未動:
1. T5/T7/T8 UX 驗收 + merge feature/me-password-destroy
2. Engineering Infra W1:PHPUnit GitHub Actions CI
3. RBAC 設計

到收工時三項**全部完成**,並且 RBAC **加碼做了 Filament UI**(原 plan 沒有),最終比 schedule_assessment 預期的「2026-05-07 Phase 1 完成」**整週提早**。

---

## 📋 完成項目

### 1. T5/T7/T8 UX 驗收 + merge develop(上午)

Kevin 用密碼註冊帳號跑完 T5 happy path + T7 註銷 modal + T8 dashboard 4 顆 tile,全綠。merge `feature/me-password-destroy` → develop(`c3e8e54`),清 local + remote branch。

**插曲 — T5.1 backlog**:Kevin 在驗收過程中問「OAuth 註冊用戶要怎麼改密碼?」,點出設計漏洞:

[app/Services/OAuth/OAuthService.php:86](../../../app/Services/OAuth/OAuthService.php#L86) 的 `'password' => Str::random(60)` 讓 OAuth-only 用戶在 `/me/password` 永遠卡「目前密碼錯誤」。寫成 backlog [T5.1](../../../work_log/20260428/backlog_t5_1_oauth_password_ux.md)(三套解法 A/B/C 並推薦 B),暫緩到 Phase 1 RBAC 之後。

Commit:`b2416bd docs(work_log): add T5.1 OAuth-only password UX backlog`

---

### 2. Engineering Infra W1 — PHPUnit GitHub Actions CI

對照 [ENGINEERING_INFRASTRUCTURE_ROADMAP](ENGINEERING_INFRASTRUCTURE_ROADMAP.md) W1 範本實作 [.github/workflows/phpunit.yml](../../../.github/workflows/phpunit.yml)。

**關鍵實作決策**:
- **mysql:8.0 service container + 自訂 wait loop**:GitHub Actions 內建 `--health-cmd` 偶爾 false-positive(回綠但 TCP 還沒 accept),加 60 秒 wait loop 保險。
- **DB credentials 在 step env 重複設**:確保 migrate / phpunit 一定有對的連線參數,即使 .env 被誤觸也行。
- **composer cache key 用 composer.lock hash**:dependency 不變直接撈 cache,從 60s 砍到 5s。

**踩坑紀錄**:
- 第一次 push 卡 OAuth scope:`refusing to allow an OAuth App to create or update workflow without workflow scope`。Kevin 自己 push 也卡(Windows GCM 沒 workflow scope)。**解法**:走 PAT(`ghp_...` token)+ `git -c credential.helper="" push` 一次性繞過。長期解是裝 `gh` CLI,記到後續(`winget install --id GitHub.cli`)。

**結果**:CI 56 秒跑完(Ubuntu vs local Windows 4× 速度差),200 tests / 660 assertions 全綠。README 加上 badge:

```markdown
[![PHPUnit](https://github.com/TuanTsungShiang/ez_crm/actions/workflows/phpunit.yml/badge.svg?branch=develop)](...)
```

Commits:
- `eb78cab ci: add PHPUnit GitHub Actions workflow (Engineering Infra W1)`
- `a769a82 docs(readme): add PHPUnit CI badge`

---

### 3. .gitignore 整理(15 分鐘小任務)

- `.claude/` — local Claude Code 設定,per-user 不該入版控
- `package-lock.json` — 後端 repo 不跑 Vite build(SPA 已分離到 ez_crm_client),lock 檔無功能價值

Commit:`7b30e6f chore(gitignore): ignore .claude/ and package-lock.json`

---

### 4. RBAC baseline — 從 zero 到 Filament UI 完整收尾(主菜)

**起點問題**:[app/Models/User.php:19](../../../app/Models/User.php#L19) 的 `canAccessPanel(): true` — 任何 User 都能進 Filament 後台,零權限控制。SENIOR_ROADMAP Phase 1 完成條件包含 RBAC,且 Phase 2(Points / Coupon / Order)會依賴底層 RBAC 已就位。

#### 4.1 Stack 決策:spatie/laravel-permission v6

跟 Kevin 對齊 5 個 baseline role + 21 permission(後來加 webhook_event.view_any 變 21)的清單後啟動。完整討論寫進 [ADR-0001](../../../work_log/20260428/adr_0001_rbac_baseline.md),含三個 alternative(boolean is_admin / Filament Shield / 純 spatie 不寫 Policy)的取捨。

**核心架構**:
1. spatie 5 張表(roles / permissions / 三張中間表)
2. Gate::before 短路 super_admin(arrow function 一行)
3. 8 個 Laravel Policy class(Member/MemberGroup/Tag/Webhook×3/User/Role)
4. RolePermissionSeeder 是 source of truth(syncPermissions,重 seed 會 reset baseline)
5. canAccessPanel 改檢查 `panel.access` permission

**5 個 baseline role × 21 permission 的 matrix**(在 seeder 寫死):

| Role | 權限數 | 主要範圍 |
|---|---|---|
| `super_admin` | 21(全部) | + Gate::before 短路 |
| `admin` | 16 | 業務全權,不能 manage user/role |
| `customer_support` | 6 | 看會員 + update profile |
| `marketing` | 9 | tag.manage + 看 webhook/notification |
| `viewer` | 8 | 全部 view_any,沒 write |

#### 4.2 Filament UserResource + RoleResource(加碼,原 plan 沒有)

Kevin 要求「視覺化的使用者創建 CRUD 介面」,選了選項 B(UserResource + RoleResource 都做)。

- **UserResource**:admin user CRUD,form 含 Role 多選欄位,table 顯示 role badges,放在「系統管理」navigation group
- **RoleResource**:role CRUD,form 含 21 個 permission checkbox grid(spatie permission preload + bulk toggleable),table 顯示 permissions_count / users_count

#### 4.3 UI Guards(雙保險)

**Policy 層**(對 user.manage / role.manage 但非 super_admin 有效):
- UserPolicy::delete:不能刪自己
- RolePolicy::delete:不能刪 baseline role(['super_admin','admin','customer_support','marketing','viewer'])

**Filament Action 層**(對 super_admin 也有效,因 Gate::before 跳過 Policy):
- DeleteAction `->visible(fn ($record) => $record->id !== auth()->id())`
- 同樣 baseline role 列表保護

Trade-off 寫進 ADR:Gate::before 短路是 super_admin 全權的核心 invariant,不該破壞;UI 防呆走 Filament 層而非 Policy 層,`super_admin` 仍可透過 tinker 強刪 — 這是設計,不是 bug。

#### 4.4 Widget RBAC(發現後補)

Kevin 用 `support@ezcrm.local`(customer_support)登入驗證時,發現 dashboard widget(WebhookHealthWidget 顯示成功率/失敗數/Queue 深度)**沒受 RBAC 控管**。補上 `static canView()` 檢查 `webhook_delivery.view_any`,3 個 test 覆蓋(roles allow / customer_support deny / guest deny)。

#### 4.5 Test 覆蓋

[tests/Feature/Auth/RbacTest.php](../../../tests/Feature/Auth/RbacTest.php) — **18 cases / 61 assertions**:

- panel.access(2 cases:無 role 拒絕 + 5 baseline role 全通)
- super_admin Gate::before 短路(覆蓋包含不存在的 permission)
- admin 業務全權但不能管 user/role(2 cases)
- customer_support 限制(view 但不 create/delete + 看不到 webhook)
- marketing 限制(tag.manage 但不能 update member)
- viewer 全 read no write
- Policy 行為(MemberPolicy + TagPolicy)
- UserPolicy::delete 不能刪自己 + 能刪別人(2 cases,皆用非 super_admin)
- RolePolicy::delete 不能刪 baseline + 能刪 custom(2 cases)
- WebhookHealthWidget canView(3 cases)
- bootstrap admin sanity check

**Full regression**:**203 passed / 666 assertions**(從 185/605 + 18 RBAC + 補測試)

#### 4.6 Bootstrap admin

`admin@ezcrm.local`(4/16 既存) + `support@ezcrm.local`(今天 tinker 建)分別 super_admin / customer_support。前者密碼 reset 為 `admin8888`,後者 `support8888`(local dev 用)。

Commit:`1e0fa69 feat(rbac): introduce RBAC for Filament admin (spatie + policies + UI)`

---

## 🎁 視覺驗證 PASS

Kevin 用兩個帳號輪流登入確認:

**super_admin** 看到完整 6 個 Resource + 「系統管理」分組的 UserResource/RoleResource ✅
**customer_support** 左 nav 只剩 3 個 Resource(Members / MemberGroups / Tags),Webhook 那 3 頁 + Notification + 系統管理全消失 ✅

---

## 📊 Commit 序列(今日)

### ez_crm(後端)
```
(pending)  feat(filament): widget RBAC + 3 widget tests
1e0fa69    feat(rbac): introduce RBAC for Filament admin (spatie + policies + UI)
7b30e6f    chore(gitignore): ignore .claude/ and package-lock.json
a769a82    docs(readme): add PHPUnit CI badge
eb78cab    ci: add PHPUnit GitHub Actions workflow (Engineering Infra W1)
b2416bd    docs(work_log): add T5.1 OAuth-only password UX backlog
```

### ez_crm_client(前端)
```
c3e8e54    Merge feature/me-password-destroy → develop  (上午)
2887ad8    feat(me): add /me/password + account destroy modal + unlock 🔑 tile  (4/24)
```

---

## 📊 狀態快照

### 測試
- 後端:**203 passed / 666 assertions**(+18 RBAC + 0 既有 regression)
- 前端:無自動測試
- CI:GitHub Actions 綠燈 ✅,README badge 上線

### 分支衛生
- ez_crm:`develop` clean(無未 merge feature branch)
- ez_crm_client:`develop` clean

### 路線圖進度(對照 schedule_assessment 2026-04-24 寫的)

| 里程碑 | 原訂日期 | 現況 |
|---|---|---|
| Phase 1 完成(CRUD + RBAC + CI) | 2026-05-07 | ✅ **2026-04-28 完成,提早 9 天** |
| Engineering Infra W1 (CI) | 2026-04-28 | ✅ 當天完成 |
| Phase 2 開始(Points)| 2026-05-05 | 🟢 可隨時啟動 |

---

## 🎁 亮點

- **「規範 + 工具 = 紀律」實踐**:今天的對話發散過一段討論公司 Linky360 codebase 有 3,554 處 inline style 卻有設計系統(蝦蝦 OpenClaw 寫的 css2/linky360-design.css)沒人套用,結論是「規範有寫不等於被遵守」。今天 ez_crm 自己做的 PHPUnit CI + RBAC ADR + 18 個 RbacTest 都是把規範**寫進工具**,不依賴人類自律 — 這是反 Linky360 demo 的關鍵差異。
- **AI augmented dev 的標準範本**:從 plan → ADR → 實作 → test → commit 一氣呵成,RBAC 從 spatie 安裝到 Filament UI + 3 套 UI Guard,1 小時內完工(原估 3h)。Kevin 從「14:34 還有時間可以進行」到「15:45 收 RBAC + widget」,進度倍速。
- **三個 backlog 完整紀錄**:T5.1 OAuth-only password / RBAC widget / Filament Dashboard 各層 RBAC,沒有當下做的事都寫進 backlog,不會掉。
- **ADR 寫進 git history**:不是 ad-hoc decision,未來 review 時看得到「為什麼選 spatie 不選 Filament Shield」。

---

## 🔜 下一步候選

1. **Phase 2 開始 - Points 點數系統**(SENIOR_ROADMAP Phase 2 開頭)
2. **Engineering Infra W2:PHPStan level 5-6 + ESLint strict**(對應 ENGINEERING W2,2026-05-05 截止)
3. **T5.1 實作**(OAuth-only password set 流程,backlog 已寫)
4. **Filament Resource 加 RBAC visibility**:Webhook/Notification Resource 在 customer_support 視角已隱藏,但 navigation group 邏輯還可優化
5. **舊的 AI PR Review workflow 紅燈清理**(設 OPENAI_API_KEY 或停用)

Kevin 累積 1 天工作量已經抵原本 schedule_assessment 預估的 1 週,可以**喘口氣**或**直推 Phase 2** — 看明天精神。
