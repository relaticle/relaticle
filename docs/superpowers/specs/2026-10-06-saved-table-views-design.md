# Saved table views

Date: 2026-10-06
Status: design approved, not implemented
Closes: #740

## Problem

Every list table forgets what the user did to it. Column visibility, column order,
page size, filters, sort and search live in the Laravel session, so they reset when
the session expires. Issue #740 carries the repro: hide `Created By` on People, come
back after the session lifetime, and the column is back.

Column widths are worse. `asmit/resized-column` writes them to a `table_settings`
table that exists in neither the local nor the production database, because its DB
write sits behind an opt-in flag we never set. Widths therefore live in the session
only, and nothing migrates: a read-only query against production on 2026-09-17
confirmed `to_regclass('public.table_settings')` is null.

PR #604 (contributor, now closed) covered the column half with a jsonb column on
`users`. Its key was the page class alone, with no workspace, so custom field columns
from one workspace would overwrite another's.

## Decisions

Four decisions were taken on 2026-09-17. Each lists what was rejected, because the
rejected branch is the part that gets re-proposed later.

| Decision | Chosen | Rejected |
|---|---|---|
| Sharing | Private views, plus share to workspace; workspace Admins may edit a shared view | Private only; shared but owner-edit-only |
| View contents | Columns, page size, filters, sort, search, column searches, grouping, widths | Columns and page size only; everything except widths |
| Auto-save | Yes: the last state persists per user, and named views sit on top | Named views only, nothing persists until saved |
| Resize | Port the Maxforms implementation and persist widths per user | Port as-is on the session; reimplement a minimal resizer |

Auto-save is what closes #740. Named views are the feature on top of it. One storage
mechanism serves both, so the two ship together rather than one blocking the other.

## Prior art

Seven Filament plugins were reviewed on 2026-09-17. None fits.

| Package | Stores | Workspace-aware | Verdict |
|---|---|---|---|
| kisame76/filament-db-table-state | all session table state, automatically | no | beta, a DB read per table request, swallows errors |
| Advanced Tables (archilex) | named views, favorites, shared views, approval | yes | paid and closed source; only worth it if curated team views become a product goal |
| wotz/filament-table-filter-presets | named filter and column presets | no | v0.x, but its "set as default" and remount-to-apply ideas are worth borrowing |
| hydrat/filament-table-layout-toggle | grid versus table layout | no | different problem; its storage-driver interface is the only idea |
| Bostos/reorderable-columns, KozSuper/Filament-Table-Views, guiu/filament-filter-presets | column order or named views | no | Filament v3 only, stale |

In-house wins on three counts: workspace scoping is mandatory here and absent from
every free package, our custom field columns differ per workspace, and the write path
has to go through an action class to satisfy `EloquentWriteOutsideActionRule`.

## Filament internals

Verified against `filament/tables` v5.9.0 on 2026-10-06. Method names are stable
references; line numbers are not.

**Every session write is gated.** In v5.9.0 each `session()->put` sits behind a
`persists*InSession()` check: `HasFilters::handleTableFilterUpdates()`,
`CanSortRecords::updatedTableSort()`, `CanSearchRecords::updatedTableSearch()` and
`updatedTableColumnSearches()`, `CanGroupRecords::updatedTableGrouping()`,
`CanPaginateRecords::updatedTableRecordsPerPage()`, and
`HasColumnManager::persistTableColumns()`. Per page was ungated in v5.8.1 and is gated
now. All seven flags default to false, so with `persistColumnsInSession(false)` added
in `AppPanelProvider`, Filament stops touching the session for table state entirely.
The database becomes the only store, and the eight workspace-scoped session key
overrides the September draft called for are unnecessary.

**Columns need one override.** With session persistence off,
`HasColumnManager::loadTableColumnsFromSession()` returns the default column state and
`persistTableColumns()` is a no-op, so the override replaces the method body rather
than wrapping `parent::`. `initTableColumnManager()` calls it only when `tableColumns`
is blank, which is the first mount. `applyTableColumnManager(?array $state, bool
$wasReordered)` is public and is the apply entry point; with session persistence off it
derives the reorder flag from `$wasReordered || hasReorderedTableColumns()`, so a saved
order survives without the session flag that v5.8.1 required.

**State shapes.** `tableSort` is one string, `"{column}:{direction}"`. `tableGrouping`
is `"{groupId}:{direction}"`. `tableFilters` is `['filterName' => ['field' => value]]`,
the raw filter form state. `tableSearch` is a string, `tableColumnSearches` a map of
column to term. `tableRecordsPerPage` accepts an int or the string `all`. `tableColumns`
is an ordered list of `{type, name, label, isHidden, isToggled, isToggleable,
isToggledHiddenByDefault}`.

