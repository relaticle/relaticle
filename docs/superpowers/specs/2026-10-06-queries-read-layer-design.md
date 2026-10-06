# Queries: the read layer

**Date:** 2026-10-06
**Branch:** `feat/queries-read-layer`, cut from `feat/crm-filter-language` (PR #906) at `ca5a4d130`
**Status:** design, awaiting review

## Goal

Reads get one home, the way writes already have one. A reusable read is a query class in a
`Queries` folder. An action is a write. `architecture.md` already titles its section
"Actions (the write path)", yet twelve classes under `Actions` only read.

Nothing a user or an agent can observe changes. The REST API, the MCP server and chat return
the same rows, the same errors and the same API reference before and after.

## What is wrong today

Measured on `ca5a4d130`:

- Five list actions (`ListCompanies`, `ListPeople`, `ListOpportunities`, `ListTasks`,
  `ListNotes`) are the same 25 lines. They differ in four facts: the entity, the selectable
  fields, the includes and the count includes.
- Each takes `?Request $request` and a dead `array $filters`. MCP and chat build a fake
  `Illuminate\Http\Request` to call them (`BaseListTool::buildHttpRequest()`,
  `BaseReadListTool::buildHttpRequest()`).
- `DescribesListEndpoint` documents the API by running regular expressions over the source
  text of each action's `execute()` method.
- A Filament table, an export or a report that wants "the companies this user may list,
  filtered by this tree" has no builder to start from. The actions return a paginator.
- Chat's four conversation reads (`FindConversation`, `ListConversations`,
  `SearchConversations`, `ListConversationMessages`) sit under `Actions` and have nine call
  sites between them.

## Decisions

Each was weighed in the brainstorm of 2026-10-05 and 2026-10-06. Sources: Pinkary
(`app/Queries`, twelve `final readonly` classes, actions are writes only), Brent Roose's
*Laravel Beyond CRUD* (pp. 143 to 147, query classes return a builder the caller refines),
and Spatie's public apps (scopes on the model, no query folder).

| # | Decision | Rejected alternative |
|---|---|---|
| 1 | A reusable read is a `*Query` class. The read actions are deleted. | Keep `List*` actions as an authorized shell around a query class. Two classes per read, and the shell is a pass-through. |
| 2 | Authorization and the workspace bound live inside the query class. | Leave them to each caller. Three transports call every list, and one forgetting the check is a data leak. |
| 3 | Query classes sit in a domain subfolder: `app/Queries/Companies/CompaniesQuery.php`. | Flat at the root. The root stays the filter and sort grammar. |
| 4 | A read over a package's own models lives in `packages/<Name>/src/Queries`, whoever calls it. | A `Queries` folder in every package up front. |
| 5 | A one-model predicate stays a `#[Scope]`. No custom Eloquent builders. | `ConnectedAccountBuilder`, `UserBuilder`. The five CRM models have no scopes, and `ConventionsTest` already gates the scope rule. |
| 6 | The list input is `App\Data\ListQuery`, a plain `final readonly` class. | `spatie/laravel-data`. Every class in `app/Data` is a plain readonly class, and each transport validates before it builds one. |
| 7 | Write actions keep `array $data`. | DTOs per write. `custom_fields` is dynamic per workspace. |
| 8 | This lands as its own PR, stacked on #906. | Folding it into #906, which is already 200 commits ahead of `main`. |

## The read path

| A read that is | Lives in | Example |
|---|---|---|
| a predicate over one model's columns | `#[Scope]` on the model | `AgentConversation::ownedBy()` |
| the shared filter and sort grammar | `app/Queries` root, `Filters/`, `Sorts/`, `Concerns/` | `FilterTree`, `NativeFilter` |
| a reusable read: one entity's list, or a read across models | `Queries/<Domain>/<Name>Query.php` | `CompaniesQuery` |
| a read with one caller | inline in that caller | |

A query class is created when a read has two callers, spans models, or carries the grammar.

## Layout

```
app/Queries/
├── FilterTree.php, EntityFilters.php, Operand.php ...   the grammar, unchanged
├── Filters/  Sorts/  Concerns/                          unchanged
├── Contracts/EntityQuery.php                            contract of the five list queries
├── Concerns/ListsEntity.php                             their shared body
├── Companies/CompaniesQuery.php
├── People/PeopleQuery.php
├── Opportunities/OpportunitiesQuery.php
├── Opportunities/OpportunityAggregatesQuery.php         was AggregateOpportunities
├── Tasks/TasksQuery.php
├── Notes/NotesQuery.php
├── Crm/CrmSummaryQuery.php                              was GetCrmSummary
└── CustomFields/EntitiesByFieldValueQuery.php           was FindEntitiesByFieldValue

app/Data/ListQuery.php

packages/Chat/src/Queries/
├── ConversationsQuery.php                               was Find, List and SearchConversations
└── ConversationMessagesQuery.php                        was ListConversationMessages
```

