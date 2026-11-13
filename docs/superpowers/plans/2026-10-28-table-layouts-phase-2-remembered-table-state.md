# Table layouts phase 2: remembered table state Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Everything a member does to one of the five CRM tables (columns, sort, filters, search, column searches, grouping, page size, widths) is saved per member, per workspace and per table, and comes back on their next visit on any device.

**Architecture:** A `table_preferences` row holds a `TableStateData` per member, workspace and `CrmEntity`. A `PersistsTableState` trait on the five pages seeds Filament's table properties from that row on mount and writes the row from `dehydrate` only when the state differs from the state the request started with. Filament's own session persistence is switched off for those five pages only, so the row is the one store.

**Tech Stack:** Laravel 13, Filament 5.9 (`filament/tables`), Livewire 4, spatie/laravel-data 4.23, PostgreSQL, Pest.

**Spec:** `docs/superpowers/specs/2026-10-27-table-layouts-design.md`, sections "Phase 2: Remembered table state", "Domain model" (`table_preferences`, `TableStateData`, Self-healing), "Filament internals", "Edge cases".

## Global Constraints

- Scope is exactly five pages: `ListCompanies`, `ListPeople`, `ListOpportunities`, `ManageTasks`, `ManageNotes`. Relation managers and every other table keep Filament's session behaviour.
- Row key: `(user_id, workspace_id, entity)`, unique. `entity` is a `CrmEntity` value, never a page class name.
- `table_preferences` columns for this phase only: `id` (ulid), `workspace_id`, `user_id` (ulid FKs, cascade on delete), `entity` (string), `state` (jsonb), timestamps. The three phase 3 columns are not added now.
- Opening a table writes nothing. A write happens only when the member changes something, at most once per request, through `SaveTablePreference`.
- A query-string sort, filter or search wins over saved state on mount.
- Stale state heals in memory (dropped columns, widths, sort; new columns appended). The row is rewritten only on the member's next change.
- Width bounds stay `40` to `1000` px (`ResizableColumns::MIN_WIDTH` / `MAX_WIDTH`).
- Migration has `up()` only. Every datetime is written from PHP `now()`, never a DB default.
- No new PHPStan ignore. No em-dash. Comments: two lines max, only a non-obvious why. No comments in tests.
- `/docs/*` is gitignored. Add this plan with `git add -f`.

## Deliberate deviations from the spec

- **Session flag scope.** The spec says `persistInSession(false)` in `AppPanelProvider` "scoped to the app panel". Panel-wide would also strip session state from relation managers, a non-goal. The flag is set in the panel's existing `Table::configureUsing()` only when the page uses `PersistsTableState`.
- **Baseline hash.** The spec says "compare with the hash taken at boot". Boot order between our trait and `bootedInteractsWithTable()` is fragile, so the baseline is a `#[Locked]` public property set on the first dehydrate (the mount request) and after each write. Same guarantee, one hook.

## Review Focus

1. **Shared URL on top of saved state.** A member with a saved sort opens `?sort=created_at:asc`: the URL sort shows, and nothing is written until they change something. Test in Task 1.
2. **A saved page size the table no longer offers** (say `200`): the table opens at the default `25`. Test in Task 1.
3. **A saved sort or column naming a deleted custom field:** the page opens, the column is gone, the sort is cleared, and new columns appear with default visibility. Tests in Task 1.
4. **A width-only request.** `updateResizableColumnWidth` is `#[Renderless]`; the width must still be written, because Livewire dehydrates without rendering. Test in Task 2.
5. **Relation managers untouched.** `PeopleRelationManager` on `ViewCompany` keeps Filament's defaults: columns persist in the session, sort does not. Test in Task 1.

## File map

| File | Responsibility | Task |
|---|---|---|
| `database/migrations/2026_10_28_000001_create_table_preferences_table.php` | the table | 1 |
| `app/Models/TablePreference.php`, `database/factories/TablePreferenceFactory.php` | model, factory | 1 |
| `app/Data/TableStateData.php` | state object, cast into `state` | 1 |
| `app/Actions/TableLayouts/SaveTablePreference.php` | the one writer | 1 |
| `app/Filament/Tables/Concerns/PersistsTableState.php` | seed on mount, heal, write on dehydrate | 1 |
| `app/Providers/Filament/AppPanelProvider.php` | session flag off for trait users | 1 |
| the five page classes | `use PersistsTableState` | 1 |
| `tests/Feature/Filament/App/Tables/PersistsTableStateTest.php` | phase 2 behaviour on all five pages | 1, 2 |
| `app/Filament/Tables/Concerns/HasResizableColumns.php`, `app/Filament/Tables/ResizableColumns.php` | widths become a property, shape `array<string, int>` | 2 |
| `tests/Feature/Filament/App/Tables/ResizableColumnsTest.php` | updated for the property and int shape | 2 |
| `tests/Browser/CRM/ResizableColumnsTest.php` | a width survives a fresh login | 3 |

