# Phase 2 Review Response

> Date: 2026-04-02
> Scope: Phase 2 implementation
> Reviewer hand-off target: Claude

## Summary

本次 Phase 2 已依照審核後的 `PHASE_2_DETAILED_IMPLEMENTATION_PROPOSAL.md` 進行實作，範圍僅包含：

1. `tag_ids` 查詢優化
2. service-level `sort_by` / `sort_dir` guard
3. 搜尋相關 index migration
4. 對應的 feature / unit tests

本次沒有混入 Phase 3、Phase 4 的功能。

## Implemented Changes

### 1. `tag_ids` AND 查詢改為 grouped subquery

已更新 `app/Services/MemberSearchService.php`：

- 原本逐一 `whereHas()` 的寫法已移除
- 改為直接查 `member_tag`
- 使用 `GROUP BY member_id + HAVING COUNT(DISTINCT tag_id) = n`
- 保留原本的 AND semantics
- 對輸入的 `tag_ids` 先做 `array_unique()` 去重

目的：

- 降低多 tag 搜尋時的 correlated subquery 數量
- 保持目前產品行為不變

### 2. 加入 service-level sort guard

已更新 `app/Services/MemberSearchService.php`：

- `sort_by` 僅允許：
  - `created_at`
  - `last_login_at`
  - `name`
- 若輸入不合法，靜默 fallback 到 `created_at`
- `sort_dir` 僅接受 `asc`
- 其他值一律 fallback 到 `desc`

目的：

- 補上 service 自身的防禦邊界
- 避免未來 service 被其他 entry point 直接呼叫時信任外部輸入

### 3. 新增搜尋相關 index migration

已新增 migration：

- `database/migrations/2026_04_02_140000_add_search_indexes_to_members_and_member_tag_tables.php`

新增 index：

- `members.status`
- `members.created_at`
- `members.last_login_at`
- `member_tag.tag_id`

依 Claude 批註，本次**沒有**新增 `member_group_id` index，因為現有 FK 已會建立對應索引，避免重複。

### 4. 補強測試

已更新 feature test：

- `tests/Feature/Api/V1/MemberSearchTest.php`

新增覆蓋：

- `tag_ids` 必須符合 AND semantics
- duplicate `tag_ids` 不應改變結果

已新增 unit test：

- `tests/Unit/Services/MemberSearchServiceTest.php`

新增覆蓋：

- invalid `sort_by` fallback to `created_at`
- invalid `sort_dir` fallback to `desc`
- valid `sort_by=name` + `sort_dir=asc` 仍正常運作

## Files Included in This Review

- `app/Services/MemberSearchService.php`
- `tests/Feature/Api/V1/MemberSearchTest.php`
- `tests/Unit/Services/MemberSearchServiceTest.php`
- `database/migrations/2026_04_02_140000_add_search_indexes_to_members_and_member_tag_tables.php`

## Validation Result

已完成：

- PHP syntax check on updated files
- `php artisan test`

測試結果：

```text
Tests: 21 passed (68 assertions)
Duration: 4.30s
```

## Notes for Review

請特別確認以下三點是否符合預期：

1. `tag_ids` 查詢仍維持嚴格 AND semantics
2. `member_group_id` index 已依批註排除，未重複建立
3. sort fallback 測試已從 HTTP feature test 分離到 unit test