Deleted: the twelve read actions and `app/Concerns/PaginatesListQuery.php`.

## Components

### `App\Data\ListQuery`

What a caller asks a list for, with no transport in it.

```php
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
}
```

- `toRequest()` returns an `Illuminate\Http\Request` holding only `filter`, `sort`, `include`
  and `fields`. It is the single adapter into `Spatie\QueryBuilder\QueryBuilder::for()`, and
  only `ListsEntity` calls it.
- `filter` is `mixed` on purpose. `FilterTree::validate()` owns the rejection of a filter that
  is not an object, and a typed property would turn that 422 into a `TypeError`.
- `include` and `fields` take what Spatie's builder takes: REST sends a comma string, MCP a list.
- `cursor` is a flag. The cursor value itself stays with Laravel's cursor resolver, which
  reads the current HTTP request. Only REST pages by cursor, so nothing changes.
- Each transport builds the object on its own side: `IndexRequest::toListQuery()`,
  `BaseListTool::listQuery()`, `BaseReadListTool::listQuery()`. The two `buildHttpRequest()`
  methods are deleted. `FilterTree::trimmed()` still runs in the MCP and chat adapters.

### `EntityQuery` and `ListsEntity`

The five list queries share one contract and one body. Three arch tests forbid inheritance
in `App` (`avoid open for extension`, `ensure no extends`, `avoid inheritance`), so the shared
part is an interface plus a trait, not a base class.

```php
// app/Queries/Contracts/EntityQuery.php
interface EntityQuery
{
    public static function entity(): CrmEntity;

    /** @return list<string> */
    public static function fields(): array;

    /** @return list<string> */
    public static function includes(): array;

    /** @return array<string, string> */
    public static function countIncludes(): array;

    /** @return list<string> */
    public static function sorts(): array;

    /** @return QueryBuilder<Model> */
    public function for(User $user, ListQuery $list): QueryBuilder;

    /** @return CursorPaginator<int, Model>|LengthAwarePaginator<int, Model> */
    public function paginate(User $user, ListQuery $list): CursorPaginator|LengthAwarePaginator;
}

// app/Queries/Concerns/ListsEntity.php
trait ListsEntity   // sorts(), for(), paginate()
```

- `for()` runs, in this order: `abort_unless($user->can('viewAny', $model), 403)`,
  `FilterTree::validate()`, then the builder over
  `$model::query()->withCustomFieldValues()->whereBelongsTo($user->currentWorkspace)` with the
  allowed filters, fields, includes, sorts and the `-created_at` default. This is the body of
  today's actions, unchanged.
- `sorts()` returns the title column, `created_at` and `updated_at`. All five actions list
  exactly those. `for()` adds the workspace's custom field sorts unless `$list->cursor` is set.
- `paginate()` is today's `PaginatesListQuery::paginateList()`: order by `id`, page or cursor,
  and the `UnexpectedValueException` to `FilterErrors::at('cursor')` translation. Pagination has
  one owner, so no transport calls `->paginate()` on a builder itself.
- A caller that wants something other than a page calls `for()` and refines the builder:
  `$query->for($user, $list)->where('account_owner_id', $user->id)->cursor()`. It is authorized
  and workspace-bound before the caller touches it.

### The five list queries

Each declares four facts and nothing else.

```php
final readonly class CompaniesQuery implements EntityQuery
{
    use ListsEntity;

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
        return ['peopleCount' => 'people', 'opportunitiesCount' => 'opportunities', 'tasksCount' => 'tasks', 'notesCount' => 'notes'];
    }
}
```

The values for the other four are copied from their actions at `ca5a4d130`, in the same order.
Order matters: the API reference prints them in it.

### `CrmEntity::query()`

Returns the list query class for the case. The enum already owns `model()`, `table()` and
`titleColumn()`, and `architecture.md` puts a pure function of an enum case on the enum.

- `BaseListTool` and `BaseReadListTool` resolve `$this->entity()->query()`. The abstract
  `actionClass()` and its ten one-line overrides are deleted.
- REST controllers inject the concrete class: `index(IndexRequest $request, CompaniesQuery $query, ...)`.

### Scribe

