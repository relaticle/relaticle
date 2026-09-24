# Activity Source Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `sdd-lean` (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every activity row records the channel that made the change (web, api, mcp, chat, import, system), and a new record's `creation_source` reads the same value.

**Architecture:** `App\Support\CurrentSource` keeps the current `CreationSource` in a hidden Laravel `Context` key. Each entry point sets it once: route middleware for the API, `RelaticleServer::runMethodHandle()` for MCP, `CurrentSource::during()` for chat approvals, imports, and the demo reset. The activity `beforeLogging` hook and a `HasCreator` trait initializer both read it. The Activity page, the record timeline, and both `ListActivityTool` classes display it.

**Tech Stack:** Laravel 13.33, Filament 5, Livewire 4, laravel/mcp 1.0, spatie/laravel-activitylog v5, Pest 4, PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-09-24-activity-source-design.md`

## Global Constraints

- PostgreSQL only. No migration is needed; if one appears, `up()` only.
- Never write the em-dash character (U+2014) in code, lang files, tests, commits, or PR text. The empty-value data glyph is reached only through `ActivityValue::EMPTY`.
- Comments: 90%+ of the diff has none; a comment is at most 2 lines and states a non-obvious why. No comments in tests. Docblocks carry types and generics only.
- Every parameter, return, and closure is typed (`composer test:type-coverage` must stay at 100%). No new PHPStan ignores.
- User-facing strings go through `__()`; new keys live in `lang/en/workspaces.php` (only `en` has that file).
- Tests live in `tests/Feature/`, go through real entry points, declare `mutates(...)`, and carry no comments.
- Pest test files share one global function namespace. Before adding a top-level helper function, `grep -rn "function <name>(" tests/` must return nothing.
- Before each commit: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run` (apply with `vendor/bin/rector` if it suggests changes), `vendor/bin/phpstan analyse --memory-limit=2G`.
- Conventional commits, lowercase, subject under 72 characters, no AI attribution. Branch `issue-831`. Stage only the files the task names; `public/css/**` has unrelated local changes that must never be staged.

## Review Focus

1. A write in the same request after a chat approval must stamp `web` again, not `chat`. Pinned in Task 1.
2. An MCP call over the real `/mcp` HTTP route, not only the test harness, stamps `mcp`. Pinned in Task 1.
3. A writer after an import that threw, in the same sync request, stamps `web`, not `import`. Pinned in Task 2.
4. A writer that states `creation_source` itself (SystemAdmin form, `BaseModelSeeder`, factories) keeps its value whatever the current channel. Pinned in Task 3.
5. A stored source this build does not know (for example `fax`), or none at all, renders as no source on the page and never errors. Pinned in Task 4.

---

### Task 1: Stamp the channel on every activity row

**Files:**
- Create: `app/Support/CurrentSource.php`
- Create: `app/Http/Middleware/SetCurrentSource.php`
- Create: `tests/Feature/ActivityLog/ActivitySourceTest.php`
- Modify: `app/Models/ActivityLog/Activity.php` (add `SOURCE_PROPERTY`)
- Modify: `app/Providers/AppServiceProvider.php:323-351` (`configureActivityLog()`)
- Modify: `routes/api.php:20`
- Modify: `app/Mcp/Servers/RelaticleServer.php`
- Modify: `packages/Chat/src/Services/PendingActionService.php:224` and `:351`

**Interfaces:**
- Produces: `CurrentSource::set(CreationSource $source): void`, `CurrentSource::during(CreationSource $source, Closure $callback): mixed` (returns the callback's value), `CurrentSource::get(): CreationSource` (defaults to `CreationSource::WEB`), `Activity::SOURCE_PROPERTY = 'source'`, middleware usage `SetCurrentSource::class.':api'`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ActivityLog/ActivitySourceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Company\UpdateCompany;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Http\Middleware\SetCurrentSource;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Company\UpdateCompanyTool;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentSource;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;

mutates(CurrentSource::class, SetCurrentSource::class, RelaticleServer::class, PendingActionService::class);

beforeEach(function (): void {
    Bus::fake();
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->company = Company::factory()->for($this->workspace)->create(['name' => 'Acme']);
    Activity::withoutGlobalScopes()->delete();
});

/** @return list<string|null> */
function sourcesOfCompanyUpdates(Company $company): array
{
    return Activity::withoutGlobalScopes()
        ->where('subject_id', $company->getKey())
        ->where('event', 'updated')
        ->orderBy('id')
        ->get()
        ->map(fn (Activity $row): ?string => $row->properties[Activity::SOURCE_PROPERTY] ?? null)
        ->all();
}

function approveCompanyRenameInChat(User $user, Company $company, string $name): void
{
    $proposal = PendingAction::query()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => null,
        'action_class' => UpdateCompany::class,
        'operation' => PendingActionOperation::Update,
        'entity_type' => 'company',
        'action_data' => ['_record_id' => (string) $company->getKey(), '_model_class' => Company::class, 'name' => $name],
        'display_data' => ['title' => 'Update Company', 'summary' => "Rename to {$name}", 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    resolve(PendingActionService::class)->approve($proposal, $user);
}

it('stamps each channel that edits one record with its own source', function (): void {
    livewire(ListCompanies::class)
        ->callAction(TestAction::make('edit')->table($this->company), data: ['name' => 'Via Panel'])
        ->assertHasNoActionErrors();

    Sanctum::actingAs($this->user);
    $this->putJson("/api/v1/companies/{$this->company->getKey()}", ['name' => 'Via Api'])->assertOk();

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateCompanyTool::class, ['id' => $this->company->getKey(), 'name' => 'Via Mcp'])
        ->assertOk();

    approveCompanyRenameInChat($this->user, $this->company, 'Via Chat');

    expect(sourcesOfCompanyUpdates($this->company))->toBe(['web', 'api', 'mcp', 'chat']);
});

it('stamps a delete through the api as api', function (): void {
    Sanctum::actingAs($this->user);

    $this->deleteJson("/api/v1/companies/{$this->company->getKey()}")->assertNoContent();

    $row = Activity::withoutGlobalScopes()
        ->where('subject_id', $this->company->getKey())
        ->where('event', 'deleted')
        ->sole();

    expect($row->properties[Activity::SOURCE_PROPERTY])->toBe('api');
});

it('stamps an mcp call that arrives over http', function (): void {
    Sanctum::actingAs($this->user, ['*']);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'update-company-tool',
            'arguments' => ['id' => $this->company->getKey(), 'name' => 'Over Http'],
        ],
    ])->assertOk();

    expect($this->company->refresh()->name)->toBe('Over Http')
        ->and(sourcesOfCompanyUpdates($this->company))->toBe(['mcp']);
});

