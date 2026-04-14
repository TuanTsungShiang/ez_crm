# ez_crm Phase 2 Detailed Implementation Proposal

> Version: 1.1
> Date: 2026-04-02
> Status: Reviewed and approved for implementation
> Reviewed by: Claude Code (2026-04-02) — redundant index removed, sort guard test path clarified, all review questions resolved

## Goal

Phase 2 focuses on improving the safety and scalability of the current member search implementation without expanding business scope.

This phase has three goals:

1. Optimize `tag_ids` filtering so multi-tag AND queries scale better.
2. Add a second-layer whitelist guard for `sort_by` inside the service layer.
3. Add database indexes that support common search filters and sorting.

This proposal is limited to planning. No application code or schema is changed in this step.

## Scope

### In scope

- `App\Services\MemberSearchService`
- new search-related migration for indexes
- test updates required to verify the Phase 2 behavior

### Out of scope

- new search features
- UI changes
- role/permission model
- CRUD endpoints
- full-text search engine integration
- major schema redesign

## Current State Summary

## 1. `tag_ids` currently uses repeated `whereHas()`

Current behavior in `app/Services/MemberSearchService.php`:

```php
if (!empty($params['tag_ids'])) {
    foreach ($params['tag_ids'] as $tagId) {
        $query->whereHas('tags', fn($q) => $q->where('tags.id', $tagId));
    }
}
```

Meaning:

- The semantics are correct for AND filtering.
- A member must contain every requested tag.

Current concern:

- For each tag, the query adds another `EXISTS` subquery.
- As tag count grows, SQL complexity and execution cost grow too.
- This is acceptable for small data but weakens quickly once search volume or member count increases.

## 2. `sort_by` is validated, but service still trusts input directly

Current behavior:

```php
$sortBy  = $params['sort_by'] ?? 'created_at';
$sortDir = $params['sort_dir'] ?? 'desc';
$query->orderBy($sortBy, $sortDir);
```

Current concern:

- Request validation currently protects normal HTTP usage.
- But the service itself still accepts `$sortBy` without internal guarding.
- If the service is reused later from another entry point, this becomes a trust boundary weakness.

## 3. Search-related indexes are still missing

Current schema already has:

- primary keys
- unique keys for `uuid`, `email`, `phone`
- primary key on `member_tag(member_id, tag_id)`

Missing for common search paths:

- `members.status`
- `members.member_group_id`
- `members.created_at`
- `members.last_login_at`
- direct support for filtering by `member_tag.tag_id`

Current concern:

- Filtering and sorting will eventually trigger more table scans than necessary.
- The current search API is still early-stage, which makes now the best time to add low-risk indexes.

## Proposed Phase 2 Implementation

## Part A. Optimize `tag_ids` filter

### A1. Keep the same business behavior

Phase 2 should preserve current semantics:

- `tag_ids=[1,2,3]` means the member must have tag 1 AND tag 2 AND tag 3.

This is important because current tests and user expectations are aligned with AND semantics.

### A2. Replace repeated `whereHas()` with grouped subquery

Proposed direction:

```php
$tagIds = array_values(array_unique($params['tag_ids']));

$query->whereIn('members.id', function ($sub) use ($tagIds) {
    $sub->select('member_id')
        ->from('member_tag')
        ->whereIn('tag_id', $tagIds)
        ->groupBy('member_id')
        ->havingRaw('COUNT(DISTINCT tag_id) = ?', [count($tagIds)]);
});
```

Why this approach:

- Keeps current AND logic
- Reduces multiple correlated subqueries
- Uses the pivot table directly, which matches the real filtering need
- Pairs naturally with an index on `member_tag.tag_id`

### A3. Normalize tag input defensively

Before building the subquery, Phase 2 should normalize:

- duplicate tag ids
- array ordering noise

Suggested approach:

```php
$tagIds = array_values(array_unique($params['tag_ids']));
```

Why:

- Prevents `COUNT(DISTINCT ...)` mismatch caused by duplicated input
- Makes behavior more predictable

## Part B. Add internal `sort_by` guard

### B1. Keep Request validation in place

`MemberSearchRequest` should continue validating:

- `created_at`
- `last_login_at`
- `name`

No change is proposed there.

### B2. Add a service-level whitelist

Proposed service behavior:

```php
$allowedSorts = ['created_at', 'last_login_at', 'name'];
$sortBy = $params['sort_by'] ?? 'created_at';

if (!in_array($sortBy, $allowedSorts, true)) {
    $sortBy = 'created_at';
}

$sortDir = ($params['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$query->orderBy($sortBy, $sortDir);
```

Why this approach:

- Adds defense in depth
- Keeps service safe if reused outside Form Request
- Makes allowed sorting behavior explicit in one place

### B3. Keep fallback behavior simple

If `sort_by` is not allowed:

- fall back to `created_at`

If `sort_dir` is invalid:

- fall back to `desc`

This avoids throwing new runtime behavior into Phase 2.

## Part C. Add search indexes

### C1. Use a new migration instead of editing existing migrations

Recommended approach:

- create a new migration that adds indexes
- do not rewrite original migrations already present in the project history

Why:

- safer for an evolving repository
- cleaner audit trail
- easier rollout for environments that already migrated once

### C2. Proposed indexes

For `members`:

- index on `status`
- ~~index on `member_group_id`~~ — **[REVIEW NOTE — REMOVED]** `member_group_id` uses `foreignId()->constrained()` in the existing migration. MySQL automatically creates an index on FK referencing columns when the constraint is defined. Adding `$table->index('member_group_id')` would create a redundant duplicate index, wasting write overhead and storage. **Do not add this index.**
- index on `created_at`
- index on `last_login_at`