---

### Task 1: Storage and the state trait (everything except widths)

**Files:**
- Create: the migration, `TablePreference`, `TablePreferenceFactory`, `TableStateData`, `SaveTablePreference`, `PersistsTableState`, `tests/Feature/Filament/App/Tables/PersistsTableStateTest.php`
- Modify: `app/Providers/Filament/AppPanelProvider.php` (`Table::configureUsing`, around line 165), the five page classes

**Interfaces:**
- Produces:
  - `final class TableStateData extends Spatie\LaravelData\Data` with constructor properties, all defaulted empty: `array $columns = []` (`list<array{type: string, name: string, isToggled: bool}>`), `?string $sort = null`, `array $filters = []`, `?string $search = null`, `array $columnSearches = []` (`array<string, string>`), `?string $grouping = null`, `int|string|null $perPage = null`, `array $widths = []` (`array<string, int>`). Method `hash(): string` returning `hash('xxh128', json_encode($this->toArray(), JSON_THROW_ON_ERROR))`.
  - `TablePreference` model: `#[ScopedBy(WorkspaceScope::class)]`, `#[Fillable(['workspace_id', 'user_id', 'entity', 'state'])]`, `HasUlids`, `HasFactory`, `HasWorkspace`, `user(): BelongsTo`; casts `entity => CrmEntity::class`, `state => TableStateData::class`.
  - `SaveTablePreference::execute(User $user, Workspace $workspace, CrmEntity $entity, TableStateData $state): TablePreference`. `abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403)`, then `updateOrCreate` on the three keys with the scope removed (`withoutGlobalScope(WorkspaceScope::class)`), because the explicit `workspace_id` is the guard.
  - Trait `PersistsTableState`: `#[Locked] public ?string $tableStateHash = null`; overrides `mountInteractsWithTable(): void` and `protected loadTableColumnsFromSession(): array`; hooks `bootedPersistsTableState(): void` and `dehydratePersistsTableState(): void`; private `tableEntity(): CrmEntity`, `storedTableState(): TableStateData` (memoised per request, empty `TableStateData` when no row), `currentTableState(): TableStateData`.

- [ ] **Step 1: Write the failing tests** in `PersistsTableStateTest.php`. Reuse the `list pages` dataset and `beforeEach` from `ResizableColumnsTest.php` (same five pages, `withWorkspace()` user, tenant, app panel). `mutates(PersistsTableState::class, SaveTablePreference::class)`. A "new visit" in a test is `session()->flush()` then a fresh `livewire($page)`. Tests:

```php
it('brings back hidden columns, order, sort, filters, search and page size on a new visit', function (string $page): void {
    // change each through the component: applyTableColumnManager (hide created_at, move it first, wasReordered: true),
    // set tableSort 'created_at:desc', set tableSearch 'acme', set tableRecordsPerPage 50,
    // set one filter the page has (trashed => ['value' => 0]); then session()->flush() and remount.
    // expect each property back, and the column order and isToggled from tableColumns.
})->with('list pages');

it('writes nothing when a table is opened', function (string $page): void {
    livewire($page);
    expect(TablePreference::query()->withoutGlobalScopes()->count())->toBe(0);
    // and with an existing row: travelTo(+1 hour), livewire($page), updated_at unchanged
})->with('list pages');

it('writes one row per member, workspace and table', ...);           // two changes on Companies, one on People: 2 rows
it('keeps state apart per workspace', ...);                            // attach a second workspace with role 'admin', setTenant, sort differs
it('lets a query string sort win over saved state and writes nothing', ...); // livewire::withQueryParams(['sort' => 'name:asc'])
it('opens at the default page size when the saved one is not offered', ...); // seeded perPage 200 -> 25
it('heals a state naming a column the table no longer has', ...);      // seeded columns + sort on 'custom_fields.gone': assertOk, column absent, tableSort null, every live column present
it('loads a filter saved for a deleted custom field', ...);           // seeded filters ['custom_fields.gone' => ['value' => 'x']]: assertOk, records still listed
it('stops writing table state to the session', ...);                   // after a sort and a column toggle, no session key starts with 'tables.'
it('leaves relation managers on session persistence', ...);            // PeopleRelationManager on ViewCompany: persistsColumnsInSession() true and persistsSortInSession() false (Filament's defaults)
```