it('stamps a write after a chat approval in the same request as web again', function (): void {
    approveCompanyRenameInChat($this->user, $this->company, 'Via Chat');

    $this->company->refresh()->update(['name' => 'By Hand']);

    expect(sourcesOfCompanyUpdates($this->company))->toBe(['chat', 'web']);
});
```

Check the helper names are free first: `grep -rn "function sourcesOfCompanyUpdates(\|function approveCompanyRenameInChat(" tests/` must print nothing.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/ActivityLog/ActivitySourceTest.php`
Expected: FAIL. `CurrentSource`/`SetCurrentSource` do not exist yet, so the file errors on the missing classes. Once they exist, the source arrays hold `null` values.

If `stamps an mcp call that arrives over http` fails with 401 or 403 instead, the Sanctum path to `/mcp` is refused in tests. Switch that test to the Passport setup from `tests/Feature/Mcp/OAuthWorkspacePickerTest.php:174-196`: create a `Laravel\Passport\Client`, call `Passport::actingAs($this->user, scopes: ['*'])`, then set `$this->user->currentAccessToken()->workspace_id = $this->workspace->getKey()`.

- [ ] **Step 3: Create the holder**

`app/Support/CurrentSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CreationSource;
use Closure;
use Illuminate\Support\Facades\Context;

final class CurrentSource
{
    private const string KEY = 'current_source';

    public static function set(CreationSource $source): void
    {
        Context::addHidden(self::KEY, $source->value);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function during(CreationSource $source, Closure $callback): mixed
    {
        return Context::scope($callback, hidden: [self::KEY => $source->value]);
    }

    public static function get(): CreationSource
    {
        $value = Context::getHidden(self::KEY);

        if (! is_string($value)) {
            return CreationSource::WEB;
        }

        return CreationSource::tryFrom($value) ?? CreationSource::WEB;
    }
}
```

- [ ] **Step 4: Create the API middleware and register it**

`app/Http/Middleware/SetCurrentSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\CreationSource;
use App\Support\CurrentSource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetCurrentSource
{
    public function handle(Request $request, Closure $next, string $source): Response
    {
        CurrentSource::set(CreationSource::from($source));

        return $next($request);
    }
}
```

In `routes/api.php`, import `App\Http\Middleware\SetCurrentSource` and add it to the v1 stack after `EnsureTokenHasAbility::class`:

```php
    ->middleware([ForceJsonResponse::class, 'auth:sanctum', 'throttle:api', EnsureTokenHasAbility::class, SetCurrentSource::class.':api', SetApiWorkspaceContext::class, EnsureHostedWorkspaceAccess::class])
```

- [ ] **Step 5: Mark every MCP method call**

In `app/Mcp/Servers/RelaticleServer.php`, add imports `App\Enums\CreationSource`, `App\Support\CurrentSource`, `Laravel\Mcp\Server\ServerContext`, `Laravel\Mcp\Server\Transport\JsonRpcRequest`, `Laravel\Mcp\Server\Transport\JsonRpcResponse`, `Override` (confirm the two transport namespaces with `grep -n "^use" vendor/laravel/mcp/src/Server.php`). Add the method:

```php
    #[Override]
    protected function runMethodHandle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        // A tool that yields a streamed result would run after this scope closes; none does.
        return CurrentSource::during(CreationSource::MCP, fn (): iterable|JsonRpcResponse => parent::runMethodHandle($request, $context));
    }
```

Confirm no MCP tool streams: `grep -rln "yield" app/Mcp` prints nothing.