A saved view stores only `type`, `name` and `isToggled` per column plus the order.
Everything else is re-derived from the live column definition on apply, and matching is
on `type` plus `name`, so a renamed or deleted column self-heals:
`syncReorderableColumnsFromDefaultTableColumnState()` drops unknown columns and appends
new ones. Column groups are not stored by id but by escaped label, which makes group
identity locale dependent. Our tables define no column groups, so this does not bite,
and the spec records it in case one is added.

**Mount ordering.** Livewire runs `boot`, `bootTrait`, `initializeTrait`, `mount`,
`mountTrait`, `booted`, `bootedTrait`, and `#[Url]` values land before all of them.
`mountInteractsWithTable()` therefore runs after URL hydration and before the session
restore block in `bootedInteractsWithTable()`, which makes it the seeding hook. Two
constraints: `$this->table` does not exist that early, so no `getTable()` call is
possible there, and each restore block is already guarded on the property being blank,
so seeding suppresses it.

**No remount is needed to apply a view.** `ListRecords` binds `tableFilters`,
`tableSort`, `tableSearch`, `tableGrouping` and `activeTab` to the query string with
`#[Url]` in replace mode, so a server-side assignment rewrites the URL in place.

**Per page is unvalidated at runtime.** `getDefaultTableRecordsPerPageSelectOption()`
is the only place Filament checks the value against
`getPaginationPageOptions()`, and it runs at mount. A saved view holding 100 against
options of 5, 10, 25 and 50 paginates by 100 silently, so the apply path validates.

## Architecture

### Storage

Two tables, ULID keys, matching the CRM convention.

`table_views`: `workspace_id` (cascade on delete), `user_id` (owner), `page`,
`name`, `is_shared`, `state` jsonb, timestamps. Index on
`workspace_id, page`.

`table_preferences`: `workspace_id`, `user_id`, `page`, `state` jsonb,
`active_view_id` and `default_view_id` (both null on delete), timestamps. Unique on
`user_id, workspace_id, page`.

`page` holds the list page class string. Both models get factories.

`state` serializes one `App\Data\TableStateData` readonly object: columns, sort,
filters, search, column searches, grouping, per page, widths.

### Trait

`App\Filament\Concerns\PersistsTableState` on the 5 app list pages (`ListCompanies`,
`ListPeople`, `ListOpportunities`, `ManageTasks`, `ManageNotes`):

1. `loadTableColumnsFromSession()`: return the stored column state, else the default.
2. `mountInteractsWithTable()`: call the parent, then seed sort, filters, search,
   column searches, grouping and per page from the row, each guarded on `blank()` so a
   shared URL wins.
3. `dehydrate()`: hash the current state, compare with the hash taken at boot, and
   persist through the action when it changed. One writer, one write per request.
4. `#[Renderless] updateTableColumnWidth()`: clamp and assign the width property only.
   The dehydrate sync persists it. `#[Renderless]` skips rendering, not dehydration.
5. `applySavedView()`, and the save, rename, share, delete, set-default and reset entry
   points, each delegating to an action.

### Actions and policy

`app/Actions/TableViews/`: `SaveTablePreference`, `CreateTableView`, `UpdateTableView`,
`DeleteTableView`, `SetDefaultTableView`. Eloquent writes from a Filament page fail
`EloquentWriteOutsideActionRule`, so every write goes through one of these.

`TableViewPolicy` must not reuse `ChecksWorkspaceWriteAccess`. That trait excludes
Viewers, and a view touches no CRM data, so any member including a Viewer may create
and share one. `view`: any member of the workspace for a shared view, the owner for a
private one. `update` and `delete`: the owner, or a workspace Admin when the view is
shared. An Admin cannot touch someone's private view.

### UI

A views control listing private views, then shared views, with Save, Save as, Rename,
Share, Delete and Reset. When the live state drifts from the applied view, the trigger
reads "Hot leads (modified)" and offers Save and Revert. Without that indicator the
control claims a view is active while the screen shows something else.

It ships as the page's own `ActionGroup` from `getHeaderActions()`, which has the
component instance and is straightforward to test. Moving it into the table toolbar at
`TablesRenderHook::TOOLBAR_START`, beside search and the column manager, is the better
placement and is evaluated during implementation with a browser check, since a render
hook closure has no access to the component.

All labels go through `__()` in `lang/en/filament/`, per the i18n PHPStan rules.

### Resize port

Replace `asmit/resized-column` with the Maxforms implementation, source paths in
`~/Herd/maxforms`:

- `app/Filament/Tables/Concerns/HasResizableColumns.php` (110 lines): the trait. Swap
  its `TableSetting` writes and session key for our preferences row, and keep the
  server-side clamp of 40 to 1000 pixels, since the Livewire action accepts any payload.
- `app/Filament/Tables/Macros/ResizableColumnsMacro.php` (147 lines): classifies each
  column as fixed (exact pixel width) or elastic (the width is a floor and the column
  absorbs leftover space), then emits the header and cell styles. Keep the
  classification: it is what stops a timestamp column stretching across a wide monitor.
- The resizable half of `resources/js/submissions.js` and
  `resources/css/submissions.css` (about 240 lines). The JS already uses
  `livewire:navigated` and the `morphed` Livewire hook, and registers its Alpine data
  factory on either `alpine:init` or immediately, so it survives our `->spa()` panel.

Register the JS with `FilamentAsset::register()` from `AppServiceProvider`, as
`payload-guard.js` already does, and commit the published file under `public/js/app`.
The CSS folds into `resources/css/filament/app/theme.css`.

Removing asmit also removes the reason for the z-index workaround at
`theme.css:538`, which exists because that package raises the table toolbar to z-21.
Re-derive it against the ported CSS or delete it, and verify dropdowns, row menus and
the sticky table head in a browser.

## Apply sequence

Order matters. `applySavedView()`:

1. `applyTableColumnManager($columns, wasReordered: true)` first: it flushes the cached
   visible columns that sort and search validity depend on.
2. `$this->tableSort = ...` then `updatedTableSort()`.
3. `$this->tableGrouping = ...` then `updatedTableGrouping()`.
4. Search: assign and call `updatedTableSearch()`, or `resetTableSearch()` when the view
   carries none.
5. Column searches: assign and call `updatedTableColumnSearches()`, or
   `resetTableColumnSearches()`, which also refills the reserved keys that a bare `= []`
   would skip.
6. Per page: validate against `getPaginationPageOptions()`, fall back to
   `getDefaultTableRecordsPerPageSelectOption()`, assign, then
   `updatedTableRecordsPerPage()`.
7. `getTableFiltersForm()->fill($filters)`: the popover schema binds to
   `tableDeferredFilters` and does not rehydrate itself, so without this the results
   change and the popover lies.
8. `applyTableFilters()`: copies deferred filters across, deselects records and resets
   the page.
9. `flushCachedTableRecords()` if records were already resolved this request.

Never call `resetTable()`: it re-runs the whole booted block and blanks the filter form.

## Edge cases

- **Workspace isolation.** The DB key includes `workspace_id`, and with session
  persistence off Filament holds no cross-workspace copy. Custom field columns differ
  per workspace, so this is the load-bearing part of the key.
- **Stale state.** Unknown column names are dropped and new ones appended on sync. A
  filter key for a deleted custom field is re-filled into the form and ignored by the
  query; the implementation asserts this rather than assuming it.
- **Two tabs.** Last dehydrate wins. Accepted.
- **No workspace-wide default.** The default view is per user. Forcing a default on a
  workspace is a separate feature.

## Tests

Feature tests through `livewire(ListCompanies::class)` in
`tests/Feature/Filament/App/Resources/`, matching the existing convention:

1. Hidden column, reordered columns and page size survive a remount.
2. A second workspace keeps its own state for the same page.
3. A saved view applies columns, filters, sort, search and per page in one call.
4. A view holding a per-page value outside the options falls back instead of applying.
5. A shared view is visible to another member, editable by an Admin, not by a Viewer
   who does not own it.
6. A private view is invisible to everyone else, including Admins.
7. A renamed or deleted column self-heals in a stored view.
8. A width update persists through the renderless action.

## Delivery

One PR, three commits, so a revert is surgical:

1. Port the resize implementation and drop `asmit/resized-column`, at today's behaviour.
2. Per-user, per-workspace persistence of the full table state. Closes #740.
3. Named views: sharing, policy, UI.

## Out of scope

Relation managers, `ActivityLog`, `ImportHistory` and the Livewire settings tables keep
Filament's default behaviour. The SystemAdmin panel is untouched. Widths stay global per
user and per page rather than being switched by a view, which keeps the resize port
independent of the views feature.

## Open decisions

1. **Default page size.** #740 notes the default of 10 feels cramped, which persistence
   does not fix for a new user. Either set `defaultPaginationPageOption(25)` in the
   panel or leave the default alone.
2. **Toolbar placement.** Header action group versus `TOOLBAR_START`, resolved with a
   browser check during implementation.