Seed rows with `TablePreference::factory()->for($user)->for($workspace)->create(['entity' => CrmEntity::Company, 'state' => new TableStateData(...)])`.

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Filament/App/Tables/PersistsTableStateTest.php`
Expected: FAIL, class `TablePreference` not found.

- [ ] **Step 3: Migration, model, factory, data object, action.** `php artisan make:migration create_table_preferences_table --no-interaction`, `php artisan make:model TablePreference --factory --no-interaction`, then shape them to the Interfaces block. Migration: `ulid('id')->primary()`, `foreignUlid('workspace_id')->constrained()->cascadeOnDelete()`, same for `user_id`, `string('entity')`, `jsonb('state')`, `timestamps()`, `unique(['user_id', 'workspace_id', 'entity'])`. Factory default `entity` is `CrmEntity::Company`, `state` is `new TableStateData`.

- [ ] **Step 4: The trait.** Rules the tests do not spell out:
  - `mountInteractsWithTable()`: `parent::mountInteractsWithTable()`, then from `storedTableState()` assign `tableSort`, `tableFilters`, `tableSearch`, `tableColumnSearches`, `tableGrouping`, `tableRecordsPerPage`, each only when the property is `blank()`. Raw assignment only: `getTable()` does not exist yet.
  - `loadTableColumnsFromSession()`: when the stored `columns` is empty, return `getDefaultTableColumnState()`. Otherwise return the stored order, each item being its default descriptor with the stored `isToggled` laid over it; stored names the table lacks are dropped. Filament appends new defaults itself (`syncReorderableColumnsFromDefaultTableColumnState`). Returning full descriptors keeps Filament's docblock shape, so PHPStan needs no ignore.
  - `bootedPersistsTableState()`: clear `tableSort` when its column is not in `getTable()->getColumns()`; reset `tableRecordsPerPage` to `getDefaultTableRecordsPerPageSelectOption()` when it is not in `getTable()->getPaginationPageOptions()`.
  - `dehydratePersistsTableState()`: `$hash = $this->currentTableState()->hash()`. When `tableStateHash` is null (mount request), store the hash and return. When it differs, call `SaveTablePreference` with `auth()->user()`, `Filament::getTenant()`, `tableEntity()`, then store the hash.
  - `currentTableState()`: `columns` keeps only `type`, `name`, `isToggled` of each `tableColumns` item. Widths come in Task 2; leave `widths` empty here.
  - `storedTableState()` reads `TablePreference::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', Filament::getTenant()->getKey())->whereBelongsTo(auth()->user())->where('entity', $this->tableEntity())->first()?->state`, memoised in a private property.
  - `tableEntity()`: `CrmEntity::tryFromModel(new ($this->getModel()))`, as `HasCustomFieldColumns` does.

- [ ] **Step 5: Panel flag and pages.** In `Table::configureUsing()` add `->when(in_array(PersistsTableState::class, class_uses_recursive($table->getLivewire()), true), fn (Table $table): Table => $table->persistInSession(false))`. Never `persistInSession(! ...)`: on any other table that passes `true` and switches on all seven session flags, where Filament's default has only columns and page size on. Add `use PersistsTableState;` to the five pages beside `HasResizableColumns`.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Feature/Filament/App/Tables/PersistsTableStateTest.php`
Expected: PASS, every dataset row.

- [ ] **Step 7: Run the neighbours**

Run: `php artisan test --compact tests/Feature/Filament/App/Tables/ResizableColumnsTest.php` and `composer test:affected`
Expected: PASS. The phase 1 width tests still pass because widths still live in the session until Task 2.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/TablePreference.php database/factories/TablePreferenceFactory.php app/Data/TableStateData.php app/Actions/TableLayouts app/Filament app/Providers/Filament/AppPanelProvider.php tests/Feature/Filament/App/Tables/PersistsTableStateTest.php
git commit -m "feat(tables): remember table state per member, workspace and table"
```

### Task 2: Widths move from the session to the row

**Files:**
- Modify: `app/Filament/Tables/Concerns/HasResizableColumns.php`, `app/Filament/Tables/ResizableColumns.php`, `app/Filament/Tables/Concerns/PersistsTableState.php`
- Test: `tests/Feature/Filament/App/Tables/PersistsTableStateTest.php`, `tests/Feature/Filament/App/Tables/ResizableColumnsTest.php`

**Interfaces:**
- Consumes: `TableStateData::$widths`, `PersistsTableState::currentTableState()` and `mountInteractsWithTable()` from Task 1.
- Produces: `HasResizableColumns` gets `#[Locked] public array $tableColumnWidths = []` (`array<string, int>`); `getResizableColumnWidths(): array<string, int>` returns it. The session key method is deleted.