- [ ] **Step 6: Mark chat approvals**

In `packages/Chat/src/Services/PendingActionService.php`, import `App\Support\CurrentSource` (`CreationSource` is already imported). Replace line 224:

```php
                $result = CurrentSource::during(CreationSource::CHAT, fn (): mixed => $this->executeAction($pendingAction, $user, $excludedFields));
```

Replace line 351:

```php
                $model = CurrentSource::during(CreationSource::CHAT, fn (): Model => $this->executeBatchItem($locked, $user, $this->withoutExcludedFields($records[$index], $excludedFields, $locked->entity_type)));
```

- [ ] **Step 7: Stamp the row**

In `app/Models/ActivityLog/Activity.php`, add inside the class, above `workspace()`:

```php
    public const string SOURCE_PROPERTY = 'source';
```

In `AppServiceProvider::configureActivityLog()`, import `App\Support\CurrentSource`, then add directly after the `if ($import->id() !== null ...) { ... }` block:

```php
            $activity->properties = ($activity->properties ?? new Collection)
                ->put(ActivityModel::SOURCE_PROPERTY, CurrentSource::get()->value);
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/ActivityLog/ActivitySourceTest.php`
Expected: 4 passed.

Then the neighbours this touches: `php artisan test --compact --parallel tests/Feature/ActivityLog tests/Feature/Mcp tests/Feature/Api/V1 tests/Feature/Chat`
Expected: all pass.

- [ ] **Step 9: Gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G
git add app/Support/CurrentSource.php app/Http/Middleware/SetCurrentSource.php app/Models/ActivityLog/Activity.php app/Providers/AppServiceProvider.php routes/api.php app/Mcp/Servers/RelaticleServer.php packages/Chat/src/Services/PendingActionService.php tests/Feature/ActivityLog/ActivitySourceTest.php
git commit -m "feat(activity-log): stamp the channel on every activity row"
```

---

### Task 2: Run imports and the demo reset under their own channel

**Files:**
- Modify: `packages/ImportWizard/src/Jobs/ExecuteImportJob.php:117-228` (`handle()`), `:230-257` (`failed()`)
- Modify: `app/Console/Commands/ResetDemoAccountCommand.php:116-141`
- Test: `tests/Feature/ActivityLog/ImportActivityTest.php`
- Test: `tests/Feature/Commands/ResetDemoAccountCommandTest.php`

**Interfaces:**
- Consumes: `CurrentSource::during()`, `Activity::SOURCE_PROPERTY` (Task 1).
- Produces: every row an import writes, its summary row included, carries `import`; every row the demo reset writes carries `system`. Task 3 relies on imports creating records inside `during(CreationSource::IMPORT)`.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/ActivityLog/ImportActivityTest.php`, add after `stamps every record row of one import with the import and its file`:

```php
it('stamps every row an import writes, its summary included, as an import', function (): void {
    runThreePersonImport($this);

    $sources = Activity::query()->withoutGlobalScopes()->get()->pluck('properties.source')->unique()->values()->all();

    expect($sources)->toBe(['import']);
});
```

In `records an import that exhausts its attempts as one failed entry`, extend the final `expect(...)` chain on `$summary` with:

```php
        ->and($summary->properties['source'])->toBe('import')
```

In `stops stamping once the import job is over` and in `stops stamping once the import job has thrown`, replace the final line with:

```php
    expect($row->properties->has('import_id'))->toBeFalse()
        ->and($row->properties['source'])->toBe('web');
```

In `tests/Feature/Commands/ResetDemoAccountCommandTest.php`, add at the end (`Bus` and `Activity` are already imported):

```php
it('stamps the rebuilt workspace, its records and their activity, as system', function (): void {
    Bus::fake();

    $this->artisan('demo:reset', ['--password' => 'runtime-secret-seven'])->assertSuccessful();

    $workspace = User::query()->where('email', ResetDemoAccountCommand::EMAIL)->firstOrFail()->currentWorkspace;

    $activitySources = Activity::query()->withoutGlobalScopes()
        ->where('workspace_id', $workspace->getKey())
        ->get()
        ->pluck('properties.source')
        ->unique()
        ->values()
        ->all();

    $recordSources = Company::query()
        ->where('workspace_id', $workspace->getKey())
        ->get()
        ->pluck('creation_source.value')
        ->unique()
        ->values()
        ->all();

    expect($activitySources)->toBe(['system'])
        ->and($recordSources)->toBe(['system']);
});
```

`$recordSources` passes already, through the explicit `CreationSource::SYSTEM` arguments. It pins the value Task 3 must keep once those arguments are gone.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/ActivityLog/ImportActivityTest.php tests/Feature/Commands/ResetDemoAccountCommandTest.php`
Expected: the new import test and the failed-summary test fail with `web` where `import` was expected. The demo test fails on `$activitySources` with `['web']`. The two `stops stamping` tests already pass, since nothing sets `import` yet. They pin the restore that Step 3 must keep.

- [ ] **Step 3: Wrap the import job**

In `ExecuteImportJob`, import `App\Support\CurrentSource` (`CreationSource` is already imported). Rename the current `handle()` to `private function runImport(): void`, keeping its body unchanged, and add above it:

```php
    public function handle(): void
    {
        CurrentSource::during(CreationSource::IMPORT, $this->runImport(...));
    }
