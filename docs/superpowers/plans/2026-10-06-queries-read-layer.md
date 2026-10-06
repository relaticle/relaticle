# Queries Read Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `sdd-lean` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move every reusable read out of `Actions` into query classes under `Queries`, with no change a user or an agent can observe.

**Architecture:** Five list queries implement `App\Queries\Contracts\EntityQuery` and share their body through the `App\Queries\Concerns\ListsEntity` trait (three arch tests forbid inheritance in `App`). The trait owns authorization, the workspace bound, the allowlists and pagination. Each transport maps its own input to `App\Data\ListQuery` and calls `paginate()`. The other reads move as renames. New arch, convention and PHPStan gates hold the layout.

**Tech Stack:** PHP 8.5, Laravel, spatie/laravel-query-builder 7, Pest 5, PHPStan with the project's custom rules, Scribe.

**Spec:** `docs/superpowers/specs/2026-10-06-queries-read-layer-design.md`. Read it before any task.

## Global Constraints

- Branch `feat/queries-read-layer`, cut from `origin/feat/crm-filter-language` at `ca5a4d130`. Never commit to another branch. Run `git branch --show-current` before each commit.
- No behavior change. No existing assertion is edited, weakened or deleted. The only edits to existing tests are class references, `mutates()` lines and call sites of a renamed method.
- No new PHPStan ignore. No new entry in `phpstan-method-length.php`. An entry whose method shrinks is lowered. An entry whose class is renamed is renamed with it.
- Query classes are `final readonly`. They take the acting `User`. They never call `auth()` or `request()`, and never write.
- No comments in tests. Comments in source only for a why the code cannot say, two lines at most. Move an existing comment with its code.
- Never the em-dash character (U+2014) in code, docs or commit text. A hook rejects it.
- Commits: conventional, lowercase subject under 72 characters, present tense. No AI attribution anywhere.
- Local test runs are scoped to the files named in the task. Never run `composer test:pest`, `composer test:pest:full`, `composer test:type-coverage` or `composer test:browser`.
- `docs/superpowers` is gitignored. Stage files there with `git add -f`.
- After each task: `vendor/bin/pint --dirty --format agent`.
- The values in each list query (fields, includes, count includes) keep the order they have in the action at `ca5a4d130`. The API reference prints them in that order.

## Review Focus

These are the inputs most likely to break without any task's main tests noticing. Each has a pinning step in the task named.

1. A REST filter that is not an object (`?filter=acme`) must still return 422 on `filter`, not a `TypeError`. Already pinned by `ListFilterTest` "rejects a filter that is not an object". Task 2, Step 3 keeps it green.
2. A cursor request sorted by a custom field must still be refused with today's message, because cursor lists drop custom field sorts. Already pinned by `ListFilterTest` "names the sorts cursor paging takes when asked for a custom field sort". Task 2 keeps it green.
3. `include` sent as a comma string and as a list must both expand on REST. The comma form is pinned in `CompaniesApiTest`. The list form is pinned in Task 2, Step 1.
4. A user the policy refuses must get 403 from the list, now that the check lives in `EntityQuery::for()`. Every `WorkspaceRole` holds `RecordsView`, so no role reaches that branch. `ApiWorkspaceScopingTest` "returns 403 when user has no workspace" pins the refusal, and Task 2, Step 12 runs it.
5. A conversation with a null `workspace_id` must still open. This plan leaves the five inline ownership checks alone. Pinned by leaving `ChatController` and `channels.php` ownership code untouched in Tasks 7 and 8, and checked in Task 8, Step 5.

> **Design change after Task 2 (2026-10-06).** `EntityQuery` is an interface at `app/Queries/Contracts/EntityQuery.php`, and the shared body is the trait `app/Queries/Concerns/ListsEntity.php`. Three arch tests forbid inheritance in `App`. Task 2's code blocks below show the first draft with an abstract base class: the committed code is the source of truth. Tasks 3 to 11 are already written for the interface and the trait.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Data/ListQuery.php` (create) | What a caller asks a list for. `toRequest()` adapts it to Spatie's builder. |
| `app/Queries/EntityQuery.php` (create) | Base list query: authorize, validate, build, paginate. |
| `app/Queries/{Companies,People,Opportunities,Tasks,Notes}/*Query.php` (create) | Four facts per entity. |
| `app/Enums/CrmEntity.php` (modify) | `query()` returns the list query class. |
| `app/Http/Requests/Api/V1/IndexRequest.php` (modify) | `toListQuery()`. |
| `app/Http/Controllers/Api/V1/*Controller.php` (modify, 5) | Inject the query class. |
| `app/Mcp/Tools/BaseListTool.php`, `packages/Chat/src/Tools/BaseReadListTool.php` (modify) | `listQuery()` replaces `buildHttpRequest()` and `actionClass()`. |
| `app/Scribe/Strategies/*.php` (modify, 4) | Read the query class, no source regex. |
| `app/Queries/Crm/CrmSummaryQuery.php`, `app/Queries/Opportunities/OpportunityAggregatesQuery.php`, `app/Queries/CustomFields/EntitiesByFieldValueQuery.php` (move) | Renames of three read actions. |
| `packages/Chat/src/Queries/ConversationsQuery.php`, `ConversationMessagesQuery.php` (create and move) | Chat reads. |
| `tests/Arch/ArchTest.php`, `tests/Arch/ConventionsTest.php`, `phpstan.neon` (modify) | Gates. |
| `.ai/rules/queries.md`, `.ai/guidelines/relaticle/architecture.md` (modify) | Rules. |

---

### Task 1: Capture the baseline

**Files:** none changed.

**Interfaces:**
- Produces: `/tmp/scribe-before/`, the API reference generated at `ca5a4d130`. Task 5 diffs against it.

- [ ] **Step 1: Confirm the branch and a clean tree**

Run: `git branch --show-current && git status --short`
Expected: `feat/queries-read-layer` and no output after it.

- [ ] **Step 2: Generate the API reference twice and learn what varies**

`config/scribe.php` enables `Strategies\Responses\ResponseCalls`, so example responses come from factory data and can differ between two runs of the same code.

```bash
php artisan scribe:generate --no-interaction
rm -rf /tmp/scribe-before /tmp/scribe-again && mkdir /tmp/scribe-before /tmp/scribe-again
cp -R .scribe /tmp/scribe-before/dot-scribe
cp -R storage/app/private/scribe /tmp/scribe-before/storage
php artisan scribe:generate --no-interaction
cp -R .scribe /tmp/scribe-again/dot-scribe
cp -R storage/app/private/scribe /tmp/scribe-again/storage
diff -r /tmp/scribe-before /tmp/scribe-again > /tmp/scribe-noise.diff; wc -l /tmp/scribe-noise.diff
git status --short
```

Expected: both copies exist and `git status` prints nothing (both paths are gitignored). If `storage/app/private/scribe` does not exist, run `find storage -name 'openapi.yaml'` and copy the directory that holds it. Record the path you used.

`/tmp/scribe-noise.diff` is the noise set: what differs with no code change. Read it and write down which files and which kinds of line vary (ids, timestamps, example values). Task 5 accepts a difference only when it is of a kind listed here. If the noise touches a parameter name, a parameter description or their order, the proof in Task 5 cannot work: stop and report.

- [ ] **Step 3: Record the list tests as green**

Run: `php artisan test --compact tests/Feature/Api/V1/ListFilterTest.php tests/Feature/CRM/ListFilterSurfacesTest.php tests/Feature/CRM/SurfaceParityTest.php`
Expected: all pass. A failure here is a fault of the base branch. Stop and report it. Do not fix it in this plan.

---

### Task 2: The list query classes and the REST surface

