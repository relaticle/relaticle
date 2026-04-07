# API Review Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply 5 targeted refactors from the PR #136 code review to improve API code quality, add rate limiting, and fix inconsistencies.

**Architecture:** Mechanical refactors across controllers, actions, and one new form request. No new abstractions or base classes. Rate limiting uses Laravel's built-in `RateLimiter` with layered limits.

**Tech Stack:** Laravel 12, PHP 8.4, Pest 4, Spatie QueryBuilder, Sanctum

**Spec:** `docs/superpowers/specs/2026-03-25-api-review-fixes-design.md`

---

### Task 1: Remove redundant Gate::authorize() from controllers

**Files:**
- Modify: `app/Http/Controllers/Api/V1/CompaniesController.php`
- Modify: `app/Http/Controllers/Api/V1/PeopleController.php`
- Modify: `app/Http/Controllers/Api/V1/NotesController.php`
- Modify: `app/Http/Controllers/Api/V1/OpportunitiesController.php`
- Modify: `app/Http/Controllers/Api/V1/TasksController.php`

- [ ] **Step 1: Run existing authorization tests to establish baseline**

Run: `php artisan test --compact tests/Feature/Api/V1/ --filter="unauthorized|forbidden|requires authentication"`
Expected: All pass

- [ ] **Step 2: Remove Gate::authorize() from CompaniesController**

Remove `Gate::authorize('create', Company::class)` from `store()` (line 47).
Remove `Gate::authorize('update', $company)` from `update()` (line 69).
Remove `Gate::authorize('delete', $company)` from `destroy()` (line 79).
Keep `Gate::authorize('view', $company)` in `show()` -- no action to delegate to.
Remove unused `use Illuminate\Support\Facades\Gate;` import.

- [ ] **Step 3: Remove Gate::authorize() from PeopleController**

Same pattern: remove from `store()` (line 50), `update()` (line 74), `destroy()` (line 84).
Keep in `show()`. Remove unused `Gate` import.

- [ ] **Step 4: Remove Gate::authorize() from NotesController**

Remove from `store()` (line 47), `update()` (line 69), `destroy()` (line 79).
Keep in `show()`. Remove unused `Gate` import.

- [ ] **Step 5: Remove Gate::authorize() from OpportunitiesController**

Remove from `store()` (line 51), `update()` (line 76), `destroy()` (line 86).
Keep in `show()`. Remove unused `Gate` import.

- [ ] **Step 6: Remove Gate::authorize() from TasksController**

Remove from `store()` (line 47), `update()` (line 69), `destroy()` (line 79).
Keep in `show()`. Remove unused `Gate` import.

- [ ] **Step 7: Run authorization tests to confirm no regression**

Run: `php artisan test --compact tests/Feature/Api/V1/ --filter="unauthorized|forbidden|requires authentication|cannot"`
Expected: All pass. Actions enforce the same authorization.

---

### Task 2: Move eager-loading from controllers into actions

**Files:**
- Modify: `app/Actions/Company/CreateCompany.php`
- Modify: `app/Actions/Company/UpdateCompany.php`
- Modify: `app/Actions/People/CreatePeople.php`
- Modify: `app/Actions/People/UpdatePeople.php`
- Modify: `app/Actions/Note/CreateNote.php`
- Modify: `app/Actions/Note/UpdateNote.php`
- Modify: `app/Actions/Opportunity/CreateOpportunity.php`
- Modify: `app/Actions/Opportunity/UpdateOpportunity.php`
- Modify: `app/Actions/Task/CreateTask.php`
- Modify: `app/Actions/Task/UpdateTask.php`
- Modify: `app/Http/Controllers/Api/V1/CompaniesController.php`
- Modify: `app/Http/Controllers/Api/V1/PeopleController.php`
- Modify: `app/Http/Controllers/Api/V1/NotesController.php`
- Modify: `app/Http/Controllers/Api/V1/OpportunitiesController.php`
- Modify: `app/Http/Controllers/Api/V1/TasksController.php`

- [ ] **Step 1: Add ->load() to simple create actions (Company, People, Opportunity)**

These have a simple `return DB::transaction(fn () => Model::query()->create($attributes))` pattern.
Change to:

```php
// CreateCompany.php line 28
$company = DB::transaction(fn (): Company => Company::query()->create($attributes));

return $company->load('customFieldValues.customField.options');
```

Same pattern for `CreatePeople.php` (line 28) and `CreateOpportunity.php` (line 28).

- [ ] **Step 2: Add ->load() to simple update actions (Company, People, Opportunity)**

These return from inside a transaction closure. Change the final `return $model->refresh()` to include the load:

```php
// UpdateCompany.php line 31
return $company->refresh()->load('customFieldValues.customField.options');
```

Same pattern for `UpdatePeople.php` (line 31) and `UpdateOpportunity.php` (line 31).

- [ ] **Step 3: Add ->load() to create actions with relationships (Note, Task)**

These have relationship syncs inside the transaction. Add `->load()` on the **final return**, not inside the transaction.

```php
// CreateNote.php -- change final line (currently returns from transaction)
$note = DB::transaction(function () use ($attributes, $companyIds, $peopleIds, $opportunityIds): Note {
    // ... existing sync logic ...
    return $note;
});

return $note->load('customFieldValues.customField.options');
```

```php
// CreateTask.php -- add load before final return (line 58)
// After $this->notifyAssignees->execute($task);
return $task->load('customFieldValues.customField.options');
```

- [ ] **Step 4: Add ->load() to update actions with relationships (Note, Task)**

```php
// UpdateNote.php -- change return on line 42
return $note->refresh()->load('customFieldValues.customField.options');
// (inside the transaction closure, the refresh+load replaces bare refresh)
```

```php
// UpdateTask.php -- add load before final return (line 57)
// After $this->notifyAssignees->execute($task, $previousAssigneeIds);
return $task->load('customFieldValues.customField.options');
```

- [ ] **Step 5: Remove ->load() from all controllers**

In each of the 5 controllers, simplify `store()` and `update()`:

```php
// store() -- before:
return (new CompanyResource($company->load('customFieldValues.customField.options')))
    ->response()->setStatusCode(201);
// store() -- after:
return (new CompanyResource($company))->response()->setStatusCode(201);

// update() -- before:
return new CompanyResource($company->load('customFieldValues.customField.options'));
// update() -- after:
return new CompanyResource($company);
```

Apply to all 5 controllers. Keep `loadMissing()` in `show()` unchanged.

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact tests/Feature/Api/V1/ --filter="can create|can update|custom fields"`
Expected: All pass. JSON responses are identical.

---

### Task 3: Create IndexRequest for per_page validation

**Files:**
- Create: `app/Http/Requests/Api/V1/IndexRequest.php`
- Modify: `app/Http/Controllers/Api/V1/CompaniesController.php`
- Modify: `app/Http/Controllers/Api/V1/PeopleController.php`
- Modify: `app/Http/Controllers/Api/V1/NotesController.php`
- Modify: `app/Http/Controllers/Api/V1/OpportunitiesController.php`
- Modify: `app/Http/Controllers/Api/V1/TasksController.php`
- Modify: `app/Actions/Company/ListCompanies.php`
- Modify: `app/Actions/People/ListPeople.php`
- Modify: `app/Actions/Note/ListNotes.php`
- Modify: `app/Actions/Opportunity/ListOpportunities.php`
- Modify: `app/Actions/Task/ListTasks.php`
- Modify: `app/Mcp/Tools/BaseListTool.php`
- Modify: `tests/Feature/Api/V1/CompaniesApiTest.php`
- Modify: `tests/Feature/Api/V1/PeopleApiTest.php`
- Modify: `tests/Feature/Api/V1/NotesApiTest.php`
- Modify: `tests/Feature/Api/V1/TasksApiTest.php`
- Modify: `tests/Feature/Api/V1/OpportunitiesApiTest.php`

- [ ] **Step 1: Update the 5 "caps per_page" tests to expect 422**

Each test file has `it('caps per_page at maximum allowed value', ...)`. Change from asserting silent clamping to asserting validation error:

```php
// In all 5 test files:
it('caps per_page at maximum allowed value', function (): void {
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/companies?per_page=500')
        ->assertUnprocessable()
        ->assertInvalid(['per_page']);
});
```

Replace `companies` with the entity's route (`people`, `opportunities`, `tasks`, `notes`) in each file.

- [ ] **Step 2: Run the updated tests to confirm they fail (TDD red)**

Run: `php artisan test --compact tests/Feature/Api/V1/ --filter="caps per_page"`
Expected: 5 FAIL (currently returns 200 with silent clamping)

- [ ] **Step 3: Create IndexRequest form request**

Run: `php artisan make:request Api/V1/IndexRequest --no-interaction`

Replace contents with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class IndexRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
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

- [ ] **Step 4: Use IndexRequest in all 5 controller index() methods**

Replace `Request $request` with `IndexRequest $request` in the `index()` method signature.
Update the import from `use Illuminate\Http\Request;` to `use App\Http\Requests\Api\V1\IndexRequest;`.

Note: check if `Request` is used elsewhere in the controller (e.g., `show()` doesn't use it). If `Request` is no longer needed, remove its import entirely. If it's still needed by other methods, keep both imports.

In these controllers, `Request` is only used in `index()`, so replace the import.

- [ ] **Step 5: Remove clamping from all 5 List actions**

Delete this line from each List action:
```php
$perPage = max(1, min($perPage, 100));
```

Files: `ListCompanies.php` (line 34), `ListPeople.php`, `ListNotes.php`, `ListOpportunities.php`, `ListTasks.php`.

- [ ] **Step 6: Add clamping to MCP BaseListTool**

In `app/Mcp/Tools/BaseListTool.php`, around line 77 where `per_page` is read:

```php
// Before:
perPage: (int) $request->get('per_page', 15),