```

In `failed()`, replace `$this->logImportSummary($import, $results, self::FAILED_EVENT);` with:

```php
        CurrentSource::during(CreationSource::IMPORT, function () use ($import, $results): void {
            $this->logImportSummary($import, $results, self::FAILED_EVENT);
        });
```

- [ ] **Step 4: Wrap the demo rebuild**

In `ResetDemoAccountCommand`, import `App\Support\CurrentSource`. Replace the `$seededCompanies = new EloquentCollection;` line and the whole `try { ... }` body (keep the `finally`) with:

```php
        try {
            CurrentSource::during(CreationSource::SYSTEM, function () use ($user, $workspace): void {
                $seededCompanies = DB::transaction(fn (): EloquentCollection => $this->rebuildWorkspace($user, $workspace));

                $this->fetchCompanyLogos($seededCompanies);
            });
        } finally {
            Auth::forgetUser();
            TenantContextService::setTenantId($previousTenantId);
        }
```

Add the extracted method below `handle()`:

```php
    /** @return EloquentCollection<int, Company> */
    private function rebuildWorkspace(User $user, Workspace $workspace): EloquentCollection
    {
        $this->resetReviewerWorkspace($workspace);

        throw_unless(
            $this->onboardSeedManager->generateFor($user, $workspace, 'sales'),
            RuntimeException::class,
            'Reviewer workspace fixtures could not be generated.',
        );

        $seededCompanies = Company::query()->where('workspace_id', $workspace->getKey())->get();

        $this->resetAiCredits($workspace);
        $this->shapeOpportunities($user, $workspace);
        $this->shapeTasks($user, $workspace);
        $this->expandWorkspace($user, $workspace);
        $this->ensureInactiveField($user, $workspace);
        $this->recordCreationActivity($user, $workspace);

        return $seededCompanies;
    }
```

Dispatching the logo jobs inside the scope carries `system` into their queued payloads through Context.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/ActivityLog/ImportActivityTest.php tests/Feature/Commands/ResetDemoAccountCommandTest.php tests/Feature/ImportWizard`
Expected: all pass.

- [ ] **Step 6: Gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G
git add packages/ImportWizard/src/Jobs/ExecuteImportJob.php app/Console/Commands/ResetDemoAccountCommand.php tests/Feature/ActivityLog/ImportActivityTest.php tests/Feature/Commands/ResetDemoAccountCommandTest.php
git commit -m "feat(activity-log): stamp imports and the demo reset with their channel"
```

---

### Task 3: Derive a record's creation_source from the channel

**Files:**
- Modify: `app/Models/Concerns/HasCreator.php`
- Modify: `app/Models/Company.php:68-73`, `app/Models/People.php:63-68`, `app/Models/Opportunity.php:62-67`, `app/Models/Task.php:63-68`, `app/Models/Note.php:56-61` (drop the `$attributes` default)
- Modify: `app/Actions/Company/CreateCompany.php`, `app/Actions/People/CreatePeople.php`, `app/Actions/Opportunity/CreateOpportunity.php`, `app/Actions/Task/CreateTask.php`, `app/Actions/Note/CreateNote.php`
- Modify: `app/Http/Controllers/Api/V1/{Companies,People,Opportunities,Tasks,Notes}Controller.php`, `app/Mcp/Tools/BaseCreateTool.php:87`, `packages/Chat/src/Services/PendingActionService.php:511,983`, `app/Console/Commands/ResetDemoAccountCommand.php:397,423,453,490,513`
- Modify: `packages/ImportWizard/src/Importers/BaseImporter.php:247`, `packages/ImportWizard/src/Jobs/ExecuteImportJob.php:1075`
- Modify (test migration): `tests/Feature/Chat/TaskLinkedCreationTest.php`, `tests/Feature/Chat/NoteLinkedCreationTest.php`, `tests/Feature/Chat/OpportunityLinkedCreationTest.php`, `tests/Feature/Chat/AiCreatedRecordsHaveOwnerTest.php`
- Test: `tests/Feature/ActivityLog/ActivitySourceTest.php`, `tests/Feature/Chat/BatchCreateApprovalTest.php`, `tests/Feature/Filament/App/Resources/CompanyResourceTest.php`

**Interfaces:**
- Consumes: `CurrentSource::get()` (Task 1); imports run inside `during(IMPORT)` (Task 2); chat approvals inside `during(CHAT)` (Task 1).
- Produces: `CreateCompany::execute(User $user, array $data): Company`, and the same two-parameter signature on `CreatePeople`, `CreateOpportunity`, `CreateTask`, `CreateNote`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ActivityLog/ActivitySourceTest.php` (add import `App\Enums\CreationSource`):