**Files:**
- Create: `app/Data/ListQuery.php`, `app/Queries/EntityQuery.php`, `app/Queries/Companies/CompaniesQuery.php`, `app/Queries/People/PeopleQuery.php`, `app/Queries/Opportunities/OpportunitiesQuery.php`, `app/Queries/Tasks/TasksQuery.php`, `app/Queries/Notes/NotesQuery.php`
- Modify: `app/Enums/CrmEntity.php`, `app/Http/Requests/Api/V1/IndexRequest.php`, the five controllers in `app/Http/Controllers/Api/V1/` (`CompaniesController`, `PeopleController`, `OpportunitiesController`, `TasksController`, `NotesController`), `tests/Arch/ArchTest.php:369-380`
- Test: `tests/Feature/Api/V1/CompaniesApiTest.php`, `tests/Feature/Api/V1/ListFilterTest.php`

**Interfaces:**
- Produces:
  - `new ListQuery(mixed $filter = null, ?string $sort = null, array|string|null $include = null, array|string|null $fields = null, int $perPage = 15, ?int $page = null, bool $cursor = false, ?string $viewerZone = null)` and `ListQuery::toRequest(): Illuminate\Http\Request`
  - `EntityQuery::for(User $user, ListQuery $list): Spatie\QueryBuilder\QueryBuilder`
  - `EntityQuery::paginate(User $user, ListQuery $list): CursorPaginator|LengthAwarePaginator`
  - static `EntityQuery::entity(): CrmEntity`, `fields(): list<string>`, `includes(): list<string>`, `countIncludes(): array<string, string>`, `sorts(): list<string>`
  - `CrmEntity::query(): class-string<EntityQuery>`
  - `IndexRequest::toListQuery(): ListQuery`
- The five list actions still exist after this task. MCP and chat still call them. Tasks 3 to 5 remove them.

- [ ] **Step 1: Pin the list form of `include`**

`tests/Feature/Api/V1/CompaniesApiTest.php:198` pins `?include=creator` as a comma string. Add its sibling beside that test, inside the same `describe` block if there is one, using the same setup lines the neighbour uses:

```php
    it('expands an include sent as a list', function (): void {
        Sanctum::actingAs($this->user);

        Company::factory()->recycle([$this->user, $this->workspace])->create();

        $this->getJson('/api/v1/companies?include[]=creator&include[]=accountOwner')
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data.0.relationships.creator')
                ->has('included')
                ->etc()
            );
    });
```

- [ ] **Step 2: Run it against the unchanged code**

Run: `php artisan test --compact tests/Feature/Api/V1/CompaniesApiTest.php --filter="expands an include sent as a list"`
Expected: pass. It pins today's behavior. A failure means the test is wrong: read the neighbour at line 198 and match its setup.

- [ ] **Step 3: Confirm the other pinned inputs are green**

Run: `php artisan test --compact tests/Feature/Api/V1/ListFilterTest.php --filter="rejects a filter that is not an object|names the sorts cursor paging takes|rejects a cursor from another sort order"`
Expected: all pass. These three existing tests guard Review Focus items 1 and 2 through the rest of this task.

- [ ] **Step 4: Commit the pinning test**

```bash
git add tests/Feature/Api/V1/CompaniesApiTest.php
git commit -m "test(api): pin an include sent as a list"
```

- [ ] **Step 5: Create `ListQuery`**

`app/Data/ListQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;

final readonly class ListQuery
{
    /**
     * @param  array<int|string, mixed>|string|null  $include
     * @param  array<int|string, mixed>|string|null  $fields
     */
    public function __construct(
        public mixed $filter = null,
        public ?string $sort = null,
        public array|string|null $include = null,
        public array|string|null $fields = null,
        public int $perPage = 15,
        public ?int $page = null,
        public bool $cursor = false,
        public ?string $viewerZone = null,
    ) {}

    public function toRequest(): Request
    {
        return new Request(array_filter([
            'filter' => $this->filter,
            'sort' => $this->sort,
            'include' => $this->include,
            'fields' => $this->fields,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
```

Then confirm these four are every parameter the builder reads that the app allows:

Run: `grep -n "query-builder.parameters" vendor/spatie/laravel-query-builder/src/QueryBuilderRequest.php`
Expected: `include`, `append`, `fields`, `sort`, `filter`. No list action calls `allowedAppends()` (`grep -rn allowedAppends app packages` prints nothing), so `append` is not carried.

- [ ] **Step 6: Create `EntityQuery`**

`app/Queries/EntityQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\ListQuery;
use App\Enums\CrmEntity;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;
use UnexpectedValueException;

/**
 * @template TModel of Model
 */
abstract readonly class EntityQuery
{
    abstract public static function entity(): CrmEntity;

    /** @return list<string> */
    abstract public static function fields(): array;

    /** @return list<string> */
    abstract public static function includes(): array;

    /** @return array<string, string> */
    abstract public static function countIncludes(): array;

    /** @return list<string> */
    public static function sorts(): array
    {
        return [static::entity()->titleColumn(), 'created_at', 'updated_at'];
    }

    /** @return QueryBuilder<TModel> */
    final public function for(User $user, ListQuery $list): QueryBuilder
    {
        $entity = static::entity();
        $model = $entity->model();

        abort_unless($user->can('viewAny', $model), 403);

        FilterTree::validate($list->filter, $entity);

        return QueryBuilder::for(
            $model::query()->withCustomFieldValues()->whereBelongsTo($user->currentWorkspace),
            $list->toRequest(),
        )
            ->allowedFilters(...new EntityFilters($user, $list->viewerZone)->for($entity))
            ->allowedFields(...static::fields())
            ->allowedIncludes(...static::includes(), ...$this->countAllowedIncludes())
            ->allowedSorts(
                ...static::sorts(),
                ...($list->cursor ? [] : new CustomFieldFilterSchema()->allowedSorts($user, $entity->value)),
            )
            ->defaultSort('-created_at');
    }

    /** @return CursorPaginator<int, TModel>|LengthAwarePaginator<int, TModel> */
    final public function paginate(User $user, ListQuery $list): CursorPaginator|LengthAwarePaginator
    {
        $ordered = $this->for($user, $list)->orderBy('id');

        if (! $list->cursor) {
            return $ordered->paginate($list->perPage, ['*'], 'page', $list->page);
        }

        try {
            return $ordered->cursorPaginate($list->perPage);
        } catch (UnexpectedValueException) {
            // A cursor holds the columns of the sort it was issued under, and the paginator throws when one is missing.
            throw FilterErrors::at('cursor', __('validation.filter.cursor'));
        }
    }

    /** @return list<mixed> */
    private function countAllowedIncludes(): array
    {
        $counts = [];

        foreach (static::countIncludes() as $name => $relation) {
            $counts[] = AllowedInclude::count($name, $relation);
        }

        return $counts;
    }
}
```

This is the body of `app/Actions/Company/ListCompanies.php` and `app/Concerns/PaginatesListQuery.php`, parameterized. Compare line by line before moving on. Two checks:

- Every action passes `$filterSchema->allowedSorts($user, '<entity value>')` with `company`, `people`, `opportunity`, `task`, `note`. Run `grep -n "case " app/Enums/CrmEntity.php` and confirm the five case values are those strings.
- `CrmEntity::titleColumn()` returns `name` for company, people and opportunity, and `title` for task and note. Run `sed -n 54,62p app/Enums/CrmEntity.php` and confirm.

If either differs, `sorts()` or the `allowedSorts` argument becomes abstract and each class states its value. Do not paper over it.

- [ ] **Step 7: Create the five list queries**

`app/Queries/Companies/CompaniesQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Queries\Companies;

use App\Enums\CrmEntity;
use App\Models\Company;
use App\Queries\EntityQuery;

/** @extends EntityQuery<Company> */
final readonly class CompaniesQuery extends EntityQuery
{
    public static function entity(): CrmEntity
    {
        return CrmEntity::Company;
    }

    public static function fields(): array
    {
        return ['id', 'name', 'creator_id', 'account_owner_id', 'created_at', 'updated_at'];
    }

    public static function includes(): array
    {
        return ['creator', 'accountOwner', 'people', 'opportunities'];
    }

    public static function countIncludes(): array
    {
        return [
            'peopleCount' => 'people',
            'opportunitiesCount' => 'opportunities',
            'tasksCount' => 'tasks',
            'notesCount' => 'notes',
        ];
    }
}
```