- [ ] **Step 1: Write the failing tests.** In `PersistsTableStateTest.php`:

```php
it('brings back a width on a new visit', function (string $page): void {
    livewire($page)->call('updateResizableColumnWidth', 'created_at', '300');
    session()->flush();

    expect(livewire($page)->instance()->getResizableColumnWidths())->toBe(['created_at' => 300]);
})->with('list pages');

it('drops a saved width for a column the table no longer has', ...); // seeded widths ['gone' => 300, 'created_at' => 250]: getResizableColumnWidths() is ['created_at' => 250]
```

In `ResizableColumnsTest.php`, change every `['created_at' => ['width' => 'N']]` expectation to `['created_at' => N]`, and rename `keeps a width for the session` to `keeps a width across visits` with a `session()->flush()` before the remount. Keep the clamp, garbage, unknown-column, hide-and-show and per-page tests as they are.

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Filament/App/Tables --parallel --processes=4 --filter='(PersistsTableStateTest|ResizableColumnsTest)::'`
Expected: FAIL on the width shape and the new-visit width.

- [ ] **Step 3: Implement.** `updateResizableColumnWidth()` keeps its guard and clamp, assigns `$this->tableColumnWidths[$columnName] = $width`, and keeps the re-stamp. `ResizableColumns::apply()` reads `$widths[$name] ?? $column->getWidth() ?? self::DEFAULT_COLUMN_WIDTH`. `PersistsTableState::mountInteractsWithTable()` seeds `tableColumnWidths` from the row, and `currentTableState()` sets `widths` to `array_intersect_key($this->tableColumnWidths, $this->getTable()->getColumns())`.

- [ ] **Step 4: Run the tests**

Run: the Step 2 command.
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Tables tests/Feature/Filament/App/Tables
git commit -m "feat(tables): store column widths with the rest of the table state"
```

### Task 3: Browser proof, migration rehearsal, quality loop

**Files:**
- Modify: `tests/Browser/CRM/ResizableColumnsTest.php`

- [ ] **Step 1: Browser test.** Add `it('keeps a dragged width after signing in again')`: drag the `created_at` handle on Companies as the existing drag test does, wait for the save, log out, log in as the same user in the same browser, open Companies, assert the header's `data-resizable-width` equals the dragged width.

- [ ] **Step 2: Run it in both engines**

Run: `php artisan test --compact tests/Browser/CRM/ResizableColumnsTest.php` then the same with `--browser safari`
Expected: PASS in both.

- [ ] **Step 3: Walk it in agent-browser** (`agent-browser-relaticle` skill). On all five tables: hide a column, reorder one, resize one, sort, filter, set page size 50. Log out, log in, confirm each came back. Switch workspace, confirm the table is at defaults. Open a table without touching it and confirm `table_preferences.updated_at` did not move (`database-query`).

- [ ] **Step 4: Rehearse the migration** per `core.md`: `pg_dump -s` of production and the `migrations` table, load into a scratch database, `DB_DATABASE=<scratch> php artisan migrate --force`, confirm only `create_table_preferences_table` ran, run again to see a no-op, drop the scratch database. It reads no rows, so no row diff applies.

- [ ] **Step 5: Quality loop before pushing**

Run: `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `composer test:lint`, `composer test:arch`
Expected: all clean. Apply rector if it suggests anything.

- [ ] **Step 6: Commit and push**

```bash
git add tests/Browser/CRM/ResizableColumnsTest.php
git add -f docs/superpowers/plans/2026-10-28-table-layouts-phase-2-remembered-table-state.md
git commit -m "test(tables): prove a resized width survives signing in again"
git push -u origin feat/943-2-remembered-table-state
```

Open the PR against `feat/943-1-resize-port` (stacked, per the spec) and watch the `Tests` workflow in the background.