// After:
perPage: max(1, min((int) $request->get('per_page', 15), 100)),
```

- [ ] **Step 7: Run tests to confirm green**

Run: `php artisan test --compact tests/Feature/Api/V1/ --filter="caps per_page|can list"`
Expected: All pass

---

### Task 4: Fix FormatsCustomFields return type

**Files:**
- Modify: `app/Http/Resources/V1/Concerns/FormatsCustomFields.php`
- Modify: `tests/Feature/Api/V1/CompaniesApiTest.php`

- [ ] **Step 1: Update orphaned custom field test assertion**

In `tests/Feature/Api/V1/CompaniesApiTest.php` around line 575:

```php
// Before:
expect($attributes['custom_fields'])
    ->toBeArray()
    ->toHaveKey('orphan_field', 'test value');

// After:
expect($attributes['custom_fields'])
    ->toBeInstanceOf(stdClass::class)
    ->and($attributes['custom_fields']->orphan_field)->toBe('test value');
```

Add `use stdClass;` to the test file imports if not present.

- [ ] **Step 2: Run test to confirm it fails (TDD red)**

Run: `php artisan test --compact tests/Feature/Api/V1/CompaniesApiTest.php --filter="handles orphaned"`
Expected: FAIL (currently returns array, test now expects stdClass)

- [ ] **Step 3: Fix FormatsCustomFields return type**

In `app/Http/Resources/V1/Concerns/FormatsCustomFields.php`:

```php
// Change return type (line 17):
protected function formatCustomFields(Model $record): \stdClass

// Change early return (line 20):
return new \stdClass;