```php
it('records the channel a record was created through, unless the writer states one', function (): void {
    $posted = CurrentSource::during(CreationSource::API, fn (): Company => Company::factory()->for($this->workspace)->create());
    $stated = CurrentSource::during(CreationSource::API, fn (): Company => Company::factory()->for($this->workspace)->create(['creation_source' => CreationSource::SYSTEM]));
    $typed = Company::factory()->for($this->workspace)->create();

    expect($posted->creation_source)->toBe(CreationSource::API)
        ->and($stated->creation_source)->toBe(CreationSource::SYSTEM)
        ->and($typed->creation_source)->toBe(CreationSource::WEB);
});
```

Append to `tests/Feature/Chat/BatchCreateApprovalTest.php` (add import `App\Enums\CreationSource`):

```php
it('records tasks created by approved proposals as created through chat', function (): void {
    resolve(PendingActionService::class)->approve(makeSingleProposal($this->convId, $this->user, 'Single From Chat'), $this->user);
    resolve(PendingActionService::class)->approveItem(makeBatchProposal($this->convId, $this->user, [['title' => 'Batch From Chat']]), $this->user, 0);

    expect(Task::query()->where('title', 'Single From Chat')->sole()->creation_source)->toBe(CreationSource::CHAT)
        ->and(Task::query()->where('title', 'Batch From Chat')->sole()->creation_source)->toBe(CreationSource::CHAT);
});
```

Append to `tests/Feature/Filament/App/Resources/CompanyResourceTest.php` (add import `App\Enums\CreationSource`):

```php
it('records a company created in the panel as created on the web', function (): void {
    livewire(ListCompanies::class)
        ->callAction('create', data: ['name' => 'Panel Made'])
        ->assertHasNoActionErrors();

    expect(Company::query()->where('name', 'Panel Made')->sole()->creation_source)->toBe(CreationSource::WEB);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/ActivityLog/ActivitySourceTest.php tests/Feature/Chat/BatchCreateApprovalTest.php tests/Feature/Filament/App/Resources/CompanyResourceTest.php`
Expected: `records the channel a record was created through...` fails with `WEB` where `API` was expected. The chat and panel tests already pass through the explicit arguments. They pin the behaviour Steps 3 and 4 must keep.

- [ ] **Step 3: Add the initializer and drop the static defaults**

In `app/Models/Concerns/HasCreator.php`, import `App\Support\CurrentSource` and add:

```php
    public function initializeHasCreator(): void
    {
        $this->attributes['creation_source'] = CurrentSource::get()->value;
    }
```

In each of `Company`, `People`, `Opportunity`, `Task`, `Note`, delete the whole `$attributes` property with its `@var` docblock; `'creation_source' => CreationSource::WEB` is its only entry. Keep `use App\Enums\CreationSource;` because the `@property` docblock and `casts()` still name it.

- [ ] **Step 4: Remove the per-record source**

In each of the five `Create*` actions, change the signature to `execute(User $user, array $data): <Model>`, delete the `$attributes['creation_source'] = $source;` line, and delete `use App\Enums\CreationSource;`.

Drop the source argument at every caller:
- The five `Api\V1` controllers: `$action->execute($user, $request->validated())`. Delete their now-unused `use App\Enums\CreationSource;`.
- `BaseCreateTool:87`: `$action->execute($user, $validated)`. Delete its unused import.
- `PendingActionService:511` becomes `return $action->execute($user, $record);` and `:983` becomes `return $action->execute($user, $data);`. Keep the import, Task 1 uses `CreationSource::CHAT`.
- `ResetDemoAccountCommand:397,423,453,490,513`: delete the `, CreationSource::SYSTEM` argument from each `execute(` call. Keep the import, Task 2 uses it.
- `BaseImporter:247`: delete `$data['creation_source'] = CreationSource::IMPORT;` and its unused import.
- `ExecuteImportJob:1075`: delete the `'creation_source' => CreationSource::IMPORT,` entry. Keep the import, Task 2 uses it.

Leave `packages/OnboardSeed/src/Support/BaseModelSeeder.php:140` alone: the seed runs inside `Model::withoutEvents()`, and `RemoveSampleData` finds sample records by that stated `SYSTEM`.

Migrate the tests that passed a source: in `TaskLinkedCreationTest.php`, `NoteLinkedCreationTest.php`, `OpportunityLinkedCreationTest.php`, and `AiCreatedRecordsHaveOwnerTest.php`, delete every `CreationSource::CHAT,` argument line inside an `->execute(` call, then delete `use App\Enums\CreationSource;` wherever the file no longer names it.

Verify nothing still passes a source: `grep -rnE "execute\([^;]*CreationSource::" app packages tests` and `grep -rn "CreationSource::[A-Z]*,$" tests/Feature/Chat` both print nothing.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --parallel tests/Feature/ActivityLog tests/Feature/Api/V1 tests/Feature/Mcp tests/Feature/Chat tests/Feature/ImportWizard tests/Feature/Commands/ResetDemoAccountCommandTest.php tests/Feature/Filament/App/Resources tests/Feature/SystemAdmin`
Expected: all pass. That includes the existing `creation_source` assertions in `CompaniesApiTest:66` (`api`), `McpToolFeaturesTest:163-199` (`mcp`), `ExecuteImportJobCoreTest`/`ExecuteImportJobEntityTest` (`import`), and `RemoveSampleDataToolTest` (`system`).

- [ ] **Step 6: Gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G
git add app/Models app/Actions app/Http/Controllers/Api/V1 app/Mcp/Tools/BaseCreateTool.php packages/Chat/src/Services/PendingActionService.php app/Console/Commands/ResetDemoAccountCommand.php packages/ImportWizard tests/Feature
git commit -m "refactor: derive creation_source from the current channel"
```

