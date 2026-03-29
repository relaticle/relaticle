# API Review Fixes -- Design Spec

Date: 2026-03-25
Branch: `feat/rest-api-mcp-server`
Scope: 5 targeted refactors from PR #136 code review. No new abstractions, no new traits. Pure cleanup using Laravel conventions.

## Context

PR #136 adds REST API, MCP server, and team-scoped API tokens (310 files, +27,945 lines). A thorough code review identified 13 findings. After brainstorming, 8 were ruled out (over-engineering, not real issues, or vendor-package concerns). 5 remain as worthwhile fixes.

### Findings explicitly skipped

- **Redundant `Arr::only()` in actions** -- serves a different purpose than form request validation; actions are called from MCP/imports where no form request exists
- **`allUsers()->pluck()` performance** -- team owner is NOT in `team_user` table; `Rule::exists()` alternatives either miss the owner or are more complex for no gain at typical team sizes
- **Identical CRUD controllers** -- intentional clarity; base controller adds abstraction for 5 small files
- **Resource boilerplate** -- same argument; a `commonAttributes()` trait saves 4 lines per resource but adds indirection
- **Octane-unsafe `SetApiTeamContext`** -- real concern but architectural change; project runs on FPM; tracked separately
- **`CustomFieldMerger` placement** -- investigated moving to model/trait level; the vendor trait's `saveCustomFields()` treats missing keys as "set to null" which is correct for Filament (always submits all fields) but wrong for API (partial updates). Moving the merger would break Filament's clearing behavior. The explicit call in each update action is the right design.
- **Duplicate validation traits (API/MCP)** -- both use `ValidCustomFields` rule but with different glue code appropriate to their contexts
- **`allUsers()->pluck()` in task requests** -- verified team owner is not in `team_user` table; `Rule::exists('team_user', ...)` would reject the owner as assignee; current approach is correct

## Fix 1: Remove redundant authorization from controllers

### Problem

All 5 entity controllers call `Gate::authorize()` before delegating to actions. The actions then call `abort_unless($user->can(...), 403)` for the same check. Authorization runs twice per request.

### Solution

Remove `Gate::authorize()` from `store()`, `update()`, and `destroy()` in all 5 controllers. Actions are the single authorization boundary -- they're also called from MCP and imports, so they must self-authorize regardless.

**Exception**: Keep `Gate::authorize('view', $model)` in `show()` because `show()` has no action -- it returns the model directly.

### Files changed

- `app/Http/Controllers/Api/V1/CompaniesController.php` -- remove lines 47, 69, 79
- `app/Http/Controllers/Api/V1/PeopleController.php` -- remove lines 50, 74, 84
- `app/Http/Controllers/Api/V1/NotesController.php` -- remove lines 47, 69, 79
- `app/Http/Controllers/Api/V1/OpportunitiesController.php` -- remove lines 51, 76, 86
- `app/Http/Controllers/Api/V1/TasksController.php` -- remove lines 47, 69, 79

Also remove unused `Gate` import from each controller.

### Risk

Low. `Gate::authorize()` throws `AuthorizationException` (message: "This action is unauthorized.") while `abort_unless(..., 403)` throws `HttpException` (empty message). The 403 status code is identical. API clients should check status codes, not message strings. Existing tests assert `assertForbidden()` (status code only), so they pass.

## Fix 2: Move eager-loading into actions

### Problem

Controllers manually call `->load('customFieldValues.customField.options')` after every create/update action. `show()` uses `->loadMissing()` instead. The eager-loading is a response concern scattered across controllers.

### Solution

Have create and update actions return models with custom field relations already loaded. Controllers just wrap in resource.

### Before (controller)

```php
$company = $action->execute($user, $request->validated(), CreationSource::API);

return (new CompanyResource($company->load('customFieldValues.customField.options')))
    ->response()
    ->setStatusCode(201);
```

### After (action returns loaded model)

```php
// In CreateCompany::execute()
$company = DB::transaction(fn () => Company::query()->create($attributes));
return $company->load('customFieldValues.customField.options');

// In controller
$company = $action->execute($user, $request->validated(), CreationSource::API);
return (new CompanyResource($company))->response()->setStatusCode(201);
```

