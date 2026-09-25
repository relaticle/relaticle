# Activity log: record which channel made each change

Date: 2026-09-24. Branch: `feat/activity-log-source`. Issue: #831.

## Problem

An activity row records who made a change but not how. An edit in the panel, through the REST
API, through an MCP tool, or by approving a chat proposal writes an identical row. A chat change
names the user who approved the card as its causer, so it reads as a manual edit.

`creation_source` on CRM records covers creation only, and `Company::getActivitylogOptions()`
excludes it from the log. Updates and deletes carry no source at all.

The channel a record was created through is also stated record by record today. Five `Create*`
actions take a `$source` argument, eight callers pass it, the importer and the sample-data seeder
set the attribute directly, and five models default it to `WEB`. Stamping activity rows from a
second holder would give the same fact two owners that can disagree.

## Goal

Each entry point states its channel once. Both a new record's `creation_source` and every
activity row's `properties.source` read that one value. The workspace Activity page, the record
timeline, and both `ListActivityTool` classes show it.

Acceptance, from the issue:

- A record edited once through each of the panel, API, MCP, and chat shows four activity rows
  with four distinct sources.
- Rows written before this change render without a source and without errors.

## Decisions

1. **One vocabulary, one holder.** `CreationSource` stays the only enum for channels.
   `App\Support\CurrentSource` holds which case applies to the running request or job. Rejected:
   moving the holder methods onto the enum, which would tie it to Context and the activity
   property key, and renaming the enum to `Source`, which touches about 40 files for no
   functional gain.
2. **The holder is a hidden Laravel `Context` key.** Context is bound scoped, dehydrated into
   every queued job payload, hydrated on `JobProcessing`, and reset between jobs by the worker
   (verified in `vendor/`, framework 13.33). A job dispatched from an API request therefore sees
   `api` on the sync and Redis queues alike. `Context::scope()` restores the previous value when a
   callback ends. The `impersonated_by` stamp already travels this way. Rejected: a scoped class
   copying `CurrentImport`, as the issue proposed. It behaves differently on sync and Redis queues
   and needs a hand-written try/finally.
3. **A record's `creation_source` comes from the holder.** An `initializeHasCreator()` trait
   initializer sets it from `CurrentSource::get()` in the model constructor, the same point where
   the static `$attributes` default applies today. An explicit value still overrides it through
   `fill()`. The `$source` argument, its callers, the explicit writes, and the static defaults all
   go. The SystemAdmin form keeps its explicit field, because staff pick that value on purpose.
   Rejected: a `creating` hook. The column is `NOT NULL` with no database default, and about 20
   test files create records inside `withoutEvents()`, where no hook fires.
4. **Default `web`, explicit `system` inside the background writers.** `ResetDemoAccountCommand`
   wraps its rebuild in `during(SYSTEM)`, so no caller can forget it. The onboarding sample-data
   seed runs inside `Model::withoutEvents()`: it writes no activity rows and no `creating` hook
   fires for it. `BaseModelSeeder` therefore keeps its explicit `creation_source => SYSTEM`, the
   one writer that states the source itself. `RemoveSampleData` finds sample records by that
   value, so it must not change. No `runningInConsole()` heuristic: it is true under PHPUnit, so
   it would mislabel every panel test.
5. **The API is marked by route middleware, MCP by its server.** A new `SetCurrentSource`
   middleware takes the source as a parameter on the v1 API routes. MCP overrides
   `RelaticleServer::runMethodHandle()`, which both the HTTP transport and the
   `RelaticleServer::actingAs()->tool()` test harness go through; a route middleware would miss
   every MCP tool test. Rejected: a parameter on `SetApiWorkspaceContext`, which would give the
   tenant middleware a second job.
6. **The timeline names non-web sources only.** API, MCP, chat, and system rows get a "Via API"
   style line. Web rows get none, because it would sit on every manual edit. Import rows keep
   their existing "Via import <file>" line.
7. **The Activity page gets a Source column and a Source filter.**
8. **Both tools return `source` on each entry.** The chat tool's rendered card keeps its four
   columns. The model reads the source from the payload when asked how a change was made.

## Components

### `App\Support\CurrentSource`

Owns which channel applies now.

| Member | Behaviour |
|---|---|
| `set(CreationSource)` | Adds the hidden Context value for the rest of the request |
| `during(CreationSource, Closure)` | Runs the callback under `Context::scope()`, restoring the previous value after |
| `get(): CreationSource` | The hidden Context value, or `WEB` when none is set |