Before committing, `git status --short` must show no `public/css` path staged.

---

### Task 4: Show and filter the source on the Activity page

**Files:**
- Modify: `app/Models/ActivityLog/Activity.php`
- Modify: `app/Filament/Pages/Workspace/ActivityLog.php` (columns after `causer.name`, filters after `causer`)
- Modify: `lang/en/workspaces.php:174-188`
- Test: `tests/Feature/Workspaces/WorkspaceActivityLogTest.php`

**Interfaces:**
- Consumes: `Activity::SOURCE_PROPERTY`, `CurrentSource::during()`.
- Produces: `Activity::sourceFrom(array $properties): ?CreationSource` (null for a missing or unknown value), scope `Activity::query()->fromSource(CreationSource $source)`. Tasks 5 and 6 read rows through `sourceFrom()`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Workspaces/WorkspaceActivityLogTest.php` (add imports `App\Enums\CreationSource`, `App\Support\CurrentSource`):

```php
test('each row names the channel it came through', function (): void {
    $typed = Company::factory()->for($this->workspace)->create(['name' => 'Typed In Co']);
    $posted = CurrentSource::during(CreationSource::API, fn (): Company => Company::factory()->for($this->workspace)->create(['name' => 'Posted Co']));

    livewire(ActivityLog::class)
        ->assertTableColumnStateSet('source', CreationSource::WEB, Activity::withoutGlobalScopes()->where('subject_id', $typed->getKey())->sole())
        ->assertTableColumnStateSet('source', CreationSource::API, Activity::withoutGlobalScopes()->where('subject_id', $posted->getKey())->sole());
});

test('a row with no source, or one this build does not know, shows none', function (): void {
    $legacy = Company::factory()->for($this->workspace)->create(['name' => 'Legacy Co']);
    $unknown = Company::factory()->for($this->workspace)->create(['name' => 'Unknown Co']);

    $legacyRow = Activity::withoutGlobalScopes()->where('subject_id', $legacy->getKey())->sole();
    $unknownRow = Activity::withoutGlobalScopes()->where('subject_id', $unknown->getKey())->sole();
    $legacyRow->update(['properties' => []]);
    $unknownRow->update(['properties' => ['source' => 'fax']]);

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertSee('Legacy Co')
        ->assertTableColumnStateSet('source', null, $legacyRow)
        ->assertTableColumnStateSet('source', null, $unknownRow);
});