### Files changed

**Actions (add `->load()` to return):**
- `app/Actions/Company/CreateCompany.php`
- `app/Actions/Company/UpdateCompany.php`
- `app/Actions/People/CreatePeople.php`
- `app/Actions/People/UpdatePeople.php`
- `app/Actions/Note/CreateNote.php`
- `app/Actions/Note/UpdateNote.php`
- `app/Actions/Opportunity/CreateOpportunity.php`
- `app/Actions/Opportunity/UpdateOpportunity.php`
- `app/Actions/Task/CreateTask.php`
- `app/Actions/Task/UpdateTask.php`

**Controllers (remove `->load()` calls):**
- `app/Http/Controllers/Api/V1/CompaniesController.php`
- `app/Http/Controllers/Api/V1/PeopleController.php`
- `app/Http/Controllers/Api/V1/NotesController.php`
- `app/Http/Controllers/Api/V1/OpportunitiesController.php`
- `app/Http/Controllers/Api/V1/TasksController.php`

`show()` keeps its `loadMissing()` since there's no action involved.

### Implementation note

For `CreateNote`, `CreateTask`, `UpdateNote`, `UpdateTask`: add `->load()` on the **final return** statement, not inside the `DB::transaction()` closure. These actions have relationship syncs and (for tasks) notification logic after the transaction.

MCP tools also call these actions and call `->loadMissing()` on the result. After this change, `loadMissing()` becomes a no-op (relation already loaded). No behavioral change.

### Risk

Low. No behavioral change for any consumer.

## Fix 3: Create IndexRequest for per_page validation

### Problem

All 5 entity index endpoints accept `per_page` as a raw query parameter with no validation. Each List action manually clamps it: `max(1, min($perPage, 100))`. This is 5 lines of duplicated defensive code that should be validation.

`IndexCustomFieldsRequest` already validates `per_page` properly. The entity endpoints should do the same.

### Solution

Create a shared `IndexRequest` form request that validates pagination parameters. Use it in all 5 index controller methods. Remove clamping from all 5 List actions.

### New file: `app/Http/Requests/Api/V1/IndexRequest.php`

```php
final class IndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
```

### Controller change

```php
// Before
public function index(Request $request, ListCompanies $action, #[CurrentUser] User $user)

// After
public function index(IndexRequest $request, ListCompanies $action, #[CurrentUser] User $user)
```

### Action change

```php
// Remove this line from all 5 List actions:
$perPage = max(1, min($perPage, 100));
```

### Files changed

- **New**: `app/Http/Requests/Api/V1/IndexRequest.php`
- **Modified**: 5 controllers (change `Request` to `IndexRequest` in `index()`)
- **Modified**: 5 List actions (remove clamping line)

### MCP tools

MCP `BaseListTool` passes `per_page` directly to the action, bypassing form request validation. After removing clamping from List actions, add `$perPage = max(1, min($perPage, 100))` in `BaseListTool::handle()` to retain the guard.

### Tests that need updating

5 tests named "caps per_page at maximum allowed value" currently send `per_page=500` and `assertOk()` (silent clamping). After this fix, they'll get 422. Update to assert validation error on `per_page`:

- `tests/Feature/Api/V1/CompaniesApiTest.php`
- `tests/Feature/Api/V1/PeopleApiTest.php`
- `tests/Feature/Api/V1/NotesApiTest.php`
- `tests/Feature/Api/V1/TasksApiTest.php`
- `tests/Feature/Api/V1/OpportunitiesApiTest.php`

### Risk

Low. Invalid `per_page` values that previously got silently clamped will now return 422 validation errors. This is better behavior for an API.

## Fix 4: Consistent return type from FormatsCustomFields

### Problem

`formatCustomFields()` returns `array` when there are values and `stdClass` when empty. This is for JSON encoding (`{}` vs `[]`), but it forces two return types in the signature.

### Solution

Always cast to `object`:

```php
// Before
return $result === [] ? new \stdClass : $result;

// After
return (object) $result;
```

`(object) []` produces `stdClass{}` (JSON `{}`). `(object) ['status' => 'Active']` produces `stdClass{status: 'Active'}` (JSON `{"status": "Active"}`). Always `stdClass`, always JSON object.

### Files changed

- `app/Http/Resources/V1/Concerns/FormatsCustomFields.php` -- line 31, change return + update return type to `\stdClass`

### Tests that need updating

One test calls `formatCustomFields()` directly and asserts `->toBeArray()` on the result:
- `tests/Feature/Api/V1/CompaniesApiTest.php` -- orphaned custom field test, update assertion to `->toBeInstanceOf(stdClass::class)`

Tests that check `custom_fields` via HTTP responses (`$response->json(...)`) are unaffected -- `json_decode` with `assoc: true` returns arrays regardless.

### Risk

None for API consumers (JSON output is identical). Return type changes from `array|\stdClass` to `\stdClass`.

## Fix 5: Apply layered rate limiting to API routes

### Problem

`RateLimiter::for('api', ...)` and `RateLimiter::for('mcp', ...)` are defined in `AppServiceProvider` but never applied -- `routes/api.php` has no `throttle` middleware. The API is currently unthrottled.

### Solution

Layered rate limiting with separate budgets for reads vs writes:

- **Per-team ceiling**: 600 requests/min shared across all tokens for a team
- **Per-token reads** (GET): 300 requests/min
- **Per-token writes** (POST/PUT/PATCH/DELETE): 60 requests/min

This follows industry patterns (HubSpot, Salesforce, Attio) where writes are more constrained because they trigger DB transactions, notifications, and relationship syncing.

### Implementation

**Update `AppServiceProvider`** -- replace the existing `api` limiter:

```php
RateLimiter::for('api', function (Request $request) {
    $tokenId = $request->user()?->currentAccessToken()?->getKey();
    $teamId = $request->user()?->currentTeam?->getKey();
    $key = $tokenId ?: $request->ip();

    $limits = [
        Limit::perMinute(600)->by("team:{$teamId}"),
    ];

    if ($request->isMethod('GET')) {
        $limits[] = Limit::perMinute(300)->by("token:{$key}:read");
    } else {
        $limits[] = Limit::perMinute(60)->by("token:{$key}:write");
    }

    return $limits;
});
```

**Update `routes/api.php`** -- add `throttle:api` to the middleware stack:

```php
Route::prefix('v1')
    ->middleware([ForceJsonResponse::class, 'auth:sanctum', 'throttle:api', EnsureTokenHasAbility::class, SetApiTeamContext::class])
    ->group(function (): void {
```

`throttle:api` must come after `auth:sanctum` (needs user for token/team resolution) and before `SetApiTeamContext` (team context setup).

### Response headers

Laravel automatically adds `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `Retry-After` (on 429) headers. No custom code needed.

### Files changed

- `app/Providers/AppServiceProvider.php` -- update `api` limiter definition
- `routes/api.php` -- add `throttle:api` middleware

### Tests

Add a test verifying 429 response when rate limit is exceeded. Use Laravel's `RateLimiter::for()` override in the test to set a low limit (e.g., 2/min) and verify the third request gets 429.

### Risk

Low. Legitimate API usage should stay well within 300 reads/60 writes per minute per token. The per-team ceiling of 600/min prevents abuse from multiple tokens. Existing tests making multiple requests in a single test won't hit limits because `RefreshDatabase` resets state.

## Testing strategy

- Update 6 tests before running (5 per_page capping tests + 1 orphaned custom field test)
- Add rate limiting test (verify 429 response)
- Run API test suite: `php artisan test tests/Feature/Api/V1/ --compact`
- Run MCP test suite: `php artisan test tests/Feature/Mcp/ --compact`
- PHPStan: `vendor/bin/phpstan analyse`
- Pint: `vendor/bin/pint --dirty --format agent`

## Execution

Single atomic commit. All changes are internal refactors with no behavioral difference for API consumers (JSON responses are identical).
