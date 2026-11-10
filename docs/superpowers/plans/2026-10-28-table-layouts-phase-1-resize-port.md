# Table layouts phase 1: resize port Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `asmit/resized-column` with the Maxforms column resizer on the five CRM list tables, at today's behaviour (widths remembered for the session).

**Architecture:** A Livewire trait on each list page owns the widths (session storage, server-side clamp). A static `ResizableColumns::apply()` stamps every column with its width and sizing mode. An Alpine component, registered as a Filament asset, draws the handles, the guideline and the width badge, and sends a width back after a drag.

**Tech Stack:** Laravel 13.35, Filament 5.9.0, Livewire 4, Alpine, Pest (the repo's fork), feature and browser tests.

**Spec:** `docs/superpowers/specs/2026-10-27-table-layouts-design.md`, section "Phase 1: Resize port".

## Global Constraints

- Scope is exactly five pages: `ListCompanies`, `ListPeople`, `ListOpportunities`, `ManageTasks`, `ManageNotes`.
- Server clamp: `40` to `1000` px. A width of `0` or below, or a non-number, is ignored.
- Default width for a column with no saved width and no `->width()`: `180` px.
- Every data column renders an exact width; the actions column takes the space left over. (Changed after review of a wide-screen bug: the Maxforms fixed/elastic split was dropped, see the ledger.)
- Widths live in the session only, keyed per page class. No migration, no model.
- Colours come from the panel's CSS variables (`--gray-*`, `--primary-*`, `--danger-*`). No `rgb()` or hex value in the ported CSS.
- No em-dash anywhere (`tests/Arch/ConventionsTest.php`). Comments: two lines at most, only a non-obvious why.
- No new PHPStan ignore. This is why the plan replaces the spec's `Table::resizableColumns()` macro with a static `ResizableColumns::apply()`: a macro call fails PHPStan without an ignore.
- `/docs/*` is in `.gitignore`. Add this plan with `git add -f`.

## Review Focus

1. **SPA navigation.** Moving from Companies to People with `wire:navigate` leaves a working resizer on People, and no handles are duplicated. The browser test in Task 2 navigates before it drags.
2. **Livewire re-render.** Sorting, filtering or paginating after a resize keeps the width and the handles. The browser test in Task 2 sorts after the drag.
3. **A hostile payload.** `updateResizableColumnWidth('name', 'abc')`, `'-5'` and `'99999'` change nothing, nothing and clamp to 1000. Feature test in Task 2.
4. **Column manager toggles.** A column hidden and then shown again keeps its handle and its saved width. Feature test in Task 2.
5. **Header dropdowns above the table.** The page-header Import / Export dropdown and the row `⋮` menus paint above the sticky table head once asmit's toolbar lift is gone. The existing test `paints the header action dropdown above the table toolbar` in `tests/Browser/CRM/CompanyBrowserTest.php` is the gate in Task 3.

## Starting material

The branch `spike/maxforms-resize` produced a working port, visually checked in Chromium on Companies:

- Untracked in the working tree: `app/Filament/Tables/Concerns/HasResizableColumns.php` (session-only trait), `app/Filament/Tables/Macros/ResizableColumnsMacro.php` (Maxforms copy), `resources/js/filament/resizable-columns.js` (the resize half of `submissions.js`, wiring trimmed), `public/js/app/resizable-columns.js` (published copy).
- `git stash@{0}`: the spike's edits to `AppServiceProvider`, `ListCompanies` and `theme.css` (the ported CSS with colours already moved to variables).
- Maxforms source: `../maxforms-resizable` (commit `de1c8eeb9`).

Use them as source. Every task still rewrites comments and names to this repo's rules.

## File map

| File | Responsibility | Task |
|---|---|---|
| `resources/js/filament/resizable-columns.js` | Alpine `resizableColumn` component: handles, guideline, badge, auto-fit, sticky head | 1 |
| `public/js/app/resizable-columns.js` | published copy, committed | 1 |
| `resources/css/filament/app/theme.css` | resize styles, appended as one block | 1 |
| `app/Providers/AppServiceProvider.php` | registers the JS asset | 1 |
| `app/Filament/Tables/Concerns/HasResizableColumns.php` | width state, session storage, clamp | 2 |
| `app/Filament/Tables/ResizableColumns.php` | stamps columns with width and sizing mode | 2 |
| `app/Filament/Concerns/HasCustomFieldColumns.php` | calls `ResizableColumns::apply()` last | 2 |
| the five page classes | swap the asmit trait for ours | 2 |
| `tests/Feature/Filament/App/Tables/ResizableColumnsTest.php` | server behaviour on all five pages | 2 |
| `tests/Browser/CRM/ResizableColumnsTest.php` | drag, auto-fit, reload, navigation, re-render | 2 |
| `composer.json`, `composer.lock`, `AppPanelProvider.php`, `public/css/asmit`, `public/js/asmit` | asmit removed | 3 |
| `resources/css/filament/app/theme.css` (Dropdowns block) | z-index re-derived | 3 |
| the spec | macro line updated to `ResizableColumns::apply()` | 3 |

---

### Task 1: Front-end assets, inert until a table opts in

The JS only acts on headers carrying `x-data="resizableColumn(...)"`, and the CSS only on `fi-ta-resizable-*`, `data-resizable-*` and `column-resize-*`. Nothing renders those yet, so this task changes no page.

**Files:**
- Create: `resources/js/filament/resizable-columns.js`, `public/js/app/resizable-columns.js`
- Modify: `app/Providers/AppServiceProvider.php` (`registerPanelScripts()`), `resources/css/filament/app/theme.css` (append)

**Interfaces:**
- Produces: global Alpine data `resizableColumn(columnName: string, columnId: string, minWidth: number, maxWidth: number)`. After a drag or auto-fit it calls `$wire.updateResizableColumnWidth(columnName, String(width))`. It reads these attributes on `th` and `td`: `data-resizable-width`, `data-resizable-type` (`fixed` | `elastic`), `data-resizable-column-id`, and adds class `fi-ta-resizable-table` to the table.

- [ ] **Step 1: Port the JS.** Start from the spike's `resources/js/filament/resizable-columns.js`. Keep the logic as is. Rewrite every comment to the repo's rules: delete those that narrate, keep at most two lines of why, and remove every em-dash. Rename the generated sticky-header attribute `data-mf-sticky-header` to `data-sticky-header`. It ends with the registration on `alpine:init`, or immediately when Alpine is already running.

- [ ] **Step 2: Check the JS parses and carries no em-dash.**

Run: `node --check resources/js/filament/resizable-columns.js && ! grep -n "$(printf '\xe2\x80\x94')" resources/js/filament/resizable-columns.js`
Expected: exit 0, no output.

- [ ] **Step 3: Register the asset.** In `AppServiceProvider::registerPanelScripts()`, add `Js::make('resizable-columns', resource_path('js/filament/resizable-columns.js'))` after `linked-records-picker`. Then run `php artisan filament:assets`.

Expected: `public/js/app/resizable-columns.js` exists and matches the source.

- [ ] **Step 4: Port the CSS.** Apply only the spike's `theme.css` hunk: `git diff 'stash@{0}^' 'stash@{0}' -- resources/css/filament/app/theme.css | git apply`. If it fails because `main` moved, append the block by hand from the stash. Then rewrite its comments as in Step 1, and confirm no colour literal is left.

Run: `awk '/Resizable table columns/,0' resources/css/filament/app/theme.css | grep -nE "rgb\(|#[0-9a-fA-F]{3,6}\b|$(printf '\xe2\x80\x94')"`
Expected: no output, apart from `rgb(0 0 0 / ...)` shadows.

- [ ] **Step 5: Build and check nothing changed visually.** Run `pnpm run build`. Open Companies on relaticle.test: the table looks and resizes exactly as before (asmit is still active). There are no console errors.

- [ ] **Step 6: Commit.**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/filament/resizable-columns.js public/js/app/resizable-columns.js app/Providers/AppServiceProvider.php resources/css/filament/app/theme.css
git commit -m "feat(tables): add the resizable columns script and styles"
```

### Task 2: Resizable columns on the five tables

**Files:**
- Create: `app/Filament/Tables/Concerns/HasResizableColumns.php`, `app/Filament/Tables/ResizableColumns.php`
- Delete: `app/Filament/Tables/Macros/ResizableColumnsMacro.php` (spike leftover, never committed)
- Modify: `app/Filament/Concerns/HasCustomFieldColumns.php` (the `table()` return), and the five page classes
- Test: `tests/Feature/Filament/App/Tables/ResizableColumnsTest.php`, `tests/Browser/CRM/ResizableColumnsTest.php`

**Interfaces:**
- Consumes: the Alpine component from Task 1.
- Produces:
  - trait `App\Filament\Tables\Concerns\HasResizableColumns`, with:
    - `public function getResizableColumnWidths(): array` (shape `array<string, array{width: string}>`)
    - `#[Renderless] public function updateResizableColumnWidth(string $columnName, string $newWidth): void`
  - `final class App\Filament\Tables\ResizableColumns`, with `public const int MIN_WIDTH = 40`, `public const int MAX_WIDTH = 1000` (PHP forbids reading a trait's constant directly) and `public static function apply(Table $table): Table`. It returns the table untouched when the Livewire component does not use the trait.

- [ ] **Step 1: Write the failing feature tests.** Use `dataset('list pages', [ListCompanies::class, ListPeople::class, ListOpportunities::class, ManageTasks::class, ManageNotes::class])`, `mutates(HasResizableColumns::class, ResizableColumns::class)`, and the `beforeEach` of `CompanyResourceTest` (workspace user, tenant, app panel). `created_at` is hidden by default on People, Opportunities and Tasks, so assert on the column objects, not on rendered HTML: `$attributes = fn (string $name): array => $component->instance()->getTable()->getColumn($name)->getExtraHeaderAttributes()`. Tests:
  - `it('marks every column resizable', ...)`: every column of `getTable()->getColumns()` has an `x-data` header attribute starting `resizableColumn(`. `created_at` has `data-resizable-type` `fixed`, and the title column (`CrmEntity::tryFromModel(...)->titleColumn()`) has `elastic`.
  - `it('gives a column the default width', ...)`: the title column's `data-resizable-width` is `'180'`.
  - `it('keeps a width for the session', ...)`: `livewire($page)->call('updateResizableColumnWidth', 'created_at', '300')`. A fresh `livewire($page)` then has `getResizableColumnWidths()` equal to `['created_at' => ['width' => '300']]`, and `created_at`'s `data-resizable-width` is `'300'`.
  - `it('clamps a width to the allowed range', ...)`: `'5'` stores `'40'`, and `'99999'` stores `'1000'`.
  - `it('ignores a width that is not a positive number', ...)`: `'0'`, `'-5'` and `'abc'` each leave `getResizableColumnWidths()` as `[]`.
  - `it('keeps the width of a column hidden and shown again', ...)`: save `created_at` at `300`, toggle it on, off and on again through the column manager (`applyTableColumnManager`), and assert `created_at`'s `data-resizable-width` is `'300'` and that the rendered HTML contains `data-resizable-width="300"`.
  - `it('keeps widths apart per page', ...)`: a width saved on `ListCompanies` does not appear in `ListPeople`'s `getResizableColumnWidths()`.

- [ ] **Step 2: Run them to see them fail.**

Run: `php artisan test --compact tests/Feature/Filament/App/Tables/ResizableColumnsTest.php`
Expected: FAIL, because the trait and class do not exist.

- [ ] **Step 3: Write the trait.** Start from the spike's session-only trait. The session key is `'tables.resizable.'.hash('xxh128', static::class)`. `updateResizableColumnWidth` ignores a value whose integer form is `0` or below, then clamps.

- [ ] **Step 4: Write `ResizableColumns::apply()`.** Port the body of the Maxforms macro's closure. The column id is `Str::of($name)->camel()->kebab()`. The mode is decided in this order: `->width()` set means fixed, then `->grow()` means elastic, then the system-column patterns mean fixed, and anything else is elastic. Header and cell attributes are as the macro emits them (`x-data` on the header only, `overflow: hidden` added to cells). The patterns and the default width are private constants.

- [ ] **Step 5: Wire it.** `HasCustomFieldColumns::table()` returns `ResizableColumns::apply($table->...)`, so custom field columns are included. On each of the five pages, replace `use Asmit\ResizedColumn\HasResizableColumn;` and `use HasResizableColumn;` with our trait. Delete the spike's macro file.

- [ ] **Step 6: Run the feature tests until they pass.**

Run: `php artisan test --compact tests/Feature/Filament/App/Tables/ResizableColumnsTest.php`
Expected: PASS, all cases on all five pages.

- [ ] **Step 7: Write the browser tests.** Follow `CompanyBrowserTest`: `loginViaBrowser`, `$this->withVite()`, `$page->script(...)` for geometry. Tests:
  - `it('resizes a column by dragging its edge and keeps the width after a reload', ...)`: drag the Account Owner handle 120px right, then assert the header grew by 120 ± 2px, reload, and assert the same width.
  - `it('fits a column to its content on double-click', ...)`: double-click a handle, and assert the width changed and stays within 40 to 1000.
  - `it('keeps resizing after navigating to another table and after sorting', ...)`: navigate Companies → People with the sidebar link, drag a People handle, sort by Name, and assert the width held and each header has exactly one `.column-resize-handle-bar`.
  - Each test ends with `->assertNoJavaScriptErrors()`.

- [ ] **Step 8: Run the browser tests in Chromium, then WebKit.**

Run: `php artisan test --compact tests/Browser/CRM/ResizableColumnsTest.php && php artisan test --compact tests/Browser/CRM/ResizableColumnsTest.php --browser safari`
Expected: PASS in both.

- [ ] **Step 9: Commit.**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Tables app/Filament/Concerns/HasCustomFieldColumns.php app/Filament/Resources tests/Feature/Filament/App/Tables tests/Browser/CRM/ResizableColumnsTest.php
git commit -m "feat(tables): resize columns on the five CRM tables with our own resizer"
```

### Task 3: Remove asmit/resized-column

**Files:**
- Modify: `composer.json`, `composer.lock`, `app/Providers/Filament/AppPanelProvider.php` (the `ResizedColumnPlugin` import and the `ResizedColumnPlugin::make()` plugin entry), `resources/css/filament/app/theme.css` (the `.fi-dropdown-panel` rule under "Dropdowns" and its comment), `docs/superpowers/specs/2026-10-27-table-layouts-design.md`
- Delete: `public/css/asmit/`, `public/js/asmit/`

- [ ] **Step 1: Remove the package.** Run `composer remove asmit/resized-column`, remove the plugin from `AppPanelProvider`, delete the published `public/css/asmit` and `public/js/asmit`, then run `php artisan filament:assets`.

Run: `grep -rn -i 'asmit\\\\\|ResizedColumn\|resized-column' app packages config bootstrap composer.json resources public/js public/css`
Expected: no output.

- [ ] **Step 2: Re-derive the dropdown z-index.** The rule `.fi-dropdown-panel { @apply z-[22] ... }` exists because asmit lifted the toolbar to 21. Our sticky head sits at 16, below Filament's default of 20. Remove `z-[22]` from the rule, keep `rounded-xl p-1`, and delete the asmit comment.

- [ ] **Step 3: Run the dropdown gate and the new browser tests.**

Run: `php artisan test --compact tests/Browser/CRM --filter='(CompanyBrowserTest|ResizableColumnsTest)::'`, then the same with `--browser safari`.
Expected: PASS in both engines. If `paints the header action dropdown above the table toolbar` fails, restore the smallest z-index that passes, and replace the comment with one line naming the new reason.

- [ ] **Step 4: Check by eye in the browser.** On each of the five tables, in light and dark mode, check four things: the row `⋮` menu paints above the sticky head and the next rows; the column manager and filter dropdowns open above the table; the head stays pinned while scrolling; and resizing works.

- [ ] **Step 5: Update the spec.** In Phase 1 and Code layout, replace `ResizableColumnsMacro` / `Table::resizableColumns()` with `ResizableColumns::apply()` and its one-line reason (no PHPStan ignore).

- [ ] **Step 6: Commit.**

```bash
git add -A composer.json composer.lock app/Providers/Filament/AppPanelProvider.php resources/css/filament/app/theme.css public/css public/js
git add -f docs/superpowers/specs/2026-10-27-table-layouts-design.md docs/superpowers/plans/2026-10-28-table-layouts-phase-1-resize-port.md
git commit -m "chore(deps): remove asmit/resized-column"
```

### Task 4: Pre-push checks

- [ ] **Step 1: Run the scoped tests.** Run `composer test:affected`. If it refuses, run `php artisan test --compact tests/Feature/Filament/App/Resources --parallel --processes=4` plus the two new test files.

Expected: PASS.

- [ ] **Step 2: Run the static checks.**

Run: `vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:lint && composer test:arch`
Expected: all pass. When rector suggests a change, apply it with `vendor/bin/rector` and commit. Never add a PHPStan ignore.

- [ ] **Step 3: Check the spec's "Done when" for phase 1.** Each line maps to a test above, except `asmit/resized-column is gone from composer.json and composer.lock`. Check that one with `grep -c resized-column composer.json composer.lock`, which should print `0` twice.