// Change final return (line 31):
// Before:
return $result === [] ? new \stdClass : $result;
// After:
return (object) $result;
```

Update the docblock to `@return \stdClass`.

- [ ] **Step 4: Run test to confirm green**

Run: `php artisan test --compact tests/Feature/Api/V1/CompaniesApiTest.php --filter="handles orphaned|custom fields"`
Expected: All pass

---

### Task 5: Apply layered rate limiting

**Files:**
- Modify: `bootstrap/app.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/api.php`
- Modify: `tests/Feature/Api/V1/ApiMiddlewareTest.php`

**Context:** `throttle:api` is already applied globally via `bootstrap/app.php` (line 24-26), but it runs BEFORE `auth:sanctum`, so `$request->user()` is null and rate limiting falls back to IP only. The existing test at line 38-54 of `ApiMiddlewareTest.php` already passes because of this. We need to move the throttle to run after auth so token/team-based keying works.

- [ ] **Step 1: Write test for layered rate limiting (write limit)**

In `tests/Feature/Api/V1/ApiMiddlewareTest.php`, add a new test inside the existing `describe('rate limiting', ...)` block:

```php
it('enforces separate write rate limit', function (): void {
    RateLimiter::for('api', function () {
        return [
            Limit::perMinute(100)->by('team:test'),
            Limit::perMinute(2)->by('token:test:write'),
        ];
    });

    $token = $this->user->createToken('test', ['*'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/companies', ['name' => 'A'])
        ->assertCreated();

    $this->withToken($token)
        ->postJson('/api/v1/companies', ['name' => 'B'])
        ->assertCreated();

    $this->withToken($token)
        ->postJson('/api/v1/companies', ['name' => 'C'])
        ->assertTooManyRequests();
});
```

- [ ] **Step 2: Run test to confirm it fails (TDD red)**

Run: `php artisan test --compact tests/Feature/Api/V1/ApiMiddlewareTest.php --filter="enforces separate write"`
Expected: FAIL (current limiter has no layered limits, third request succeeds)

- [ ] **Step 3: Move throttle from bootstrap/app.php to routes/api.php**

In `bootstrap/app.php`, remove the throttle from the API middleware group (lines 24-26):

```php
// Before:
$middleware->api(prepend: [
    ThrottleRequests::class.':api',
]);

// After: remove the entire $middleware->api() block
```

In `routes/api.php`, add `'throttle:api'` after `'auth:sanctum'`:

```php
Route::prefix('v1')
    ->middleware([ForceJsonResponse::class, 'auth:sanctum', 'throttle:api', EnsureTokenHasAbility::class, SetApiTeamContext::class])
    ->group(function (): void {
```

This ensures throttle runs after auth so `$request->user()` is available.

- [ ] **Step 4: Update rate limiter in AppServiceProvider**

In `app/Providers/AppServiceProvider.php`, replace the `configureRateLimiting()` method body (lines 127-132):

```php
private function configureRateLimiting(): void
{
    RateLimiter::for('api', function (Request $request) {
        $tokenId = $request->user()?->currentAccessToken()?->getKey();
        $teamId = $request->user()?->currentTeam?->getKey();
        $key = $tokenId ?: $request->ip();

        $limits = [
            Limit::perMinute(600)->by("team:" . ($teamId ?? $request->ip())),
        ];

        if ($request->isMethod('GET')) {
            $limits[] = Limit::perMinute(300)->by("token:{$key}:read");
        } else {
            $limits[] = Limit::perMinute(60)->by("token:{$key}:write");
        }

        return $limits;
    });

    RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
}
```

- [ ] **Step 5: Run tests to confirm green**

Run: `php artisan test --compact tests/Feature/Api/V1/ApiMiddlewareTest.php`
Expected: All pass (both existing and new rate limiting tests)

---

### Task 6: Final verification and commit

- [ ] **Step 1: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Fix any style issues.

- [ ] **Step 2: Run PHPStan**

Run: `vendor/bin/phpstan analyse`
Expected: No new errors.

- [ ] **Step 3: Run full API test suite**

Run: `php artisan test --compact tests/Feature/Api/V1/`
Expected: All pass.

- [ ] **Step 4: Run MCP test suite**

Run: `php artisan test --compact tests/Feature/Mcp/`
Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/V1/ \
       app/Actions/ \
       app/Http/Requests/Api/V1/IndexRequest.php \
       app/Http/Resources/V1/Concerns/FormatsCustomFields.php \
       app/Providers/AppServiceProvider.php \
       app/Mcp/Tools/BaseListTool.php \
       bootstrap/app.php \
       routes/api.php \
       tests/Feature/Api/V1/

git commit -m "refactor: apply API review fixes -- authorization, eager-loading, validation, rate limiting

- Remove redundant Gate::authorize() from controllers (actions self-authorize)
- Move customFieldValues eager-loading from controllers into actions
- Create IndexRequest for per_page validation (replaces manual clamping)
- Fix FormatsCustomFields to always return stdClass
- Apply layered rate limiting: 600/min per team, 300/min reads, 60/min writes"
```

- [ ] **Step 6: Push and verify CI**

```bash
git push
gh run list --branch feat/rest-api-mcp-server -L 1
```
