# Activity log: record which channel made each change

Date: 2026-09-24. Branch: `issue-831`. Issue: #831.

## Problem

An activity row records who made a change but not how. An edit in the panel, through the REST
API, through an MCP tool, or by approving a chat proposal writes an identical row. A chat change
names the user who approved the card as its causer, so it reads as a manual edit.

`creation_source` on CRM records covers creation only, and `Company::getActivitylogOptions()`
excludes it from the log. Updates and deletes carry no source at all.

## Goal

Every activity row written after this change carries `properties.source`, a `CreationSource`
value naming the channel. The workspace Activity page, the record timeline, and both
`ListActivityTool` classes show it.

Acceptance, from the issue:

- A record edited once through each of the panel, API, MCP, and chat shows four activity rows
  with four distinct sources.
- Rows written before this change render without a source and without errors.

## Decisions

1. **Holder: a hidden Laravel `Context` key, behind one owner class.** Context is bound scoped,
   dehydrated into every queued job payload, hydrated on `JobProcessing`, and reset between jobs
   by the worker (verified in `vendor/`, framework 13.33). A job dispatched from an API request
   therefore stamps `api` on the sync and Redis queues alike. `Context::scope()` restores the
   previous value when a callback ends. The `impersonated_by` stamp in the same hook already
   travels this way. Rejected: a scoped `CurrentSource` copying `CurrentImport`, as the issue
   proposed. It behaves differently on sync and Redis queues and needs a hand-written
   try/finally.
2. **Default `web`, explicit `system` where background code writes.** Two background writers
   create logged records today: the onboarding sample-data seed and `ResetDemoAccountCommand`.
   Both run under `system`. No `runningInConsole()` heuristic: it is true under PHPUnit, so it
   would mislabel every panel test.
3. **API and MCP are told apart by route middleware.** A new `SetActivitySource` middleware takes
   the source as a parameter. Rejected: a parameter on `SetApiWorkspaceContext`, which would give
   the tenant middleware a second job.
4. **The timeline names non-web sources only.** API, MCP, chat, and system rows get a "Via API"
   style line. Web rows get none, because it would sit on every manual edit. Import rows keep
   their existing "Via import <file>" line.
5. **The Activity page gets a Source column and a Source filter.**
6. **Both tools return `source` on each entry.** The chat tool's rendered card keeps its four
   columns. The model reads the source from the payload when asked how a change was made.

## Components

### `App\Support\ActivityLog\ActivitySource`

The single owner of the channel fact: the Context key, the property name, and reading it back.

| Member | Behaviour |
|---|---|
| `PROPERTY = 'source'` | The key under `properties` on an activity row |
| `set(CreationSource)` | Adds the hidden Context value for the rest of the request |
| `during(CreationSource, Closure)` | Runs the callback under `Context::scope()`, restoring the previous value after |
| `current(): CreationSource` | The hidden Context value, or `WEB` when none is set |
| `from(array $properties): ?CreationSource` | Reads a row's properties; a missing or unknown value is `null` |

`set()` is for middleware, whose request ends with the value. Everything else uses `during()`,
because a sync-queue job runs inside a request that keeps writing afterwards.

### `App\Models\ActivityLog\Activity`

Gains `#[Scope] fromSource(Builder $query, CreationSource $source)`, the one predicate over
`properties->source`. The Activity page filter goes through it.

### Writing the source

| Where | Change |
|---|---|
| `AppServiceProvider::configureActivityLog()` | `put(ActivitySource::PROPERTY, ActivitySource::current()->value)` on every row, next to the import properties |
| `SetActivitySource` (new middleware) | `handle(Request, Closure, string $source)` calls `ActivitySource::set(CreationSource::from($source))` |
| `routes/api.php` | `SetActivitySource::class.':api'` in the v1 stack |
| `routes/ai.php` | `SetActivitySource::class.':mcp'` in `$mcpMiddleware` |
| `PendingActionService` | `executeAction()` and `executeBatchItem()` run inside `during(CreationSource::CHAT)`. `approve()`, `approveItem()`, `ProposalPlanService`, and `RemoveSampleDataTool` all reach these two methods |
| `ExecuteImportJob` | The row loop runs inside `during(CreationSource::IMPORT)`, next to `CurrentImport::set()` |
| `CreateWorkspaceCustomFields` | `OnboardSeeder::run()` runs inside `during(CreationSource::SYSTEM)` |
| `ResetDemoAccountCommand` | The reseed runs inside `during(CreationSource::SYSTEM)` |

### Reading the source

| Where | Change |
|---|---|
| Activity page (`app/Filament/Pages/Workspace/ActivityLog.php`) | A `source` badge column. `CreationSource` supplies label and color, and legacy rows show the `ActivityValue::EMPTY` placeholder. A `SelectFilter` over `CreationSource` queries `fromSource()` |
| `MergedActivityRenderer` and `activity-log/merged-activity.blade.php` | Pass a "Via <label>" line for API, MCP, chat, and system rows, below the summary where the import line sits |
| `app/Mcp/Tools/ListActivityTool.php` | Each entry gains `'source' => ?string` |
| `packages/Chat/src/Tools/Activity/ListActivityTool.php` | Each entry gains `'source' => ?string`, and the `ActivityEntry` type follows |
| `lang/en/workspaces.php` | `activity.columns.source`, `activity.filters.source`, `activity.via_source` ("Via :source") |

Only `en` carries `workspaces.php`, so no other locale needs the keys.

## Out of scope

- `creation_source` on the record still comes from the `$source` argument of each `Create*`
  action. Deriving it from `ActivitySource` would touch five API controllers, `BaseCreateTool`,
  `PendingActionService`, and the importer. It is a follow-up.
- Edits made in the SystemAdmin panel stamp `web`. `CreationSource` has no staff case, and no
  one has asked for one.
- Rows written before this change are not backfilled. The channel was never recorded, so no
  backfill could recover it.

## Testing

Everything goes through real entry points, in `tests/Feature/ActivityLog/`.

New `ActivitySourceTest.php`:

- One company edited through the panel edit page, `PATCH /api/v1/companies/{id}`, the MCP
  update tool, and an approved chat proposal yields four `updated` rows stamped `web`, `api`,
  `mcp`, and `chat`.
- A delete through the API stamps `api` on the `deleted` row.
- A legacy row without `source` renders on the Activity page with the placeholder, in the record
  timeline without a "Via" line, and in both tools with `source: null`.
- The Activity page Source filter narrows to one channel.
- The timeline shows "Via API" on an API row and nothing on a web row.
- Onboarding sample data stamps `system`.

Extended `ImportActivityTest.php`: imported rows stamp `import`, and a write after the job in the
same sync request stamps `web`.

Extended `tests/Feature/Commands/ResetDemoAccountCommandTest.php`: the reseeded records' rows
stamp `system`.

## Verification

- Gates: pint, rector, phpstan, type coverage, the targeted tests, then the full suite once.
- Chat: approve a proposal on the production-shaped stack (Horizon, `QUEUE_CONNECTION=redis`,
  Reverb) in a real browser, then confirm the row reads "Via AI Chat".
- UI: agent-browser screenshots of the Activity page and the record timeline, light and dark,
  including a legacy row.
