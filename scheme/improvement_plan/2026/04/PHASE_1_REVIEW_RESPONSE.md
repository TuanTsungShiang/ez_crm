# Phase 1 Review Response

> Date: 2026-04-02
> Scope: Phase 1 implementation follow-up
> Reviewer feedback source: Claude

## Summary

Claude 對本次 Phase 1 變更的判定為：

- `routes/api.php`: 可合併
- `app/Http/Requests/Api/V1/MemberSearchRequest.php`: 可合併
- `README.md`: 可合併
- `tests/Feature/Api/V1/MemberSearchTest.php`: 需要修正後可合併

本次已依據 Claude 指示完成補正。

## Claude Requested Fix

Claude 指出的唯一具體修正點是：

- 將 `MemberSearchTest.php` 中的 `auth()->logout();`
- 改為 `$this->app['auth']->forgetGuards();`

## Why This Was Updated

在測試情境中，`auth()->logout()` 會依賴目前 guard 與登入狀態，對於 API middleware 測試來說不夠穩定，也可能在不同測試設定下產生不必要副作用。

改用：

```php
$this->app['auth']->forgetGuards();
```

可以更直接地清除目前測試程序中的 guard 狀態，讓 `/api/v1/members/search` 的未登入驗證測試更貼近「重新發出匿名 request」的目標。

## Final Updated Items

本次 Phase 1 最終變更包含：

1. 將 `GET /api/v1/members/search` 加上 `auth:sanctum`
2. 將 `MemberSearchRequest::authorize()` 改為需有登入使用者
3. 重寫 `README.md` 為專案文件版本
4. 在 `MemberSearchTest.php` 中：
   - 加入 `User` 與 `actingAs(User::factory()->create())`
   - 新增 `test_requires_authentication()`
   - 依 Claude 建議將 guard reset 改為 `$this->app['auth']->forgetGuards();`

## Files Affected

- `routes/api.php`
- `app/Http/Requests/Api/V1/MemberSearchRequest.php`
- `README.md`
- `tests/Feature/Api/V1/MemberSearchTest.php`

## Verification Notes

已完成：

- PHP syntax check for updated PHP files

尚未完整完成：

- `php artisan test`

原因：

- 本機 `composer install` 過程中，`vendor/phpunit/phpunit` 安裝狀態異常，導致 autoload 未完整產生，屬於環境依賴問題，並非這次 Phase 1 邏輯修正本身的 blocker。

## Review Hand-off

請搭配以下檔案一起交由 Claude 再次審核：

- `scheme/improvement_plan/2026/04/PHASE_1_REVIEW_RESPONSE.md`
- `scheme/improvement_plan/2026/04/PHASE_1_IMPLEMENTATION.diff`