`DescribesListEndpoint` finds the `EntityQuery` parameter on the controller method and reads
`$class::entity()`, `$class::sorts()`, `$class::includes()` and `$class::countIncludes()`.
`LIST_ACTION_ENTITIES`, `getMethodSource()`, `topLevelNames()` and both regular expressions are
deleted. `GetFromSpatieQueryBuilder` follows whatever it shares with them.

### Chat queries

```php
final readonly class ConversationsQuery
{
    public function find(User $user, string $conversationId): ?stdClass
    /** @return Collection<int, stdClass> */
    public function recent(User $user, int $limit = 50): Collection
    /** @return Collection<int, stdClass> */
    public function search(User $user, string $term): Collection
}
```

- The three bodies move unchanged, the `ownedBy()` scope included.
- `ConversationMessagesQuery::get()` is `ListConversationMessages::execute()` renamed. Its body
  does not change in this work. Its two `phpstan-method-length.php` entries are renamed with it.
- The `lockForUpdate()` read at `ChatController:218` stays: it belongs to a write transaction.

### Finding: the ownership copies are not copies

`ChatController` checks conversation ownership inline at lines 126, 435, 475 and 550, and
`packages/Chat/routes/channels.php` does at line 15. The brainstorm assumed these duplicate
`AgentConversation::ownedBy()`. They do not:

| | Participant | Workspace |
|---|---|---|
| `ownedBy()` | must match | `workspace_id` must equal `current_workspace_id` |
| the five inline checks | must match | a null `workspace_id` passes, otherwise it must match |

A conversation with a null `workspace_id` opens, streams and broadcasts today, yet
`FindConversation`, `ListConversations` and `SearchConversations` never return it. Routing the
five checks through `ownedBy()` would lock those conversations out.

So this work does not merge them. The five checks stay as they are, and phase 3 is the two query
classes only. Closing the gap needs one fact first: how many `agent_conversations` rows in
production have a null `workspace_id`. If none, a follow-up replaces the five checks with
`ConversationsQuery::find()`. If some, a migration backfills them first.

### The other three reads

`GetCrmSummary`, `AggregateOpportunities` and `FindEntitiesByFieldValue` move to the paths in
the layout. Their method becomes `get()`. Bodies, constructor dependencies and authorization
checks do not change. `EntitiesByFieldValueQuery` takes no `User`. It bounds both
of its subqueries by the `tenant_id` of the `CustomField` it receives, so its caller must resolve
that field inside the acting workspace.

## Rules and the artifact that fails

| Rule | Gate |
|---|---|
| A one-model predicate is a `#[Scope]`, never a query class | `ConventionsTest`: no public method outside a model, enum or `Scope` takes a builder (exists) |
| `app/Queries` imports no transport | `ArchTest` "the query language uses no transport" (exists, covers subfolders) |
| A `Filter` lives in `Filters/`, a `Sort` in `Sorts/` | `ArchTest` (exists) |
| A class in a domain subfolder of `Queries` ends in `Query` | `ConventionsTest` (new) |
| A class in a domain subfolder of `Queries` is `final readonly` | `ArchTest` (new) |
| `Queries` never calls `auth()` or `request()` | `ArchTest` (new) |
| `Queries` never writes | `EloquentWriteOutsideActionRule`: add `App\Queries` and `Relaticle\Chat\Queries` to `guardedNamespaces` in `phpstan.neon` (new) |
| An action is a write: `Actions` holds no `List*`, `Find*`, `Search*`, `Get*` or `Aggregate*` class | `ConventionsTest` (new) |
| `App\Actions` does not build a Spatie query | `ArchTest`: `App\Actions` does not use `Spatie\QueryBuilder` (new) |
| `Relaticle\Chat\Queries` imports none of Chat's `Http`, `Livewire`, `Tools` or `Jobs` | `ArchTest` (new) |
| API controllers reach reads through `App\Queries` | `ArchTest` "API controllers must depend on actions for write operations": add `App\Queries` to its list (edit) |
| Every surface publishes the same filters, sorts and includes | `SurfaceParityTest` (exists) |

Each negated arch expectation covers one layer inside a `foreach`. Each new gate is proven by
planting a violation and watching it fail before the gate is trusted.

The action-name gate reads names, so a read named `ResolveSomething` passes it. A reviewer
reads for that.

## Phases

One branch, four phases. Each ends green and can be reviewed on its own.

1. **CRM lists.** `ListQuery`, `EntityQuery`, `ListsEntity`, the five list queries, `CrmEntity::query()`.
   Migrate the five REST controllers, both base list tools and Scribe. Delete the five actions,
   the trait and both `buildHttpRequest()` methods.
2. **Other app reads.** Move `GetCrmSummary`, `AggregateOpportunities` and
   `FindEntitiesByFieldValue`.