test('it filters down to one channel', function (): void {
    Company::factory()->for($this->workspace)->create(['name' => 'Typed In Co']);
    CurrentSource::during(CreationSource::API, fn (): Company => Company::factory()->for($this->workspace)->create(['name' => 'Posted Co']));

    livewire(ActivityLog::class)
        ->filterTable('source', CreationSource::API->value)
        ->assertSee('Posted Co')
        ->assertDontSee('Typed In Co');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Workspaces/WorkspaceActivityLogTest.php --filter="channel|does not know"`
Expected: FAIL, the table has no `source` column or filter.

- [ ] **Step 3: Add the reader and the scope**

In `app/Models/ActivityLog/Activity.php`, import `App\Enums\CreationSource`, `Illuminate\Database\Eloquent\Attributes\Scope`, `Illuminate\Database\Eloquent\Builder`, and add below `SOURCE_PROPERTY`:

```php
    /** @param  array<array-key, mixed>  $properties */
    public static function sourceFrom(array $properties): ?CreationSource
    {
        $source = $properties[self::SOURCE_PROPERTY] ?? null;

        return is_string($source) ? CreationSource::tryFrom($source) : null;
    }
```

and below `workspace()`:

```php
    /** @param  Builder<self>  $query */
    #[Scope]
    protected function fromSource(Builder $query, CreationSource $source): void
    {
        $query->where('properties->'.self::SOURCE_PROPERTY, $source->value);
    }
```

- [ ] **Step 4: Add the column, filter, and strings**

In `lang/en/workspaces.php`, add `'source' => 'Source',` to both `activity.columns` (after `causer`) and `activity.filters` (after `causer`).

In `app/Filament/Pages/Workspace/ActivityLog.php`, import `App\Enums\CreationSource`. Add after the `causer.name` column:

```php
                TextColumn::make('source')
                    ->label(__('workspaces.activity.columns.source'))
                    ->state(fn (Activity $record): ?CreationSource => Activity::sourceFrom($record->properties?->toArray() ?? []))
                    ->badge()
                    ->placeholder(ActivityValue::EMPTY),
```

Add after the `causer` `SelectFilter`:

```php
                SelectFilter::make('source')
                    ->label(__('workspaces.activity.filters.source'))
                    ->options(CreationSource::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->fromSource(CreationSource::from((string) $data['value']))
                        : $query),
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Workspaces/WorkspaceActivityLogTest.php tests/Feature/ActivityLog`
Expected: all pass.

- [ ] **Step 6: Gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G
git add app/Models/ActivityLog/Activity.php app/Filament/Pages/Workspace/ActivityLog.php lang/en/workspaces.php tests/Feature/Workspaces/WorkspaceActivityLogTest.php
git commit -m "feat(activity-log): show and filter the channel on the activity page"
```

---

### Task 5: Name non-web channels in the record timeline

**Files:**
- Modify: `app/Support/ActivityLog/MergedActivityRenderer.php`
- Modify: `resources/views/activity-log/merged-activity.blade.php` (the `@php` var docblocks and the block after the `$importFile` paragraph)
- Modify: `lang/en/workspaces.php:213`
- Test: `tests/Feature/ActivityLog/ActivitySourceTest.php`

**Interfaces:**
- Consumes: `Activity::sourceFrom()` (Task 4).
- Produces: view variable `$viaSource` (`?string`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ActivityLog/ActivitySourceTest.php` (add import `App\Support\ActivityLog\MergedActivityRenderer`; add `MergedActivityRenderer::class` to the `mutates(...)` call):

```php
it('names the channel of an api change in the record timeline', function (): void {
    CurrentSource::during(CreationSource::API, fn (): bool => $this->company->update(['name' => 'Posted']));

    $html = (new MergedActivityRenderer)->render($this->company->timeline()->get()->first())->render();

    expect($html)->toContain(__('workspaces.activity.via_source', ['source' => CreationSource::API->getLabel()]));
});

it('leaves the channel line off web and legacy timeline entries', function (): void {
    $this->company->update(['name' => 'Typed']);

    $webHtml = (new MergedActivityRenderer)->render($this->company->timeline()->get()->first())->render();

    Activity::withoutGlobalScopes()->where('subject_id', $this->company->getKey())->update(['properties' => '{}']);

    $legacyHtml = (new MergedActivityRenderer)->render($this->company->timeline()->get()->first())->render();

    expect($webHtml)->not->toContain('Via ')
        ->and($legacyHtml)->not->toContain('Via ');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/ActivityLog/ActivitySourceTest.php --filter=timeline`
Expected: the api test fails, since the output lacks the line and the lang key is missing. The web/legacy test passes already and pins the omission.

- [ ] **Step 3: Implement**

In `lang/en/workspaces.php`, add after `'via_import' => 'Via import :file',`:

```php
        'via_source' => 'Via :source',
```

In `MergedActivityRenderer`, import `App\Enums\CreationSource` and `App\Models\ActivityLog\Activity`. Add to the view data array in `render()`:

```php
            'viaSource' => $this->viaSource(Activity::sourceFrom($entry->properties)),
```

and the method:

```php
    private function viaSource(?CreationSource $source): ?string
    {
        if (! $source instanceof CreationSource) {
            return null;
        }

        if (in_array($source, [CreationSource::WEB, CreationSource::IMPORT], true)) {
            return null;
        }

        return __('workspaces.activity.via_source', ['source' => $source->getLabel()]);
    }
```

Import rows are skipped because they already print "Via import <file>".

In `merged-activity.blade.php`, add `/** @var string|null $viaSource */` next to the `$importFile` docblock, and directly after the `@if (filled($importFile ?? null)) ... @endif` block:

```blade
        @if (filled($viaSource ?? null))
            <p class="text-[12px] leading-5 text-gray-500 dark:text-gray-400">{{ $viaSource }}</p>
        @endif
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/ActivityLog`
Expected: all pass.

- [ ] **Step 5: Gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G
git add app/Support/ActivityLog/MergedActivityRenderer.php resources/views/activity-log/merged-activity.blade.php lang/en/workspaces.php tests/Feature/ActivityLog/ActivitySourceTest.php
git commit -m "feat(activity-log): name non-web channels in the record timeline"
```

---

### Task 6: Return the source from both ListActivityTool classes

**Files:**
- Modify: `app/Mcp/Tools/ListActivityTool.php:33` (`#[Description]`), `:256-269` (`entry()`)
- Modify: `packages/Chat/src/Tools/Activity/ListActivityTool.php:29` (`ActivityEntry` type), `:64-70` (`description()`), `:338-350` (`entry()`)
- Test: `tests/Feature/Mcp/McpReadToolsTest.php`, `tests/Feature/Chat/ListActivityToolTest.php`

**Interfaces:**
- Consumes: `Activity::sourceFrom()` (Task 4), `CurrentSource::during()` (Task 1).
- Produces: each entry carries `'source' => string|null`, a `CreationSource` value or null.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Mcp/McpReadToolsTest.php` (add imports `App\Enums\CreationSource`, `App\Models\ActivityLog\Activity`, `App\Support\CurrentSource`):

```php
it('names the channel of each change in the activity it returns', function (): void {
    $company = Company::withoutEvents(fn (): Company => Company::factory()
        ->recycle([$this->user, $this->workspace])
        ->create(['name' => 'Before']));

    $this->actingAs($this->user);
    CurrentSource::during(CreationSource::API, fn (): Company => resolve(UpdateCompany::class)->execute($this->user, $company, ['name' => 'After']));

    RelaticleServer::actingAs($this->user)
        ->tool(ListActivityTool::class, ['record_type' => 'company', 'record_id' => $company->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('items.0.source', 'api')
            ->etc());

    Activity::withoutGlobalScopes()->where('subject_id', $company->getKey())->update(['properties' => '{}']);

    RelaticleServer::actingAs($this->user)
        ->tool(ListActivityTool::class, ['record_type' => 'company', 'record_id' => $company->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('items.0.source', null)
            ->etc());
});
```

Append to `tests/Feature/Chat/ListActivityToolTest.php` (add imports `App\Enums\CreationSource`, `App\Models\Company`, `App\Support\CurrentSource`):

```php
it('names the channel of each change, and none for a row that predates it', function (): void {
    $user = $this->user;

    $company = app(CreateCompany::class)->execute($user, ['name' => 'Old Co']);

    nextActivityRequest();

    CurrentSource::during(CreationSource::API, fn (): Company => app(UpdateCompany::class)->execute($user, $company, ['name' => 'New Co']));

    $payload = activityPayload(['record_type' => 'company', 'record_id' => (string) $company->getKey()]);

    expect(array_column($payload['data'], 'source'))->toBe(['api', 'web']);

    Activity::withoutGlobalScopes()->where('subject_id', $company->getKey())->update(['properties' => '{}']);

    $legacy = activityPayload(['record_type' => 'company', 'record_id' => (string) $company->getKey()]);

    expect(array_column($legacy['data'], 'source'))->toBe([null, null]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Mcp/McpReadToolsTest.php tests/Feature/Chat/ListActivityToolTest.php --filter="channel"`
Expected: FAIL, entries have no `source` key.

- [ ] **Step 3: Implement**

MCP `ListActivityTool::entry()`: add after `'by' => ...`:

```php
            'source' => Activity::sourceFrom($base->properties?->toArray() ?? [])?->value,
```

and change the `#[Description]` to:

```php
#[Description('List who changed which CRM records, through which channel (web, api, mcp, chat, import, or system), when they changed them, and the field-level differences. Results use the caller timezone.')]
```

Chat `ListActivityTool`: add `source: string|null` after `by: string` in the `@phpstan-type ActivityEntry` shape; in `entry()` add after `'by' => ...`:

```php
            'source' => Activity::sourceFrom($base->properties?->toArray() ?? [])?->value,
```

and change the first sentence in `description()` to:

```php
        return 'Read the change history of CRM records: who changed what, through which channel (web, api, mcp, chat, import, or system), and when.'
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --parallel tests/Feature/Mcp tests/Feature/Chat`
Expected: all pass. If `CrmAssistantInstructionsTest` asserts the old description sentence, update that assertion to the new sentence. The description is the fact under test, not a stale value.

- [ ] **Step 5: Gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G
git add app/Mcp/Tools/ListActivityTool.php packages/Chat/src/Tools/Activity/ListActivityTool.php tests/Feature/Mcp/McpReadToolsTest.php tests/Feature/Chat/ListActivityToolTest.php
git commit -m "feat(activity-log): return the channel from both activity tools"
```

---

### Task 7: Final gates and real-stack verification

**Files:**
- Modify only if a gate fails.

- [ ] **Step 1: Sweep for stray source writes**

Run: `grep -rn "CreationSource::" app packages | grep -vE "app/Enums/|SystemAdmin/src/Filament/Widgets|getColor|getLabel"`
Expected: writes only in `CurrentSource`, `SetCurrentSource`, `RelaticleServer`, `PendingActionService` (the two `during(CHAT)` calls), `ExecuteImportJob` and `ResetDemoAccountCommand` (`during(...)`), the SystemAdmin form defaults, `BaseModelSeeder`, and read-side filters (`RemoveSampleData`, `WorkspaceActivationFacts`, `DigestService`, list tools). Anything else is a leftover per-record write: remove it.

- [ ] **Step 2: Full deterministic gates**

```bash
composer test:lint
vendor/bin/phpstan analyse --memory-limit=2G
composer test:type-coverage
composer test:pest:full
php artisan test tests/Browser
```

Expected: all green. `composer test:pest` excludes the Browser suite, so run it separately. Do not label a failure "pre-existing" without a CI run on `main` showing it.

- [ ] **Step 3: Chat on the production-shaped stack**

With Horizon running, `QUEUE_CONNECTION=redis`, and Reverb up, use agent-browser against the local app:
1. Ask the assistant to create a company and to rename an existing one. Approve both cards.
2. Open the workspace Activity page: both rows show the "AI Chat" badge.
3. Open the renamed company's timeline: the entry reads "Via AI Chat".
4. The created company's list row shows creation source "AI Chat".

- [ ] **Step 4: UI screenshots**

Use agent-browser to capture the Activity page (Source column, Source filter open) and a record timeline with a "Via API" line and a legacy entry. Take each in light and dark mode, plus the Activity page at a mobile viewport. Save them under `.context/`.
