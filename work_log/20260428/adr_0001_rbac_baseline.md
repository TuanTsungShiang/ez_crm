# ADR-0001: Filament Admin RBAC Baseline

> Status: **Accepted** (2026-04-28)
> Deciders: Kevin, Claude
> Related: SENIOR_ROADMAP Phase 1 完成條件 / ENGINEERING_INFRASTRUCTURE_ROADMAP

---

## Context

ez_crm 後台 (Filament) 在 v1.0.0 上線時 `User::canAccessPanel()` 直接 `return true`,
任何成功登入的 User 都能對所有 Resource(Member / WebhookSubscription / Tag / ...)
做完整 CRUD,等同零權限控制。

SENIOR_ROADMAP Phase 1 的完成條件包含 RBAC,且未來 Phase 2 業務模組
(Points / Coupon / Order)的權限細分**必須依賴底層 RBAC 已就位**,否則每個
Resource 的存取邏輯會散在各自 Controller / Resource,無法一致審計。

範圍切割:
- **In scope**:Filament 後台 admin (`User` model)
- **Out of scope**:前台會員 (`Member` model) — B2C 場景不需角色,會員權限就是「自己的資料」。
  若未來轉 B2B(企業會員看自己旗下會員),再開新 ADR。

## Decision

採用 **`spatie/laravel-permission` v6.x** 作為 RBAC 基礎,搭配 Laravel 原生 Policy class
做 Resource-level 授權判斷。

### 5 baseline roles

| Role | 用途 |
|---|---|
| `super_admin` | 全權,含 user/role 管理。Gate::before 短路所有 ability check |
| `admin` | 業務全權,不能 manage user/role |
| `customer_support` | 看會員、改 profile,不能刪會員、不能管 webhook/tag |
| `marketing` | 看會員、寄通知、看 webhook、可管 tag,不能改會員/系統設定 |
| `viewer` | 全部唯讀(含老闆 / 稽核)|

未來預留(在 seeder 註解):
- `developer` — 看 webhook payload + retry,不能管 user
- `auditor` — viewer + access log

### 21 baseline permissions

命名:`<resource>.<action>`,跨 8 個 resource。

| Resource | Actions |
|---|---|
| `panel` | `access` |
| `member` | `view_any` / `view` / `create` / `update` / `delete` / `restore` |
| `member_group` | `view_any` / `manage` |
| `tag` | `view_any` / `manage` |
| `webhook_subscription` | `view_any` / `manage` |
| `webhook_delivery` | `view_any` / `retry` |
| `webhook_event` | `view_any` |
| `notification_delivery` | `view_any` |
| `user` | `view_any` / `manage`(僅 super_admin)|
| `role` | `view_any` / `manage`(僅 super_admin)|

### 關鍵實作決策

1. **Source of truth = `RolePermissionSeeder`**
   重 seed 會 `syncPermissions` 覆蓋 baseline 角色的權限。在 Filament UI 改 baseline
   角色 = 暫時偏離 baseline,下次 seed 會 reset。要長期改規則,改 seeder 並重 seed。
   客製化的 role(super_admin 在 UI 自行新增的)不會被洗掉。

2. **Gate::before 短路 super_admin**
   `AuthServiceProvider::boot()` 加 `Gate::before(fn ($user) => $user->hasRole('super_admin') ? true : null)`,
   讓 super_admin 自動繞過所有 permission / policy check。Seeder 中也明確給 super_admin
   全部 21 個 permission(雙保險,不依賴 Gate::before 在某些 context 失效)。

3. **`canAccessPanel` 改檢查 `panel.access` permission**
   不再 `return true`。沒角色的 User 進不了後台,有角色但被拔掉 `panel.access` 也進不了。

4. **6 個 Policy class 顯式註冊在 `AuthServiceProvider::$policies`**
   Laravel 10 預設 auto-discovery 也找得到,但顯式註冊讓 review 時一眼看到「哪個 Model 有 Policy」。

5. **Bootstrap admin 寫進 seeder**
   `admin@ezcrm.local` 由 seeder 用 `firstOrCreate` 確保存在 + 賦予 super_admin。
   Idempotent,fresh deploy / CI 都能跑。預設密碼 `password`(會在第一次登入後改)。

## Consequences

### ✅ Pros
- Phase 2 業務模組(Points / Coupon / Order)的權限細分有底層可依靠
- 可由 super_admin 在 Filament UI 自訂新角色(spatie 預設支援)
- Permission 命名一致(`resource.action`),易於日後 PHPStan rule 統一驗證
- 9 個 RbacTest case + 11 個 assertions 覆蓋所有 5 baseline role × 各權限,
  改 seeder 就能立刻在 CI 看到 regression

### ❌ Cons
- 多了 5 張 spatie 表(roles / permissions / model_has_permissions / model_has_roles / role_has_permissions),
  schema 略複雜
- super_admin 透過 Gate::before 完全繞過 → 任何防呆(例如「不能刪自己」)
  必須用 Policy logic 自己擋,不能依賴 permission check
- 重 seed 會洗掉 baseline role 的 UI 改動 — 對 prod 是 trade-off,需要團隊知道規矩

## Alternatives considered

### Alt A — 自製 boolean `is_admin` 欄位 ❌
零 dependency,但無法表達「客服只能看不能刪」這類粒度。Phase 2 來時必重寫,代價更高。

### Alt B — `bezhanSalleh/filament-shield`(Filament-specific RBAC plugin) ❌
專為 Filament 設計、自動產 Resource Permission,初看 ROI 高。但:
- 與框架耦合過深,以後想把 admin API 開出去(非 Filament)會卡
- 不符合「先有 Laravel-native 概念,Filament 是 consumer」的分層原則
- 自家 ez_crm_client 前台可能也想複用權限定義 → spatie 通用,Shield 不行

### Alt C — 純 spatie permission,不寫 Policy class ❌
每個 Resource 都用 `->visible(fn() => auth()->user()->can('member.create'))` 判斷,
省 Policy class 但散落在 Filament Resource 各處,難審計、單元測試難寫。

## References

- `app/Providers/AuthServiceProvider.php` — Gate::before + $policies
- `app/Models/User.php` — HasRoles trait + canAccessPanel
- `database/seeders/RolePermissionSeeder.php` — baseline source of truth
- `app/Policies/*.php` — 6 個 Resource Policy
- `tests/Feature/Auth/RbacTest.php` — 11 個 RBAC test
- spatie/laravel-permission 官方文件: https://spatie.be/docs/laravel-permission/v6/introduction