3. **Chat reads.** `ConversationsQuery` and `ConversationMessagesQuery`. The inline ownership
   checks stay, as the finding above explains.
4. **Rules and gates.** The new arch and convention tests, the PHPStan namespaces,
   `.ai/rules/queries.md`, a "Queries (the read path)" section in
   `.ai/guidelines/relaticle/architecture.md`, then `php artisan boost:update` and the
   `AGENTS.md` to `GEMINI.md` copy.

## Proof that nothing changed

- The existing suites pass with no assertion edited: `tests/Feature/Api/V1` (including
  `ListFilterTest` and `ListFilterSurfacesTest`), `tests/Feature/CRM/SurfaceParityTest.php`,
  `tests/Feature/Mcp`, `tests/Feature/Chat`. The only test edits are class references and
  `mutates()` lines.
- The generated API reference does not change. Scribe makes example responses from factory
  data, so generate it twice at `ca5a4d130` to learn what varies, then once after phase 1. Every
  parameter name, description and order must match exactly.
- Phase 3 changes what `ChatController`, `ChatInterface` and the chat panels call, so it is
  walked on the production-shaped stack that `chat.md` requires: Horizon,
  `QUEUE_CONNECTION=redis`, Reverb, a real browser. The walk opens a conversation, sends a
  message, reloads, loads earlier messages, and searches.
- `vendor/bin/phpstan analyse`, `vendor/bin/rector --dry-run`, `composer test:lint` and
  `composer test:arch` pass. No PHPStan ignore is added.

## Out of scope

- A Filament or export caller of `for()`. The builder exists for one. None is added here.
- `packages/SystemAdmin/src/Metrics` (`SalesLeadsQuery`, `PivotSafeTableQuery`). SystemAdmin
  is outside PHPStan and keeps its layout.
- Raw `DB::table` reads in `ProcessChatMessage`, `SuggestNextSteps`, `GenerateConversationTitle`
  and `ConversationTitleGate`. Each has one caller and sits beside a write.
- Read-shaped classes in `EmailIntegration`, `ImportWizard` and `OnboardSeed` that the name gate
  does not catch. They get an audit of their own.
- DTOs for write payloads, custom Eloquent builders, and a `src/Domain` layout.
- Splitting `ConversationMessagesQuery::get()`, which is 125 lines. It moves as it is.

## Risks

- **The base branch moves.** Another session has uncommitted edits to `.ai/rules/queries.md`
  and the filter spec in the `melbourne` workspace. Phase 4 rewrites `queries.md`, so expect a
  conflict there when #906 advances. Rebase onto `origin/feat/crm-filter-language` before
  phase 4, not after.
- **The PR base.** The PR targets `feat/crm-filter-language` until #906 merges, then is
  retargeted to `main`. It must never be merged into the base branch after that branch has
  merged.
- **Enum and query classes import each other.** `CrmEntity::query()` names `App\Queries`
  classes, and they name `CrmEntity`. `CrmEntity::model()` already does this with `App\Models`.
  If an arch test refuses it, the tools keep an abstract `queryClass()` and the enum method is
  dropped.
- **`mutates()` targets.** Five API tests and `ListDateFilterTest` declare the list actions.
  They are repointed at the query classes and `EntityQuery`.

## Changes during review (2026-10-06)

The pre-merge review simplified the design. The code is the source of truth where a sketch
above differs.

- **Two facts per list query, not four.** `countIncludes()` is gone: a count include is a name
  in `includes()` ending in `Count`, which spatie/laravel-query-builder 7 resolves itself.
  `entity()` moved into the trait and reads `CrmEntity::query()`, so the entity-to-query mapping
  has one owner.
- **The chat list tool reads its sort names from the query class.** Its own copy is gone.
- **A package read stays in its package.** `ConversationsQuery` is called from `app/Filament`
  and cannot move to `app/Queries`, which may not import a package.
- **`for()` is private until a caller needs it.** Nothing calls it but `paginate()`, and a
  caller's top-level `orWhere` would escape the workspace bound in a queued job. The first
  caller makes it public, with its own test.
- **One page cap.** `ListQuery::MAX_PAGE` is 1,000,000 on REST, MCP and chat. A larger page
  overflowed the offset and returned 500 on `main`.
- **Gates read the folders.** Readonly covers every class under `app/Queries`. One gate fails a
  Spatie query built outside a query layer. A convention test fails a `Queries` folder that
  `phpstan.neon` does not guard.
- **The list authorization has a test.** Removing the `viewAny` check left the suite green, so
  `SurfaceParityTest` now refuses the list to an unverified user on REST, MCP and chat.