For `member_tag`:

- index on `tag_id`

Notes:

- `member_tag(member_id, tag_id)` already has a primary key, so no extra duplicate composite index is needed for the same order.
- The missing practical lookup for Phase 2 is `tag_id`, because the optimized subquery filters by `whereIn('tag_id', ...)`.

### C3. Migration shape

> **[REVIEW NOTE — `member_group_id` removed from migration]**
> See C2 above. The corrected migration must NOT include `$table->index('member_group_id')`.

Correct migration shape:

```php
Schema::table('members', function (Blueprint $table) {
    $table->index('status');
    $table->index('created_at');
    $table->index('last_login_at');
});

Schema::table('member_tag', function (Blueprint $table) {
    $table->index('tag_id');
});
```

Rollback should drop those indexes explicitly.

## Test Plan for Phase 2

Phase 2 should include tests for behavior, not just code changes.

## 1. `tag_ids` AND semantics test

Add or update tests to verify:

- member with all requested tags is returned
- member with only some requested tags is excluded

## 2. Duplicate `tag_ids` handling

Add a test to verify:

- `tag_ids=[1,1,2]` behaves the same as `tag_ids=[1,2]`

## 3. Service fallback for sort behavior

> **[REVIEW NOTE — REQUIRES UNIT TEST, NOT FEATURE TEST]**
> The HTTP feature test layer cannot cover the service-level sort guard.
> `MemberSearchRequest` validates `sort_by` with `in:created_at,last_login_at,name` and returns 422 for any invalid value before the request ever reaches the service.
> Therefore, testing the fallback behavior from an HTTP request is impossible — invalid values are always blocked first.
>
> **Required approach: add a Unit test that instantiates `MemberSearchService` directly and passes invalid `sort_by` / `sort_dir` values.**
>
> Target file: `tests/Unit/Services/MemberSearchServiceTest.php` (create new if not exists).
>
> Required coverage in the unit test:
>
> - calling `search(['sort_by' => 'invalid_column'])` falls back to ordering by `created_at`
> - calling `search(['sort_dir' => 'sideways'])` falls back to `desc`
> - calling `search(['sort_by' => 'name', 'sort_dir' => 'asc'])` still works correctly
>
> Feature test in `MemberSearchTest.php` can keep the existing sort-related coverage for valid HTTP paths only.

## Risks and Considerations

### 1. Query semantics must not change accidentally

The biggest Phase 2 risk is changing tag filtering from AND to OR by mistake.

Phase 2 must preserve:

- AND semantics only

### 2. Index creation should avoid redundant duplication

Because `member_tag(member_id, tag_id)` is already the primary key:

- do not add the exact same composite index again

The meaningful addition is:

- standalone `tag_id`

### 3. Performance gain is structural, not guaranteed by tiny datasets

With current seed/test data, performance differences may not be visible.

That is normal.

The purpose of Phase 2 is to improve the query shape before the data set grows.

### 4. Existing request validation still remains the first boundary

Service-level sorting guard is not replacing request validation.

It is an additional boundary so the service remains safer if reused elsewhere.

## Files Planned for Future Implementation

If Phase 2 is approved, the implementation is expected to touch:

- `app/Services/MemberSearchService.php`
- `tests/Feature/Api/V1/MemberSearchTest.php`
- `database/migrations/<new migration for indexes>.php`

Depending on the final testing approach, an additional unit/service test file may be added.

## Validation Plan After Approval

After implementation, validation should include:

1. syntax check on updated PHP files
2. `php artisan migrate` or `php artisan migrate:fresh --seed`
3. `php artisan test`
4. review generated SQL behavior if debugging is needed
5. confirm `tag_ids` duplicate input does not change expected result

## Acceptance Criteria

Phase 2 can be considered complete when all of the following are true:

1. `tag_ids` filtering still uses AND semantics
2. multi-tag filtering no longer relies on one `whereHas()` per tag
3. service-level `sort_by` guard exists and falls back safely
4. search-supporting indexes are added through a new migration
5. tests pass after the implementation
6. no unrelated Phase 3 or Phase 4 scope is mixed in

## Recommended Execution Order After Review Approval

1. update `MemberSearchService`
2. add or adjust tests for tag filtering and sort fallback
3. add new migration for indexes
4. run migrations and tests
5. summarize any database or environment blockers

## Review Questions

> **[REVIEW NOTE — ALL THREE QUESTIONS RESOLVED. Codex should treat these as decided.]**

1. **Should Phase 2 keep strict AND semantics for `tag_ids` exactly as-is?**
   **Resolved: Yes. AND semantics are preserved exactly.**
   Rationale: Current tests and product intent are aligned with AND logic. Changing to OR would be a separate product decision, not a Phase 2 concern.

2. **Do you want the sort fallback to remain silent, or should invalid input eventually be surfaced differently in a later phase?**
   **Resolved: Keep it silent in Phase 2.**
   Rationale: The service-level guard is a defensive boundary only. Invalid sort values are already rejected at the HTTP layer. If a service-direct call passes bad input, silent fallback is the safest non-breaking behavior. Surfacing this differently can be revisited in a later phase if needed.

3. **Do you want Phase 2 tests kept inside the existing feature test file, or split into a clearer dedicated test file?**
   **Resolved: Split. Feature test coverage stays in `MemberSearchTest.php`. Sort guard fallback requires a new unit test at `tests/Unit/Services/MemberSearchServiceTest.php`.**
   Rationale: The sort guard cannot be reached from HTTP (blocked by FormRequest). It must be covered at the service unit level. Mixing unit-style assertions into a feature test class is incorrect.
