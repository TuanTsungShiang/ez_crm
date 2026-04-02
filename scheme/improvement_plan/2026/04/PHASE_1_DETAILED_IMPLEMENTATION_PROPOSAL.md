# ez_crm Phase 1 Detailed Implementation Proposal

> Version: 1.1
> Date: 2026-04-02
> Status: Reviewed and approved for implementation
> Reviewed by: Claude Code (2026-04-02) — open questions resolved, test fix directive added

## Goal

Phase 1 focuses on two outcomes only:

1. Protect the member search API so member data is no longer publicly accessible.
2. Replace the default Laravel README with project-specific documentation for `ez_crm`.

This proposal is intentionally limited to planning. No application code or existing docs are changed in this step.

## Scope

### In scope

- `GET /api/v1/members/search` access control
- `MemberSearchRequest::authorize()` behavior
- README rewrite plan
- Validation and review checklist for the Phase 1 implementation

### Out of scope

- Search performance optimization
- Database indexes
- Additional tests beyond what is required to support Phase 1
- Member CRUD
- Tag/group management
- Search feature expansion

## Current State Summary

### 1. Member search route is public

Current route definition in `routes/api.php`:

```php
Route::prefix('v1')->group(function () {
    Route::get('members/search', [MemberController::class, 'search']);
});
```

Risk:

- The endpoint can be reached without authentication.
- Search results include member identity fields such as name, email, and phone.
- For a CRM context, this is too permissive even in an early test stage.

### 2. Request authorization always passes

Current behavior in `app/Http/Requests/Api/V1/MemberSearchRequest.php`:

```php
public function authorize(): bool
{
    return true;
}
```

Risk:

- The request object provides no authorization boundary.
- Even if route protection is later removed or bypassed, the request layer still allows access.

### 3. README is still the default Laravel template

Current `README.md` does not explain:

- What `ez_crm` is
- What features are already implemented
- How to install dependencies
- How to run migrations and seeders
- How to call the existing API
- What current project limitations exist

Impact:

- The project looks less complete than it is.
- Reviewers and future contributors cannot quickly verify or continue the work.

## Proposed Phase 1 Implementation

## Part A. Protect the member search API

### A1. Apply authentication middleware to the search route

Proposed direction:

- Move the member search route behind authentication.
- Prefer using `auth:sanctum` because Sanctum is already present in the project dependencies and is already used for `/api/user`.

Proposed route shape:

```php
Route::prefix('v1')
    ->middleware('auth:sanctum')
    ->group(function () {
        Route::get('members/search', [MemberController::class, 'search']);
    });
```

Why this approach:

- Minimal change
- Consistent with the existing Laravel setup
- Easy to verify
- Appropriate for internal CRM access control in an early phase

### A2. Tighten `MemberSearchRequest::authorize()`

Proposed direction:

- Replace `return true;` with an authenticated-user check.

Proposed behavior:

```php
public function authorize(): bool
{
    return $this->user() !== null;
}
```

Why this is still useful even with route middleware:

- Adds a second authorization boundary
- Keeps the request object aligned with the route expectation
- Reduces the chance of accidental exposure if the route middleware changes later

### A3. Keep Phase 1 authorization simple

Phase 1 should not introduce role or permission logic yet.

Reason:

- There is no clear admin/ops permission model implemented yet.
- Early-stage value comes from first closing public access.
- Role design can be handled in a later phase once user/back-office requirements are clearer.

### A4. Expected API behavior after implementation

- Unauthenticated request to `/api/v1/members/search`
  - Expected result: `401 Unauthorized`
- Authenticated request without validation issues
  - Expected result: `200 OK`
- Authenticated request with invalid query parameters
  - Expected result: `422 Validation failed`

## Part B. Replace the default README with project documentation

### B1. README target structure

The new README should contain these sections:

1. Project overview
2. Current implementation status
3. Tech stack
4. Local setup
5. Database setup
6. Seeder/test notes
7. Current API endpoints
8. Project structure
9. Known limitations
10. Related planning documents

### B2. Proposed README content outline

#### Project overview

Describe `ez_crm` as:

- a Laravel-based early-stage CRM/member management project
- currently centered on member data modeling and member search API
- still in testing and early development

#### Current implementation status

Summarize what already exists:

- member core model
- member groups
- tags
- profile relation
- SNS relation
- member search endpoint
- request validation
- API resources
- seed data
- feature tests

#### Local setup

Expected setup instructions:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

If frontend assets are needed:

```bash
npm install
npm run dev
```

#### Test notes

Document that tests require dependencies to be installed first.

Example:

```bash
php artisan test
```

#### API section

Document at least:

- `GET /api/v1/members/search`
- auth requirement
- major query parameters
- response summary

#### Known limitations

State clearly:

- project is in early development
- search API is currently the main exposed business endpoint
- CRUD and advanced CRM operations are not complete yet
- performance tuning and deeper permissions are planned later

### B3. README tone and intent

The README should be written for these readers:

- future you
- teammates
- reviewers
- anyone trying to boot the project locally

The goal is not marketing copy. The goal is clarity and operational usefulness.

## Files Planned for Future Implementation

If Phase 1 is approved, the implementation is expected to touch:

- `routes/api.php`
- `app/Http/Requests/Api/V1/MemberSearchRequest.php`
- `README.md`

No database schema changes are required for Phase 1.

## Risks and Considerations

### 1. Authentication choice must match actual usage

If the project currently relies on browser session auth rather than Sanctum tokens, we should confirm whether:

- API access is intended for internal backend users via session, or
- API access is intended for token-based clients

Current proposal assumes `auth:sanctum` remains the correct short-term choice because it already exists in the codebase.

### 2. Existing tests will break and must be fixed as part of Phase 1

> **[REVIEW NOTE — RESOLVED]**
> All 9 tests in `tests/Feature/Api/V1/MemberSearchTest.php` call `$this->getJson()` without authentication.
> After Phase 1 middleware is applied they will all return 401 and fail.
> This is not optional to fix later — it must be handled within Phase 1 to keep the test suite green.

**Required fix:**

In `MemberSearchTest.php`, create a test user in `setUp()` and authenticate via `actingAs()` before requests are made.

Expected change:

```php
use App\Models\User;

protected function setUp(): void
{
    parent::setUp();
    $this->actingAs(User::factory()->create());
    $this->seedBaseData();
}
```

If a separate admin model is introduced later, update accordingly. For Phase 1, using `User::factory()->create()` with `actingAs()` is sufficient and keeps the change minimal.

This fix is mandatory. Phase 1 implementation is not complete until all existing tests pass with authentication in place.

### 3. README setup steps depend on dependency availability

The repo currently appears to be missing installed Composer dependencies in the working tree. The README should still document the correct setup flow, but implementation verification may remain partially blocked until dependencies are installed.

## Validation Plan After Approval

After implementation, validation should include:

1. Syntax check on updated PHP files
2. Route review to confirm middleware coverage
3. Unauthenticated access check for `/api/v1/members/search`
4. Authenticated access check for `/api/v1/members/search`
5. Validation failure check for bad query input
6. README review for setup completeness and accuracy

## Acceptance Criteria

Phase 1 can be considered complete when all of the following are true:

1. Member search endpoint is no longer publicly accessible.
2. `MemberSearchRequest` enforces authenticated access instead of always authorizing.
3. README clearly explains what the project is and how to run it locally.
4. README documents the current API surface and current project limitations.
5. No non-Phase-1 code changes are mixed into the implementation.

## Recommended Execution Order After Review Approval

1. Update `routes/api.php`
2. Update `MemberSearchRequest.php`
3. Update `README.md`
4. Run available verification steps
5. Summarize any remaining blockers such as missing dependencies

## Review Notes

> **[REVIEW NOTE — ALL THREE QUESTIONS RESOLVED. Codex should treat these as decided.]**

1. **Is `auth:sanctum` the intended short-term access control method for this CRM?**
   **Resolved: Yes. Use `auth:sanctum` (token-based).**
   Rationale: Sanctum is already in the project dependencies and used for `/api/user`. It is the correct short-term choice for a CRM API consumed by a frontend SPA or API client. Session-based auth can be considered later if a server-rendered admin UI is introduced.

2. **Should Phase 1 stop at authenticated access, or do you also want admin-only access in the same phase?**
   **Resolved: Phase 1 stops at authenticated access only. No role or permission logic.**
   Rationale: No admin/ops model exists yet. Introducing role logic now would require schema decisions that are out of scope. Closing public access is the immediate priority. Role-based access control is deferred to a later phase.

3. **Should the README be written mainly in English, Traditional Chinese, or mixed bilingual format?**
   **Resolved: Traditional Chinese (zh-TW) as the primary language.**
   Rationale: The project owner communicates in Traditional Chinese. Technical code examples remain in English. Section headers and prose should be in Chinese.