The other four have the same shape. Their class, namespace, model, case and values:

| Class | Namespace | Model, case | `fields()` | `includes()` | `countIncludes()` |
|---|---|---|---|---|---|
| `PeopleQuery` | `App\Queries\People` | `People`, `CrmEntity::People` | `id, name, company_id, creator_id, created_at, updated_at` | `creator, company` | `tasksCount => tasks, notesCount => notes` |
| `OpportunitiesQuery` | `App\Queries\Opportunities` | `Opportunity`, `CrmEntity::Opportunity` | `id, name, company_id, contact_id, creator_id, created_at, updated_at` | `creator, company, contact` | `tasksCount => tasks, notesCount => notes` |
| `TasksQuery` | `App\Queries\Tasks` | `Task`, `CrmEntity::Task` | `id, title, creator_id, created_at, updated_at` | `creator, assignees, companies, people, opportunities` | `assigneesCount => assignees, companiesCount => companies, peopleCount => people, opportunitiesCount => opportunities` |
| `NotesQuery` | `App\Queries\Notes` | `Note`, `CrmEntity::Note` | `id, title, creator_id, created_at, updated_at` | `creator, companies, people, opportunities` | `companiesCount => companies, peopleCount => people, opportunitiesCount => opportunities` |

Verify each row against its action before writing: `sed -n '/allowedFields/,/defaultSort/p' app/Actions/People/ListPeople.php` and the same for `Opportunity/ListOpportunities.php`, `Task/ListTasks.php`, `Note/ListNotes.php`. The action is the source of truth. If a row here disagrees, the action wins.

- [ ] **Step 8: Add `CrmEntity::query()`**

In `app/Enums/CrmEntity.php`, after `model()`:

```php
    /** @return class-string<EntityQuery> */
    public function query(): string
    {
        return match ($this) {
            self::Company => CompaniesQuery::class,
            self::People => PeopleQuery::class,
            self::Opportunity => OpportunitiesQuery::class,
            self::Task => TasksQuery::class,
            self::Note => NotesQuery::class,
        };
    }
```

Import `App\Queries\EntityQuery` and the five query classes. Follow the docblock style of `model()` in the same file.

- [ ] **Step 9: Add `IndexRequest::toListQuery()`**

In `app/Http/Requests/Api/V1/IndexRequest.php`, as a public method after `after()`:

```php
    public function toListQuery(): ListQuery
    {
        $sort = $this->input('sort');
        $include = $this->input('include');
        $fields = $this->input('fields');

        return new ListQuery(
            filter: $this->input('filter'),
            sort: is_string($sort) ? $sort : null,
            include: is_string($include) ? $include : null,
            fields: is_array($fields) || is_string($fields) ? $fields : null,
            perPage: $this->safe()->integer('per_page', 15),
            cursor: $this->safe()->has('cursor'),
        );
    }
```

Import `App\Data\ListQuery`. `page` stays null: the paginator reads it from the request, as it does today. `rules()` already makes `sort` and `include` strings, and `prepareForValidation()` joins a list into a comma string.

- [ ] **Step 10: Point the five controllers at the query classes**

In `app/Http/Controllers/Api/V1/CompaniesController.php`, replace `index()`:

```php
    #[ResponseFromApiResource(CompanyResource::class, Company::class, collection: true, paginate: 15)]
    public function index(IndexRequest $request, CompaniesQuery $query, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        return CompanyResource::collection(
            $query->paginate($user, $request->toListQuery())->appends($request->query()),
        );
    }
```

Replace `use App\Actions\Company\ListCompanies;` with `use App\Queries\Companies\CompaniesQuery;`. Do the same in the other four, keeping each controller's own resource, model and attribute line exactly as they are: `PeopleController` with `PeopleQuery`, `OpportunitiesController` with `OpportunitiesQuery`, `TasksController` with `TasksQuery`, `NotesController` with `NotesQuery`. If a controller's `index()` differs from the Companies one in anything but the names, keep the difference and report it.

- [ ] **Step 11: Let controllers import `App\Queries`**

In `tests/Arch/ArchTest.php`, the test `API controllers must depend on actions for write operations` has a `toOnlyUse` list. Add `'App\Queries',` after `'App\Models',`.

- [ ] **Step 12: Run the REST tests**

Run: `php artisan test --compact tests/Feature/Api/V1 tests/Arch/ArchTest.php`
Expected: all pass, with no assertion edited. This run includes `ApiWorkspaceScopingTest`, which pins the 403.

- [ ] **Step 13: Static analysis on the new files**

Run: `vendor/bin/phpstan analyse app/Data app/Queries app/Enums/CrmEntity.php app/Http/Controllers/Api/V1 app/Http/Requests/Api/V1/IndexRequest.php`
Expected: no errors. The likely one is `withCustomFieldValues()` or `whereBelongsTo()` unknown on `Builder<Model>`, because `CrmEntity::model()` returns a wide type. Fix it with types, never an ignore: tighten the `@return` of `CrmEntity::model()` to the union of the five model classes, or bound `TModel` to that union. If neither satisfies PHPStan, stop and report the exact error.

- [ ] **Step 14: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/ListQuery.php app/Queries app/Enums/CrmEntity.php app/Http tests/Arch/ArchTest.php
git commit -m "refactor(queries): list crm records through query classes on the api"
```

---

### Task 3: MCP list tools

**Files:**
- Modify: `app/Mcp/Tools/BaseListTool.php`, `app/Mcp/Tools/{Company/ListCompaniesTool,People/ListPeopleTool,Opportunity/ListOpportunitiesTool,Task/ListTasksTool,Note/ListNotesTool}.php`, `phpstan-method-length.php`
- Test: `tests/Feature/Mcp/McpReadToolsTest.php`, `tests/Feature/CRM/ListFilterSurfacesTest.php`

**Interfaces:**
- Consumes: `CrmEntity::query()`, `EntityQuery::paginate(User, ListQuery)`, `new ListQuery(...)` from Task 2.
- Produces: `BaseListTool` no longer declares `actionClass()`.

- [ ] **Step 1: Replace the action call**

In `BaseListTool::handle()`, replace the `app()->make($this->actionClass())->execute(...)` call inside the `try`:

```php
            $results = resolve($this->entity()->query())->paginate($user, $this->listQuery($request, $validated));
```

Delete `abstract protected function actionClass(): string;` with its docblock. Replace the whole `buildHttpRequest()` method with:

```php
    /** @param  array<string, mixed>  $validated */
    private function listQuery(Request $request, array $validated): ListQuery
    {
        $filter = $request->get('filter');
        $sort = $request->get('sort');
        $include = $request->get('include');

        return new ListQuery(
            filter: is_array($filter) && $filter !== [] ? FilterTree::trimmed($filter) : null,
            sort: is_array($sort) && isset($sort['field'])
                ? (($sort['direction'] ?? 'asc') === 'desc' ? '-' : '').$sort['field']
                : null,
            include: is_array($include) && $include !== [] ? $include : null,
            perPage: (int) ($validated['per_page'] ?? 15),
            page: (int) ($validated['page'] ?? 1),
        );
    }
```

Add `use App\Data\ListQuery;`. Remove `use Illuminate\Http\Request as HttpRequest;`.

- [ ] **Step 2: Delete the five `actionClass()` overrides**

In each of the five MCP list tools, delete the `actionClass()` method and its `use App\Actions\...\List...;` import. `entity()` and `resourceClass()` stay.

- [ ] **Step 3: Run the MCP tests**

Run: `php artisan test --compact tests/Feature/Mcp tests/Feature/CRM/ListFilterSurfacesTest.php tests/Feature/CRM/SurfaceParityTest.php`
Expected: all pass, with no assertion edited.

- [ ] **Step 4: Lower the method length entry**

Run: `vendor/bin/phpstan analyse app/Mcp`
`phpstan-method-length.php` lists `App\Mcp\Tools\BaseListTool::handle` at 94. If PHPStan reports the method is now shorter, set the entry to the number it reports. Expected after that: no errors.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mcp phpstan-method-length.php
git commit -m "refactor(mcp): list crm records through query classes"
```