`set()` is for middleware, whose request ends with the value. Everything else uses `during()`,
because a sync-queue job runs inside a request that keeps writing afterwards.

### `App\Models\ActivityLog\Activity`

Owns where the source sits on an activity row.

| Member | Behaviour |
|---|---|
| `SOURCE_PROPERTY = 'source'` | The key under `properties` |
| `sourceFrom(array $properties): ?CreationSource` | Reads a row's properties, or a timeline entry's merged ones; a missing or unknown value is `null` |
| `#[Scope] fromSource(Builder, CreationSource)` | The one predicate over `properties->source` |

### `App\Models\Concerns\HasCreator`

Gains `initializeHasCreator()`, setting the raw `creation_source` attribute with
`??= CurrentSource::get()->value`. Laravel runs trait initializers before `fill()`, and hydration
from the database overwrites every attribute afterwards, so explicit and stored values win. The
`??=` matters because `Model::__wakeup()` re-runs initializers: a plain assignment would re-stamp
an unserialized record with the current channel and mark it dirty. The initializer also returns
early when `$this->exists`, so a record loaded without the column and then unserialized gains
no channel either. The `$attributes` default (its
only entry) leaves `Company`, `People`, `Opportunity`, `Task`, and `Note`.

### Setting the channel

| Where | Change |
|---|---|
| `SetCurrentSource` (new middleware) | `handle(Request, Closure, string $source)` calls `CurrentSource::set(CreationSource::from($source))` |
| `routes/api.php` | `SetCurrentSource::class.':api'` in the v1 stack |
| `RelaticleServer` | Overrides `runMethodHandle()` to run the parent inside `during(CreationSource::MCP)`. No tool streams a generator result, which would otherwise run after the scope closes |
| `PendingActionService` | The single call sites of `executeAction()` (in `approve()`) and `executeBatchItem()` (in `approveItem()`) run inside `during(CreationSource::CHAT)`. `ProposalPlanService` and `RemoveSampleDataTool` reach these too |
| `ExecuteImportJob` | `handle()` runs its body, moved to a private method, inside `during(CreationSource::IMPORT)`. `failed()` writes its summary row the same way |
| `ResetDemoAccountCommand` | The transaction body moves to a private method returning the seeded companies and runs inside `during(CreationSource::SYSTEM)` |

### Removing the per-record source

| Where | Change |
|---|---|
| `CreateCompany`, `CreatePeople`, `CreateOpportunity`, `CreateTask`, `CreateNote` | Drop the `$source` parameter and the `creation_source` assignment |
| Five `Api\V1` controllers, `BaseCreateTool`, `PendingActionService` (two calls), `ResetDemoAccountCommand` (five calls) | Stop passing a source |
| `BaseImporter::initializeNewRecordData()`, `ExecuteImportJob` auto-created link records | Stop setting `creation_source` |

### Writing and reading the activity stamp

| Where | Change |
|---|---|
| `AppServiceProvider::configureActivityLog()` | `put(Activity::SOURCE_PROPERTY, CurrentSource::get()->value)` on every row, next to the import properties |
| Activity page (`app/Filament/Pages/Workspace/ActivityLog.php`) | A `source` badge column. `CreationSource` supplies label and color, and legacy rows show the `ActivityValue::EMPTY` placeholder. A `SelectFilter` over `CreationSource` queries `fromSource()` |
| `MergedActivityRenderer` and `activity-log/merged-activity.blade.php` | Pass a "Via <label>" line for API, MCP, chat, and system rows, below the summary where the import line sits |
| `app/Mcp/Tools/ListActivityTool.php` | Each entry gains `'source' => ?string` |
| `packages/Chat/src/Tools/Activity/ListActivityTool.php` | Each entry gains `'source' => ?string`, and the `ActivityEntry` type follows |
| `lang/en/workspaces.php` | `activity.columns.source`, `activity.filters.source`, `activity.via_source` ("Via :source") |

Only `en` carries `workspaces.php`, so no other locale needs the keys.

`CreationSource` colors API `purple` and chat `indigo`. Neither panel registered those names, so
the badges rendered transparent. `AppPanelProvider` and `SystemAdminPanelProvider` now register
both next to `primary`; the SystemAdmin `creation_source` badges had the same gap.

`CurrentSource` is a `final readonly class`, as the arch preset requires of a class with no state.