---

### Task 4: Chat list tools

**Files:**
- Modify: `packages/Chat/src/Tools/BaseReadListTool.php`, `packages/Chat/src/Tools/{Company/ListCompaniesTool,People/ListPeopleTool,Opportunity/ListOpportunitiesTool,Task/ListTasksTool,Note/ListNotesTool}.php`, `phpstan-method-length.php`
- Test: `tests/Feature/Chat/ListDateFilterTest.php`, `tests/Feature/CRM/SurfaceParityTest.php`

**Interfaces:**
- Consumes: `CrmEntity::query()`, `EntityQuery::paginate(User, ListQuery)`, `new ListQuery(...)` from Task 2.
- Produces: `BaseReadListTool` no longer declares `actionClass()`.

- [ ] **Step 1: Replace the action call**

In `BaseReadListTool::handle()`, replace the `app()->make($this->actionClass())->execute(...)` call inside the `try`:

```php
            $results = resolve($this->entity()->query())->paginate($user, $this->listQuery($request, $user));
```

Delete `abstract protected function actionClass(): string;` with its docblock. Replace the whole `buildHttpRequest()` method with:

```php
    private function listQuery(Request $request, User $user): ListQuery
    {
        $filter = $request['filter'] ?? null;
        $sort = $request['sort'] ?? null;

        return new ListQuery(
            filter: filled($filter) ? FilterTree::trimmed($filter) : null,
            sort: is_string($sort) && $sort !== '' ? $sort : null,
            perPage: $this->perPageFor($request),
            page: isset($request['page']) ? (int) $request['page'] : null,
            viewerZone: $user->effectiveTimezone(),
        );
    }
```

Add `use App\Data\ListQuery;`. Remove `use Illuminate\Http\Request as HttpRequest;` if nothing else in the file uses it.

- [ ] **Step 2: Delete the five `actionClass()` overrides**

In each of the five chat list tools, delete the `actionClass()` method and its `use App\Actions\...\List...;` import.

- [ ] **Step 3: Run the chat list tests**

```bash
grep -rlE "List(Companies|People|Opportunities|Tasks|Notes)Tool" tests/Feature/Chat tests/Feature/CRM
```

Run `php artisan test --compact` over every file that command prints.
Expected: all pass, with no assertion edited. `ListDateFilterTest.php` still names the old actions in `mutates()`. That is fixed in Task 5.

- [ ] **Step 4: Lower the method length entry**