The SystemAdmin activity view lists a row's properties when it has no diff (import summaries). It
skips `Activity::SOURCE_PROPERTY` there, so the stamp never reads as a change.

`RequestActivityBatch` holds one `batch_uuid` per channel within a request or job. Rows written
through two channels are two saves, so the timeline, the Activity page and the tools never merge
them and never disagree about the source.

## Tenant scoping without static teardown

`SetApiWorkspaceContext::terminate()` used to call `clearBootedModels()` to drop the scopes it
added. That resets boot state shared by every model, so the next use of any model boots it again
and registers its listeners twice: measured, `Company` created listeners went 2 to 4 and
`CustomField`, never scoped, went 1 to 2. In one test process every write after an API call logged
twice; the four-channel test had to call the API last to hide it.

- `Company`, `People`, `Opportunity`, `Task`, and `Note` declare `#[ScopedBy(WorkspaceScope::class)]`
  once. The scope reads the request-scoped `App\Support\CurrentWorkspace` and does nothing when it is
  unset, which matches today: outside the tenant middleware no scope existed.
- `SetApiWorkspaceContext` and `ApplyTenantScopes` set the holder instead of adding five scopes.
  Laravel resets the scoped binding per queue job; `SetApiWorkspaceContext::terminate()` clears it
  for long-lived processes, since the HTTP kernel does not.
- The API's `User` `tenant` scope stays a runtime closure, removed by name through
  `Model::getAllGlobalScopes()`/`setAllGlobalScopes()`. The panel's Filament-named `User` scope is
  unchanged, because `AcceptWorkspaceInvitation` suspends it by name.
- Rejected: holding the workspace in `Context`, which would scope every queued job dispatched from
  an API request; `flushEventListeners()`, which leaves every other model doubled; explicit
  `whereBelongsTo()` in each caller, where one missed call leaks a tenant.

## Out of scope

- Edits made in the SystemAdmin panel stamp `web`. `CreationSource` has no staff case, and no
  one has asked for one.
- Rows written before this change are not backfilled. The channel was never recorded, so no
  backfill could recover it.
- The `creation_source` column keeps its name, even though the enum now labels every change.

## Testing

Everything goes through real entry points. Activity tests live in `tests/Feature/ActivityLog/`.

New `ActivitySourceTest.php`:

- One company edited through the panel edit page, `PATCH /api/v1/companies/{id}`, the MCP
  update tool, and an approved chat proposal yields four `updated` rows stamped `web`, `api`,
  `mcp`, and `chat`.
- A delete through the API stamps `api` on the `deleted` row.
- An MCP call over the real `/mcp` HTTP route stamps `mcp`.
- A write after a chat approval, in the same request, stamps `web` again.
- A legacy row without `source` renders on the Activity page with the placeholder, in the record
  timeline without a "Via" line, and in both tools with `source: null`.
- The Activity page Source filter narrows to one channel.
- The timeline shows "Via API" on an API row and nothing on a web row.

Record `creation_source`, now derived from the holder:

- A company created through the panel, `POST /api/v1/companies`, the MCP create tool, and an
  approved chat proposal carries `web`, `api`, `mcp`, and `chat`. Extend whichever existing API,
  MCP, and chat tests already assert `creation_source`; add the assertion where none does.
- A writer that states `creation_source` itself keeps it, whatever channel is current.
- `RemoveSampleDataToolTest` keeps finding onboarding sample records by `system` unchanged.
- The import tests that assert `CreationSource::IMPORT` today
  (`ExecuteImportJobCoreTest`, `ExecuteImportJobEntityTest`) keep passing unchanged.

Extended `ImportActivityTest.php`: imported rows stamp `import`, and a write after the job in the
same sync request stamps `web`.

Extended `tests/Feature/Commands/ResetDemoAccountCommandTest.php`: reseeded records carry
`system`, and so do their activity rows.

## Verification

- Gates: pint, rector, phpstan, type coverage, the targeted tests, then the full suite once.
- Sweep: `grep -rn "CreationSource::" app packages` shows no write outside the channel setters,
  the SystemAdmin form, `BaseModelSeeder`, and seeders under `database/`.
- Chat: approve a proposal on the production-shaped stack (Horizon, `QUEUE_CONNECTION=redis`,
  Reverb) in a real browser, then confirm the row reads "Via AI Chat" and a created record
  carries `chat`.
- UI: agent-browser screenshots of the Activity page and the record timeline, light and dark,
  including a legacy row.