Run: `vendor/bin/phpstan analyse packages/Chat/src/Tools`
`phpstan-method-length.php` lists `Relaticle\Chat\Tools\BaseReadListTool::handle` at 106. Lower it to what PHPStan reports. Expected after that: no errors.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add packages/Chat/src/Tools phpstan-method-length.php
git commit -m "refactor(chat): list crm records through query classes"
```

---

### Task 5: Scribe reads the query class, and the list actions go

**Files:**
- Modify: `app/Scribe/Strategies/DescribesListEndpoint.php`, `GetFromSpatieQueryBuilder.php`, `GetFilterQueryMetadata.php`, `GetFilterBodyFromEntityFilters.php`
- Delete: `app/Actions/Company/ListCompanies.php`, `app/Actions/People/ListPeople.php`, `app/Actions/Opportunity/ListOpportunities.php`, `app/Actions/Task/ListTasks.php`, `app/Actions/Note/ListNotes.php`, `app/Concerns/PaginatesListQuery.php`
- Modify: `tests/Feature/Api/V1/{Companies,People,Opportunities,Tasks,Notes}ApiTest.php`, `tests/Feature/Chat/ListDateFilterTest.php` (`mutates()` only)

**Interfaces:**
- Consumes: static `EntityQuery::entity()`, `sorts()`, `includes()`, `countIncludes()` from Task 2. `/tmp/scribe-before` from Task 1.
- Produces: no class named `List*` under `app/Actions`.

- [ ] **Step 1: Rewrite the trait's discovery and parameter methods**

In `DescribesListEndpoint.php`, delete the `LIST_ACTION_ENTITIES` constant, `findActionClass()`, `getMethodSource()` and `topLevelNames()`. Replace `listParameters()`, `sortParameter()` and `includeParameter()` with:

```php
    /** @return class-string<EntityQuery>|null */
    private function findQueryClass(ExtractedEndpointData $endpointData): ?string
    {
        foreach ($endpointData->method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), EntityQuery::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * @param  class-string<EntityQuery>  $queryClass
     * @return array<string, array<string, mixed>>
     */
    private function listParameters(string $queryClass): array
    {
        return [
            ...$this->sortParameter($queryClass::sorts()),
            ...$this->includeParameter([...$queryClass::includes(), ...array_keys($queryClass::countIncludes())]),
            ...$this->paginationParameters(),
        ];
    }

    /**
     * @param  list<string>  $sorts
     * @return array<string, array<string, mixed>>
     */
    private function sortParameter(array $sorts): array
    {
        if ($sorts === []) {
            return [];
        }

        $sortList = implode(', ', array_map(fn (string $s): string => "{$s}, -{$s}", $sorts));

        return ['sort' => [
            'type' => 'string',
            'required' => false,
            'description' => "Sort results. Prefix with `-` for descending. Allowed: {$sortList}.",
            'example' => '-created_at',
        ]];
    }

    /**
     * @param  list<string>  $includes
     * @return array<string, array<string, mixed>>
     */
    private function includeParameter(array $includes): array
    {
        if ($includes === []) {
            return [];
        }

        return ['include' => [
            'type' => 'string',
            'required' => false,
            'description' => 'Include related resources (comma-separated). Allowed: '.implode(', ', $includes).'.',
            'example' => $includes[0],
        ]];
    }
```

Fix the imports: add `App\Queries\Contracts\EntityQuery`. Remove the five action imports, `App\Enums\CrmEntity` if unused, `ReflectionClass`, `ReflectionException` and `ReflectionMethod`. `isIndexMethod()`, `isPostIndex()` and `paginationParameters()` stay untouched.

- [ ] **Step 2: Update the three strategies**

In each of `GetFromSpatieQueryBuilder.php`, `GetFilterQueryMetadata.php` and `GetFilterBodyFromEntityFilters.php`: rename the local `$actionClass` to `$queryClass`, call `$this->findQueryClass($endpointData)`, and replace every `self::LIST_ACTION_ENTITIES[$actionClass]` with `$queryClass::entity()`. Nothing else in those files changes.

Run: `grep -rn "LIST_ACTION_ENTITIES\|findActionClass\|actionClass" app/Scribe`
Expected: no output.

- [ ] **Step 3: Delete the actions and the trait**

```bash
git rm app/Actions/Company/ListCompanies.php app/Actions/People/ListPeople.php app/Actions/Opportunity/ListOpportunities.php app/Actions/Task/ListTasks.php app/Actions/Note/ListNotes.php app/Concerns/PaginatesListQuery.php
grep -rnE "App\\\\Actions\\\\(Company|People|Opportunity|Task|Note)\\\\List|PaginatesListQuery" app packages tests routes config
```

Expected from the grep: only the six test files' `use` and `mutates()` lines.

- [ ] **Step 4: Repoint `mutates()`**

In each of the five `tests/Feature/Api/V1/*ApiTest.php` files, replace the `use App\Actions\...\List...;` import with the matching query class import and add `use App\Queries\Concerns\ListsEntity;`. In the `mutates(...)` call, replace `ListCompanies::class` (or its sibling) with the query class and add `ListsEntity::class`. Example for `CompaniesApiTest.php`:

```php
mutates(
    CreateCompany::class,
    UpdateCompany::class,
    DeleteCompany::class,
    CompaniesQuery::class,
    ListsEntity::class,
    CompanyResource::class,
);
```

Keep every other entry of each file's own list. In `tests/Feature/Chat/ListDateFilterTest.php:20`:

```php
mutates(OpportunitiesQuery::class, CompaniesQuery::class, PeopleQuery::class, ListsEntity::class);
```

Run the grep from Step 3 again. Expected: no output.

- [ ] **Step 5: Run every test that touched the lists**

Run: `php artisan test --compact tests/Feature/Api/V1 tests/Feature/Mcp tests/Feature/CRM/SurfaceParityTest.php tests/Feature/Chat/ListDateFilterTest.php tests/Arch`
Expected: all pass.

- [ ] **Step 6: Prove the API reference did not change**

```bash
php artisan scribe:generate --no-interaction
rm -rf /tmp/scribe-after && mkdir /tmp/scribe-after
cp -R .scribe /tmp/scribe-after/dot-scribe
cp -R storage/app/private/scribe /tmp/scribe-after/storage
diff -r /tmp/scribe-before /tmp/scribe-after > /tmp/scribe-change.diff; wc -l /tmp/scribe-change.diff
```

Use the storage path recorded in Task 1. Read `/tmp/scribe-change.diff` against the noise set from Task 1. Every difference must be of a kind already in `/tmp/scribe-noise.diff`. Then check the part that must be exact:

```bash
grep -E "^[<>]" /tmp/scribe-change.diff | grep -iE "Allowed:|Sort results|Include related|filter\[|Operators:|per_page|cursor" | head
```

Expected: no output. A difference in a parameter name, a description or their order is a regression. Fix the query class or the trait until it is gone. Never accept a changed reference.

- [ ] **Step 7: Static analysis**

Run: `vendor/bin/phpstan analyse app/Scribe app/Queries tests/Feature/Api/V1`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app/Scribe app/Actions app/Concerns tests/Feature
git commit -m "refactor(queries): drop the list actions and the scribe source regex"
```

---

### Task 6: The other three app reads

**Files:**
- Move: `app/Actions/Crm/GetCrmSummary.php` to `app/Queries/Crm/CrmSummaryQuery.php`
- Move: `app/Actions/Opportunity/AggregateOpportunities.php` to `app/Queries/Opportunities/OpportunityAggregatesQuery.php`
- Move: `app/Actions/CustomFields/FindEntitiesByFieldValue.php` to `app/Queries/CustomFields/EntitiesByFieldValueQuery.php`
- Modify: `app/Mcp/Resources/CrmSummaryResource.php`, `app/Mcp/Tools/GetCrmSummaryTool.php`, `app/Mcp/Tools/AggregateOpportunitiesTool.php`, `packages/Chat/src/Tools/AggregateCrmTool.php`, `app/Concerns/ResolvesUpsertMatch.php`, `app/Support/CustomFields/RestoreConflictMessage.php`, `phpstan-method-length.php`, `phpstan.neon`, and each test that names one of the three classes

**Interfaces:**
- Produces: `CrmSummaryQuery::get(User $user): array`, `OpportunityAggregatesQuery::get(...)` with the parameters `AggregateOpportunities::execute()` has today, `EntitiesByFieldValueQuery::get(string $modelClass, CustomField $field, array $values, int $limit): Collection`.

- [ ] **Step 1: Move the three files**

```bash
mkdir -p app/Queries/Crm app/Queries/CustomFields
git mv app/Actions/Crm/GetCrmSummary.php app/Queries/Crm/CrmSummaryQuery.php
git mv app/Actions/Opportunity/AggregateOpportunities.php app/Queries/Opportunities/OpportunityAggregatesQuery.php
git mv app/Actions/CustomFields/FindEntitiesByFieldValue.php app/Queries/CustomFields/EntitiesByFieldValueQuery.php
```

In each moved file: set the namespace (`App\Queries\Crm`, `App\Queries\Opportunities`, `App\Queries\CustomFields`), rename the class to match the file, and rename `execute` to `get`. Change nothing else. Add a `use` for any class that was in the old namespace and was referenced without an import.

- [ ] **Step 2: Update every reference**

```bash
grep -rnwE "GetCrmSummary|AggregateOpportunities|FindEntitiesByFieldValue" app packages tests phpstan.neon phpstan-method-length.php
```

For each hit: update the import and the type, rename the variable only where it is the property or parameter that holds the class, and change `->execute(` to `->get(` on that object. Class names such as `GetCrmSummaryTool` and `AggregateOpportunitiesTool` are tools, not the action: leave them. A comment that names the old class (`AggregateCrmTool.php:139` does) gets the new name. In `phpstan-method-length.php`, rename the key `App\Actions\Opportunity\AggregateOpportunities::byStage` to `App\Queries\Opportunities\OpportunityAggregatesQuery::byStage` and keep its number.

Run the grep again. Expected: only the two tool class names.

- [ ] **Step 3: Check the new folder against the existing arch tests**

Run: `php artisan test --compact tests/Arch`
Expected: all pass. `the query language uses no transport` now covers the three moved classes. If one of them imports `App\Mcp`, `App\Http`, `App\Filament`, `App\Livewire`, `App\Scribe` or `Relaticle\Chat`, stop and report the import. Do not add an ignore.

- [ ] **Step 4: Run the tests that exercise the three reads**

Run: `php artisan test --compact tests/Feature/Mcp/CrmSummaryResourceTest.php tests/Feature/Mcp/McpReadToolsTest.php tests/Feature/Chat/AggregateCrmToolTest.php tests/Feature/Api/V1/CompaniesUpsertApiTest.php tests/Feature/Api/V1/PeopleUpsertApiTest.php`
Expected: all pass.

- [ ] **Step 5: Static analysis**

Run: `vendor/bin/phpstan analyse app/Queries app/Mcp app/Concerns app/Support/CustomFields packages/Chat/src/Tools`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app packages tests phpstan-method-length.php phpstan.neon
git commit -m "refactor(queries): move the summary, aggregate and field value reads"
```

---

### Task 7: `ConversationsQuery`

**Files:**
- Create: `packages/Chat/src/Queries/ConversationsQuery.php`
- Delete: `packages/Chat/src/Actions/FindConversation.php`, `ListConversations.php`, `SearchConversations.php`
- Modify: `app/Filament/Pages/ChatConversation.php:39`, `app/Filament/Pages/Dashboard.php:65`, `packages/Chat/src/Http/Controllers/ChatController.php:643`, `packages/Chat/src/Livewire/App/Chat/ChatAllChatsPanel.php:70-71`, `packages/Chat/src/Livewire/App/Chat/ChatSidebarNav.php:58`, `packages/Chat/src/Livewire/Chat/ChatInterface.php:139,391,402`, and each test that names one of the three classes

**Interfaces:**
- Produces: `ConversationsQuery::find(User $user, string $conversationId): ?stdClass`, `recent(User $user, int $limit = 50): Collection<int, stdClass>`, `search(User $user, string $term): Collection<int, stdClass>`.

- [ ] **Step 1: Create the class from the three bodies**

`packages/Chat/src/Queries/ConversationsQuery.php`:

```php
<?php

declare(strict_types=1);

namespace Relaticle\Chat\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Support\TitleSanitizer;
use stdClass;

final readonly class ConversationsQuery
{
    private const array COLUMNS = ['id', 'title', 'created_at', 'updated_at'];

    public function find(User $user, string $conversationId): ?stdClass
    {
        return AgentConversation::query()
            ->ownedBy($user)
            ->whereKey($conversationId)
            ->toBase()
            ->first(self::COLUMNS);
    }

    /** @return Collection<int, stdClass> */
    public function recent(User $user, int $limit = 50): Collection
    {
        return $this->withCleanTitles(
            AgentConversation::query()
                ->ownedBy($user)
                ->latest('updated_at')
                ->limit($limit)
                ->toBase()
                ->get(self::COLUMNS),
        );
    }

    /** @return Collection<int, stdClass> */
    public function search(User $user, string $term): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        $needle = '%'.LikePattern::escape($term).'%';

        return $this->withCleanTitles(
            AgentConversation::query()
                ->ownedBy($user)
                ->where(function (Builder $conversation) use ($needle): void {
                    $conversation->where('title', 'ilike', $needle)
                        ->orWhereHas('messages', fn (Builder $message): Builder => $message->withoutSynthetic()->where('content', 'ilike', $needle));
                })
                ->latest('updated_at')
                ->limit(50)
                ->toBase()
                ->get(self::COLUMNS),
        );
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return Collection<int, stdClass>
     */
    private function withCleanTitles(Collection $rows): Collection
    {
        return $rows->map(function (stdClass $row): stdClass {
            $row->title = TitleSanitizer::clean((string) $row->title);

            return $row;
        });
    }
}
```

Open the three actions and compare. Take the `LikePattern` import and the exact `Builder` type hints from `SearchConversations.php`. `find()` returns the raw title, as `FindConversation` does today: do not add the sanitizer there. If any action differs from the body above in a way this plan did not capture, the action wins.

- [ ] **Step 2: Update the call sites**

| File | Before | After |
|---|---|---|
| `app/Filament/Pages/ChatConversation.php:39` | `(new FindConversation)->execute(` | `new ConversationsQuery()->find(` |
| `app/Filament/Pages/Dashboard.php:65` | `(new ListConversations)->execute($user, 1)` | `new ConversationsQuery()->recent($user, 1)` |
| `ChatController.php:643` | `(new ListConversations)->execute($user)` | `new ConversationsQuery()->recent($user)` |
| `ChatAllChatsPanel.php:70` | `(new ListConversations)->execute($user, 50)` | `new ConversationsQuery()->recent($user, 50)` |
| `ChatAllChatsPanel.php:71` | `(new SearchConversations)->execute($user, $query)` | `new ConversationsQuery()->search($user, $query)` |
| `ChatSidebarNav.php:58` | `(new ListConversations)->execute($user, self::SIDEBAR_LIMIT + 1)` | `new ConversationsQuery()->recent($user, self::SIDEBAR_LIMIT + 1)` |
| `ChatInterface.php:139` and `:402` | `resolve(FindConversation::class)->execute(` | `resolve(ConversationsQuery::class)->find(` |

Fix the imports in each file. The docblock at `ChatInterface.php:391` names `FindConversation`: change it to `ConversationsQuery::find()`. Do not touch any `DB::table('agent_conversations')` read or any ownership `abort_if` in `ChatController` or `routes/channels.php`. The spec's finding explains why.

- [ ] **Step 3: Delete the actions and update the tests**

```bash
git rm packages/Chat/src/Actions/FindConversation.php packages/Chat/src/Actions/ListConversations.php packages/Chat/src/Actions/SearchConversations.php
grep -rnwE "FindConversation|ListConversations|SearchConversations" app packages tests
```

For each test hit, change the class and the method (`execute` to `find`, `recent` or `search`). A test title that names the old class, such as `returns null from FindConversation for cross-workspace conversation ids`, gets the new name: `returns null from ConversationsQuery::find for cross-workspace conversation ids`. The assertions do not change. Run the grep again. Expected: no output.

- [ ] **Step 4: Run the chat conversation tests**

Run: `php artisan test --compact tests/Feature/Chat/ChatAllChatsPanelTest.php tests/Feature/Chat/ConversationSearchTest.php tests/Feature/Chat/ConversationWorkspaceScopingTest.php tests/Feature/Commands/ResetDemoAccountCommandTest.php tests/Arch`
Expected: all pass. Then run any other test file the Step 3 grep named.

- [ ] **Step 5: Static analysis**

Run: `vendor/bin/phpstan analyse packages/Chat/src app/Filament/Pages`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A packages/Chat app/Filament/Pages tests
git commit -m "refactor(chat): read conversations through one query class"
```

---

### Task 8: `ConversationMessagesQuery` and the chat walk

**Files:**
- Move: `packages/Chat/src/Actions/ListConversationMessages.php` to `packages/Chat/src/Queries/ConversationMessagesQuery.php`
- Modify: `packages/Chat/src/Livewire/Chat/ChatInterface.php:98,275`, `phpstan-method-length.php:49-50`, comments that name the class, and each test that names it

**Interfaces:**
- Produces: `ConversationMessagesQuery::get(User $user, string $conversationId, ?string $beforeMessageId = null, int $limit = 50): array`.

- [ ] **Step 1: Move and rename**

```bash
git mv packages/Chat/src/Actions/ListConversationMessages.php packages/Chat/src/Queries/ConversationMessagesQuery.php
```

Set the namespace to `Relaticle\Chat\Queries`, the class to `ConversationMessagesQuery`, and rename `execute` to `get`. Add a `use` for any `Relaticle\Chat\Actions` class the file referenced without an import. The body does not change by one line.

- [ ] **Step 2: Update references**

```bash
grep -rnw "ListConversationMessages" app packages tests phpstan-method-length.php
```

- PHP references: new import, `->execute(` becomes `->get(`.
- `phpstan-method-length.php`: `Relaticle\Chat\Actions\ListConversationMessages::execute` becomes `Relaticle\Chat\Queries\ConversationMessagesQuery::get`, and `...::extractPendingActions` moves to the new class name. Both numbers stay.
- Comments in `.php`, `.js` and `.blade.php` files that name the class get the new name. Change the name only.

Run the grep again. Expected: no output.

- [ ] **Step 3: Run the chat message tests**

Run: `php artisan test --compact tests/Feature/Chat/MessagePaginationTest.php tests/Feature/Chat/MessageVisibilityTest.php tests/Feature/Chat/DisplayBlockTest.php tests/Feature/Chat/PendingActionEagerLoadTest.php tests/Feature/Chat/RecordLinkRehydrationTest.php tests/Feature/Chat/ProposalContinuationTest.php tests/Feature/Chat/MessageFeedbackTest.php tests/Feature/Chat/MessageDocumentRenderingTest.php tests/Feature/Chat/ChatMessageSupersedeTest.php tests/Feature/Chat/ChatAttachmentSendTest.php tests/Feature/Chat/ProcessChatMessageFailureTest.php`
Expected: all pass. Then run any other test file the Step 2 grep named, except Browser tests.

- [ ] **Step 4: Static analysis**

Run: `vendor/bin/phpstan analyse packages/Chat/src`
Expected: no errors.

- [ ] **Step 5: Confirm the ownership checks were not touched**

```bash
git diff ca5a4d130 -- packages/Chat/routes/channels.php
git diff ca5a4d130 -- packages/Chat/src/Http/Controllers/ChatController.php
```

Expected: no diff for `channels.php`. For `ChatController.php`, only the import and line 643 from Task 7.

- [ ] **Step 6: Walk chat on the production-shaped stack**

Read the `agent-browser-relaticle` skill and `.ai/rules/chat.md` first. Bring up Horizon with `QUEUE_CONNECTION=redis` and Reverb. In a real browser, as a seeded user:

1. Open the dashboard. The recent conversation card renders.
2. Open the sidebar. The conversation list renders.
3. Open a conversation with more than 50 messages, or the longest one seeded. Messages render, and scrolling up loads earlier ones.
4. Send a message. It streams, and a proposal card appears if the prompt asks for a write.
5. Reload. The same messages and the card return.
6. Open All chats and search a word from a message body. The conversation is found.

Save one screenshot of step 5 and one of step 6 under `.context/`. Any difference from the base branch is a regression of the rename: find the missed reference.

- [ ] **Step 7: Run the one browser file that reloads a conversation**

Run: `php artisan test --compact tests/Browser/Chat/DisplayBlockRenderTest.php`
Expected: pass.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A packages/Chat tests phpstan-method-length.php
git commit -m "refactor(chat): move the conversation messages read to queries"
```

---

### Task 9: Gates

**Files:**
- Modify: `tests/Arch/ArchTest.php`, `tests/Arch/ConventionsTest.php`, `phpstan.neon:146-152`

**Interfaces:**
- Consumes: the layout Tasks 2 to 8 produced. No class named `List*`, `Find*`, `Search*`, `Get*` or `Aggregate*` remains under any `Actions` folder.

Each gate is proven by planting a violation, watching the gate fail, and removing the plant. A gate that never failed is not trusted.

- [ ] **Step 1: Confirm the starting state**

```bash
find app/Actions packages/*/src/Actions -name '*.php' | grep -E '/(List|Find|Search|Get|Aggregate)[A-Z][A-Za-z]*\.php$'
grep -rn "Spatie\\\\QueryBuilder" app/Actions
grep -rnE "\b(auth|request)\(" app/Queries packages/Chat/src/Queries
```

Expected: no output from any of the three. A hit from the first command is a read action this plan missed or a write with a read-shaped name. Report it. Do not rename it and do not weaken the gate.

- [ ] **Step 2: Add the arch gates**

In `tests/Arch/ArchTest.php`, after `the query language uses no transport`:

```php
$queryLayers = [
    'App\Queries\Companies',
    'App\Queries\Crm',
    'App\Queries\CustomFields',
    'App\Queries\Notes',
    'App\Queries\Opportunities',
    'App\Queries\People',
    'App\Queries\Tasks',
    'Relaticle\Chat\Queries',
];

foreach ($queryLayers as $queryLayer) {
    arch("{$queryLayer} holds final readonly query classes")
        ->expect($queryLayer)
        ->classes()
        ->toBeFinal()
        ->toBeReadonly();
}

foreach (['App\Queries', 'Relaticle\Chat\Queries'] as $queryRoot) {
    arch("{$queryRoot} takes the acting user and reads no ambient request")
        ->expect($queryRoot)
        ->not
        ->toUse(['auth', 'request']);
}

arch('actions build no list query')
    ->expect('App\Actions')
    ->not
    ->toUse('Spatie\QueryBuilder');

arch('chat queries use no chat transport')
    ->expect('Relaticle\Chat\Queries')
    ->not
    ->toUse(['Relaticle\Chat\Http', 'Relaticle\Chat\Livewire', 'Relaticle\Chat\Tools', 'Relaticle\Chat\Jobs']);
```

- [ ] **Step 3: Add the convention gates**

In `tests/Arch/ConventionsTest.php`, after `keeps the role suffix on classes whose directory carries one`:

```php
it('names a class in a domain folder of Queries with the Query suffix', function (): void {
    $root = dirname(__DIR__, 2);
    $grammarFolders = ['Filters', 'Sorts', 'Concerns', 'Contracts'];

    $files = [
        ...glob($root.'/app/Queries/*/*.php') ?: [],
        ...glob($root.'/packages/*/src/Queries/*.php') ?: [],
        ...glob($root.'/packages/*/src/Queries/*/*.php') ?: [],
    ];

    $offenders = array_values(array_filter(
        $files,
        static fn (string $file): bool => ! in_array(basename(dirname($file)), $grammarFolders, true)
            && ! str_ends_with(basename($file, '.php'), 'Query'),
    ));

    expect($offenders)->toBe([], 'A reusable read is a *Query class (.ai/rules/queries.md). Fix: '.json_encode($offenders));
});

it('keeps reads out of the Actions folders', function (): void {
    $root = dirname(__DIR__, 2);

    $files = [
        ...glob($root.'/app/Actions/*/*.php') ?: [],
        ...glob($root.'/packages/*/src/Actions/*.php') ?: [],
        ...glob($root.'/packages/*/src/Actions/*/*.php') ?: [],
    ];

    $offenders = array_values(array_filter(
        $files,
        static fn (string $file): bool => preg_match('/^(List|Find|Search|Get|Aggregate)[A-Z]/', basename($file, '.php')) === 1,
    ));

    expect($offenders)->toBe([], 'An action is a write. A reusable read is a *Query class under Queries (.ai/rules/queries.md). Fix: '.json_encode($offenders));
});
```

Read the neighbouring tests in the file first and match how they resolve `$root` and word their failure message.

- [ ] **Step 4: Guard `Queries` against writes**

In `phpstan.neon`, in the `guardedNamespaces` of `App\PHPStan\Rules\EloquentWriteOutsideActionRule`, add two lines after `- Relaticle\Chat\Livewire`:

```
                - App\Queries
                - Relaticle\Chat\Queries
```

- [ ] **Step 5: Run the gates green**

Run: `composer test:arch && vendor/bin/phpstan analyse app/Queries packages/Chat/src/Queries`
Expected: all pass, no errors. If the write rule reports an existing class in `app/Queries`, read the line. A real write there is a finding to report, not a line to ignore.

- [ ] **Step 6: Plant a violation for each gate**

Do these one at a time. After each, run the command, confirm the named test fails, then undo with `git checkout -- <file>` or `rm <file>`.

| Plant | Run | Must fail |
|---|---|---|
| Remove `final` from `app/Queries/Companies/CompaniesQuery.php` | `php artisan test --compact tests/Arch/ArchTest.php` | `App\Queries\Companies holds final readonly query classes` |
| Add `$unused = auth()->id();` as the first line of `ConversationsQuery::find()` | same | `Relaticle\Chat\Queries takes the acting user and reads no ambient request` |
| Add `use Spatie\QueryBuilder\QueryBuilder;` and `QueryBuilder::for(Company::class);` inside `app/Actions/Company/CreateCompany.php::execute()` | same | `actions build no list query` |
| Add `use Relaticle\Chat\Tools\BaseReadListTool;` and a `BaseReadListTool::class;` statement inside `ConversationsQuery::find()` | same | `chat queries use no chat transport` |
| Create `app/Queries/Companies/CompanyFinder.php` holding an empty `final readonly class CompanyFinder {}` in namespace `App\Queries\Companies` | `php artisan test --compact tests/Arch/ConventionsTest.php` | `names a class in a domain folder of Queries with the Query suffix` |
| Create `app/Actions/Company/ListCompanies.php` holding an empty `final readonly class ListCompanies {}` in namespace `App\Actions\Company` | same | `keeps reads out of the Actions folders` |
| Add `Company::query()->delete();` inside `CompaniesQuery::fields()` with its import | `vendor/bin/phpstan analyse app/Queries/Companies` | an `EloquentWriteOutsideActionRule` error on that line |

After the last one:

Run: `git status --short`
Expected: only `tests/Arch/ArchTest.php`, `tests/Arch/ConventionsTest.php` and `phpstan.neon` modified. No plant survives.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Arch phpstan.neon
git commit -m "test(arch): gate the queries read layer"
```

---

### Task 10: Rules and guidelines

**Files:**
- Modify: `.ai/rules/queries.md`, `.ai/guidelines/relaticle/architecture.md`
- Regenerate: `CLAUDE.md`, `AGENTS.md`, `GEMINI.md`, `.ai/rules/index.md`

**Interfaces:**
- Consumes: the gate names from Task 9, exactly as written there.

- [ ] **Step 1: Rebase before touching `queries.md`**

Another session edits `.ai/rules/queries.md` on the base branch.

```bash
git fetch origin feat/crm-filter-language
git log --oneline HEAD..origin/feat/crm-filter-language
```

If that prints commits, run `git rebase origin/feat/crm-filter-language`, resolve conflicts, and re-run `composer test:arch` plus the Task 5 Step 5 test command. If the rebase conflicts in a file this plan rewrote and the right resolution is not obvious, stop and report.

- [ ] **Step 2: Rewrite `.ai/rules/queries.md`**

Make these edits. Keep every section this list does not name.

1. Front matter `paths`: replace `'app/Actions/*/List*.php'` with `'app/Data/ListQuery.php'` and add `'packages/*/src/Queries/**'`.
2. Title: `# Queries: the read path`. First paragraph: add that a reusable read is a query class and an action is a write.
3. In "Where a file goes", add three table rows:

   | Kind of class | Folder |
   |---|---|
   | one entity's list, or a read across models | `app/Queries/<Domain>`, named `*Query` |
   | the contract and the shared body of the five list queries | `app/Queries/Contracts/EntityQuery.php`, `app/Queries/Concerns/ListsEntity.php` |
   | a read only one package calls | `packages/<Name>/src/Queries`, named `*Query` |

   Under the table, name the gates: `ConventionsTest` "names a class in a domain folder of Queries with the Query suffix" and "keeps reads out of the Actions folders", `ArchTest` "holds final readonly query classes".
4. Add a section "When a read becomes a query class" with the placement rule: one model and one condition is a `#[Scope]`. Two callers, a read across models, or a read that carries the grammar is a query class. One caller keeps the read inline. A package read moves to `app/Queries` when a second module needs it.
5. Add a section "The shape of a query class": `final readonly`, takes the acting `User`, never `auth()` or `request()`, never writes, authorizes and bounds the workspace itself. Verbs: `for()` returns a builder a caller may refine, `paginate()` returns a page, `get()` and `find()` return results. Name the gates: `ArchTest` "takes the acting user and reads no ambient request", and `EloquentWriteOutsideActionRule` for writes.
6. Every "list action" becomes "list query". `EntityFilters::definitions()` paragraph: "A list query never registers an `AllowedFilter` of its own", gated by `ArchTest` "actions build no list query".
7. Replace the bullet "A caller that is not a list action" with: "**A caller that wants more than a page.** Call `for($user, $list)` on the entity's query class and refine the builder. It is authorized and workspace-bound already. Never rebuild the allowlists in the caller."
8. Add one line under "Adding to it": "**A field, an include or a count include on a list.** Add it to the entity's query class. The API reference reads the class."

Write it in the house style: one idea per sentence, 25 words at most, no em-dash.

- [ ] **Step 3: Add the read path to `architecture.md`**

In `.ai/guidelines/relaticle/architecture.md`, after the "Actions (the write path)" section and before "One fact, one owner", add:

````markdown
## Queries (the read path)

A reusable read is a query class. An action is a write. `tests/Arch/ConventionsTest.php` fails a
class under `Actions` named `List*`, `Find*`, `Search*`, `Get*` or `Aggregate*`.

| A read that is | Lives in |
|---|---|
| a predicate over one model's columns | a `#[Scope]` on the model |
| one entity's list, or a read across models | `app/Queries/<Domain>/<Name>Query.php` |
| a read only one package calls | `packages/<Name>/src/Queries/<Name>Query.php` |
| a read with one caller | inline in that caller |

A query class is `final readonly`. It takes the acting `User`, authorizes, and bounds the
workspace itself, so a transport cannot forget either:

```php
final readonly class CompaniesQuery implements EntityQuery
{
    use ListsEntity;

    public static function entity(): CrmEntity
    {
        return CrmEntity::Company;
    }
    // fields(), includes(), countIncludes()
}

$page = $query->paginate($user, $request->toListQuery());
```

- Each transport maps its own input to `App\Data\ListQuery`. A query class never reads
  `auth()` or `request()`, because chat tools run in queued jobs
- A caller that wants more than a page calls `for($user, $list)` and refines the builder
- A query class never writes. `EloquentWriteOutsideActionRule` covers `App\Queries` and
  `Relaticle\Chat\Queries`
- `.ai/rules/queries.md` holds the filter grammar and the rest of the rules
````

In the "Actions (the write path)" section, find the bullet that says a command is given an action when a second caller shares the write. Leave it. If any sentence in that section describes a list or read action, remove that sentence.

- [ ] **Step 4: Compile**

```bash
php artisan boost:update --no-interaction
cp AGENTS.md GEMINI.md
git status --short
```

Expected: `CLAUDE.md`, `AGENTS.md`, `GEMINI.md` and `.ai/rules/index.md` change. In `.ai/rules/index.md`, the `queries.md` row no longer lists `app/Actions/*/List*.php`. If the index did not regenerate, find what writes it (`grep -rn "rules/index" app vendor/laravel/boost/src | head`) and run that. Do not edit a compiled file by hand.

- [ ] **Step 5: Run the convention gate**

Run: `php artisan test --compact tests/Arch/ConventionsTest.php`
Expected: pass. It fails when the compiled files drift from the sources or when a doc holds an em-dash.

- [ ] **Step 6: Commit**

```bash
git add .ai CLAUDE.md AGENTS.md GEMINI.md
git commit -m "docs(guidelines): describe queries as the read path"
```

---

### Task 11: Whole-branch checks and the pull request

**Files:** whatever the checks rewrite.

- [ ] **Step 1: Second pass for stale references**

```bash
grep -rnE "App\\\\Actions\\\\(Company\\\\ListCompanies|People\\\\ListPeople|Opportunity\\\\(ListOpportunities|AggregateOpportunities)|Task\\\\ListTasks|Note\\\\ListNotes|Crm\\\\GetCrmSummary|CustomFields\\\\FindEntitiesByFieldValue)|Relaticle\\\\Chat\\\\Actions\\\\(FindConversation|ListConversations|SearchConversations|ListConversationMessages)|PaginatesListQuery|buildHttpRequest|actionClass\(" app packages tests routes config .ai packages/Documentation
```

Expected: no output. A hit in `docs/superpowers` is an executed record: leave it.

- [ ] **Step 2: The pre-push checks**

```bash
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:lint
composer test:arch
```

Expected: all clean. If rector suggests a change, apply it with `vendor/bin/rector` and re-run the tests of the files it touched. Run these one at a time, not in parallel: they share the machine with other workspaces.

- [ ] **Step 3: Shard balance**

This plan adds no test class and moves no test file. Confirm: `git diff --stat ca5a4d130 -- tests | tail -1` shows only modified files. If so, leave `tests/.pest/shards.json` alone.

- [ ] **Step 4: Commit anything the checks changed**

```bash
git status --short
git add -A && git commit -m "chore: apply rector and style fixes"
```

Skip the commit when `git status` is empty.

- [ ] **Step 5: Push and draft the pull request**

```bash
git branch --show-current
git push -u origin feat/queries-read-layer
```

Draft the PR title and body and show them to Manuk. Do not open the PR until he replies "post". Base: `feat/crm-filter-language` while #906 is open, `main` once it has merged. The body states: what moved, that nothing observable changed, the unchanged API reference, the chat walk with its two screenshots, and the open finding about conversations with a null `workspace_id`.

- [ ] **Step 6: Watch CI**

After the PR is open, as a background task:

```bash
gh run watch --exit-status $(gh run list --branch feat/queries-read-layer --workflow Tests --limit 1 --json databaseId --jq '.[0].databaseId')
```

Read the result from the run itself, never from a piped tail. Fix what it reports and push again.

---

## Notes for the executor

- **Stacked base.** If #906 merges while this branch is open, rebase onto `origin/main` and retarget the PR to `main`. Never merge this branch into `feat/crm-filter-language` after that branch has merged.
- **Out of scope, on purpose.** The five inline conversation ownership checks, `packages/SystemAdmin/src/Metrics`, raw `DB::table` reads in chat jobs, read-shaped classes in `EmailIntegration`, `ImportWizard` and `OnboardSeed`, and splitting `ConversationMessagesQuery::get()`. The spec lists each with its reason.
- **When a step's expectation does not hold.** Stop and report what you ran and what came back. Do not edit an assertion, add an ignore, or widen a gate to get past it.
