# Task Checklists Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `sdd-lean` (this user's replacement for
> superpowers:subagent-driven-development) or superpowers:executing-plans to implement this plan
> task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A task's description holds a tickable checklist: started from `/checklist` or `[ ] `, saved
with the form, counted on the board card, writable by agents as `- [ ]` markdown, and visible as
`[x]`/`[ ]` in activity and Rela's approval card.

**Architecture:** TipTap's official `TaskList`/`TaskItem` from `@tiptap/extension-list`, bundled as an
ES module whose `@tiptap/*` imports resolve to `window.FilamentRichEditor.tiptap`, loaded by a
`ChecklistPlugin` that only the task description editor gets. The PHP side uses tiptap-php's
`TaskList` and an app `TaskItem` subclass that reads `data-checked`. Plain-text, markdown and board
helpers read the same `li[data-type="taskItem"][data-checked]` markup.

**Tech Stack:** Laravel 13, Filament 5.9 RichEditor, TipTap 3.32, ueberdosis/tiptap-php 2.2.1, Vite 8
(Rolldown), Pest 5 with the browser plugin.

**Spec:** `docs/superpowers/specs/2026-11-06-task-checklists-design.md`

## Global Constraints

- Checklists exist only in the task description field: `entity_type = 'task'`, `code = 'description'`.
  Every check goes through `App\Models\CustomField::holdsChecklists()` (Task 2).
- `@tiptap/extension-list` at `^3.32.2` in `dependencies`. No other new package.
- The bundle never imports TipTap or ProseMirror code of its own (`slash-menu.js:1` explains why).
- Stored markup is TipTap's: `<ul data-type="taskList">`, `<li data-checked="true|false" data-type="taskItem"><label><input type="checkbox"[ checked="checked"]><span></span></label><div><p>text</p></div></li>`.
- `nested: false`. `Cmd/Ctrl+Shift+9` stays bound.
- Plain-text markers are exactly `[x] ` and `[ ] `.
- No migration, no config key, no env var. No sanitizer change.
- Copy follows `.ai/guidelines/relaticle/writing.md`: no em-dashes, sentences of 25 words at most.
- No comments in tests. Comments in code only for a non-obvious why, two lines at most.
- Local runs are scoped. The `pnpm-lock.yaml` change voids the TIA baseline, so pick test files by hand:
  `php artisan test --compact <file>`.

## Review Focus

1. An item with inline markup (`- [ ] Call **Bob**`) keeps the bold in its `<p>`. Test in Task 5.
2. `- [ ]` inside a fenced code block stays code, untouched. Test in Task 5.
3. Uppercase `[X]` from an agent counts as ticked. Test in Task 5.
4. A description with a checklist plus a paragraph and a table saves through the form with the other
   blocks unchanged. Test in Task 2.
5. The board badge adds no query per card. Test in Task 6.

---

### Task 1: Build the official task-list extension against Filament's TipTap

**Files:**
- Modify: `package.json` (dependency and two scripts), `pnpm-lock.yaml`
- Create: `resources/js/filament/rich-content-plugins/checklist.js` (source entry)
- Create: `bin/build-rich-editor-plugins.mjs`
- Create (generated, committed): `resources/js/filament/rich-content-plugins/dist/checklist.js`

**Interfaces:**
- Produces: `resources/js/filament/rich-content-plugins/dist/checklist.js`, an ES module whose default
  export is one TipTap `Extension` named `checklist` whose `addExtensions()` returns `TaskList` and
  `TaskItem.configure({ nested: false })`. Task 2 registers this path with `FilamentAsset`.
- Produces: `pnpm run rich-editor:build` and `pnpm run rich-editor:check`.

- [ ] **Step 1:** `pnpm add @tiptap/extension-list@^3.32.2`. Confirm `package.json` lists it under
  `dependencies` beside the other `@tiptap` packages.
- [ ] **Step 2:** Write `checklist.js`: import `Extension` from `@tiptap/core` and `TaskList`, `TaskItem`
  from `@tiptap/extension-list`, default-export the `checklist` extension above.
- [ ] **Step 3:** Write `bin/build-rich-editor-plugins.mjs`, shaped like `bin/build-brand-assets.mjs`.
  It calls Vite's `build()` with `configFile: false`, library mode, format `es`, minified, entry
  `checklist.js`, output `dist/checklist.js`. A plugin resolves `@tiptap/core`, `@tiptap/pm/state`,
  `@tiptap/pm/model` and `@tiptap/pm/view` to virtual modules. Each virtual module re-exports every key
  of the real module, read in Node with `Object.keys(await import(specifier))`, as
  `export const X = window.FilamentRichEditor.tiptap.<core|pmState|pmModel|pmView>.X`. With `--check`, it
  builds into a temp dir and exits 1 when the output differs from the committed file.
- [ ] **Step 4:** In the same script, fail the build when the output exceeds 40 KB or contains
  `prosemirror` (case-insensitive). Either means a second TipTap copy got bundled.
- [ ] **Step 5:** Add `"rich-editor:build": "node bin/build-rich-editor-plugins.mjs"` and
  `"rich-editor:check": "node bin/build-rich-editor-plugins.mjs --check"` to `scripts`.
- [ ] **Step 6:** Run `pnpm run rich-editor:build`, then `pnpm run rich-editor:check`.
  Expected: the build writes `dist/checklist.js`, and the check exits 0.
- [ ] **Step 7:** Run `grep -c "window.FilamentRichEditor.tiptap" resources/js/filament/rich-content-plugins/dist/checklist.js`.
  Expected: at least 1. Run `grep -ci prosemirror` on the same file. Expected: 0.
- [ ] **Step 8:** If `.github/workflows/ci.yml` has a job that already runs `pnpm install`, add a
  `pnpm run rich-editor:check` step to it. Otherwise leave CI alone and say so in the PR.
- [ ] **Step 9:** Commit: `feat(tasks): bundle tiptap's task list against filament's editor`.

### Task 2: Checklist plugin on the task description editor

**Files:**
- Create: `app/Filament/RichEditor/ChecklistPlugin.php`
- Create: `app/Filament/RichEditor/TaskItem.php`
- Modify: `app/Models/CustomField.php` (add `holdsChecklists()`)
- Modify: `app/Filament/CustomFields/RichEditorFieldType.php:37-69`
- Modify: `app/Filament/RichEditor/SlashMenuPlugin.php:22-66`
- Modify: `app/Providers/AppServiceProvider.php:851-862`
- Modify: `lang/en/filament/rich-editor.php` (`slash_menu.items.taskList`)
- Modify: `public/js/app/` (published by `php artisan filament:assets`)
- Test: `tests/Feature/Filament/App/Resources/TaskResourceTest.php`, `tests/Feature/Filament/App/Resources/NoteResourceTest.php`

**Interfaces:**
- Consumes: Task 1's `dist/checklist.js`.
- Produces: `App\Models\CustomField::holdsChecklists(): bool`, true only for `entity_type === CrmEntity::Task->value`
  and `code === TaskField::DESCRIPTION->value`. Task 5 uses it.
- Produces: `App\Filament\RichEditor\ChecklistPlugin implements RichContentPlugin` with `make(): self`.
- Produces: `App\Filament\RichEditor\TaskItem extends Tiptap\Nodes\TaskItem`. Its `checked` attribute
  parses `data-checked === 'true'` and renders `data-checked` as the string `"true"` or `"false"`.

- [ ] **Step 1: Write the failing tests** in `TaskResourceTest.php`:
  - `it('keeps a checklist and its ticks when a task is saved from the edit form')`: store
    `<ul data-type="taskList">` with a ticked `Call Bob` and an unticked `Send quote`, plus
    `<p>Notes</p>` and a two-cell `<table>` after it. Call the edit action with only a new title. Assert the
    stored description contains `data-checked="true"` once, `data-checked="false"` once, `Call Bob`,
    `<p>Notes</p>` and `<table`.
  - `it('shows a viewer the checklist with its ticks')`: as a `WorkspaceRole::Viewer`, mount the view
    action and assert the description state contains `data-checked="true"` and `Send quote`.
  - `it('offers the checklist in the task description slash menu')`: decode the `data-slash-menu`
    attribute (base64 JSON) of the description editor and assert an item with `id` `taskList`,
    `shortcut` `[ ]`, and group label `Lists`.
- [ ] **Step 2:** In `NoteResourceTest.php`, add `it('keeps the checklist out of the note slash menu')`:
  mount create, decode the body editor's `data-slash-menu`, assert no `taskList` item.
- [ ] **Step 3:** Run `php artisan test --compact tests/Feature/Filament/App/Resources/TaskResourceTest.php --filter=checklist`.
  Expected: FAIL. The save test loses `data-checked="true"`, and the menu test finds no `taskList`.
- [ ] **Step 4:** Add `CustomField::holdsChecklists()`.
- [ ] **Step 5:** Write `TaskItem` (only `addAttributes()` overridden) and `ChecklistPlugin`:
  - `getTipTapPhpExtensions()`: `[new Tiptap\Nodes\TaskList, new TaskItem]`.
  - `getTipTapJsExtensions()`: `[FilamentAsset::getScriptSrc('rich-editor-checklist')]`.
  - `getEditorTools()`: `RichEditorTool::make('taskList')`, label
    `__('filament/rich-editor.slash_menu.items.taskList.label')`, jsHandler
    `$getEditor()?.chain().focus().toggleTaskList().run()`, `->toggle()`, icon
    `Heroicon::OutlinedCheckCircle`.
  - `getEditorActions()`: `[]`.
- [ ] **Step 6:** In `RichEditorFieldType::configure()`, add `ChecklistPlugin::make()` to `plugins()`
  when `$customField instanceof App\Models\CustomField && $customField->holdsChecklists()`.
- [ ] **Step 7:** In `SlashMenuPlugin::ITEMS`, add
  `'taskList' => ['group' => 'lists', 'shortcut' => '[ ]', 'optional' => true]` after `orderedList`.
  Widen the shape's docblock to `optional?: bool`. In `attributes()`, skip an optional item whose tool is
  absent. A missing required tool still throws `LogicException`.
- [ ] **Step 8:** Add lang keys: label `Checklist`, description `Track steps with checkboxes`.
- [ ] **Step 9:** In `registerPanelScripts()`, register
  `Js::make('rich-editor-checklist', resource_path('js/filament/rich-content-plugins/dist/checklist.js'))->loadedOnRequest()`.
  Make `appVersion()` take the newest mtime of `public/js/app/rich-editor-slash-menu.js` and
  `public/js/app/rich-editor-checklist.js`.
- [ ] **Step 10:** Run `php artisan filament:assets`. Expected: `public/js/app/rich-editor-checklist.js`
  exists and matches `dist/checklist.js`.
- [ ] **Step 11:** Re-run the Step 3 command, then the note test.
  Expected: PASS, and the rest of both files still pass.
- [ ] **Step 12:** Commit: `feat(tasks): add a checklist to the task description editor`.

### Task 3: Checklist styling and browser walk

**Files:**
- Modify: `resources/css/filament/app/theme.css` (after the `.fi-fo-rich-editor-seamless` block, line 1856)
- Test: `tests/Browser/CRM/RichEditorBrowserTest.php`

**Interfaces:**
- Consumes: Task 2's editor, the `taskList` slash item, and the official `[ ] ` input rule.

- [ ] **Step 1: Write the failing test** `it('builds, ticks and saves a checklist in a task description')`:
  log in, open the tasks list, press `New task`, type a title, then type `/checklist` and choose it.
  Type `Call Bob`, Enter, `Send quote`. On a new line, type `[ ] Book room` and assert the editor has 3
  `li[data-type="taskItem"]`. Click the first item's checkbox, press `Create`. Assert the stored
  description has `data-checked="true"` once and `data-checked="false"` twice. Reopen the task and
  assert the first checkbox is checked. Finish with `assertNoJavaScriptErrors()`.
- [ ] **Step 2:** Run `php artisan test --compact tests/Browser/CRM/RichEditorBrowserTest.php --filter=checklist`.
  Expected: PASS for behaviour, since Tasks 1 and 2 shipped it. If it fails, fix the cause before styling.
- [ ] **Step 3:** Style it in `theme.css`, scoped to `.fi-fo-rich-editor .ProseMirror`:
  `ul[data-type="taskList"]` has no bullets and no indent. Each `li[data-type="taskItem"]` lays the
  checkbox and the text in one row, aligned to the first line. Ticked items (`[data-checked="true"] > div`)
  use the muted text token. The checkbox uses the panel's primary colour. Use tokens from
  `resources/css/theme.css`, not raw colours. Run `pnpm run build`.
- [ ] **Step 4:** Screenshot the edit modal with a ticked and an unticked item through agent-browser, in
  light and dark mode, plus the disabled view a viewer sees. Save them under `.context/`.
- [ ] **Step 5:** Run the browser file under WebKit:
  `php artisan test --compact tests/Browser/CRM/RichEditorBrowserTest.php --filter=checklist --browser safari`.
  Expected: PASS.
- [ ] **Step 6:** Commit: `feat(tasks): style checklists in the task editor`.

### Task 4: Tick markers in plain text

**Files:**
- Modify: `app/Support/PlainText.php`
- Test: `tests/Feature/Workspaces/WorkspaceActivityLogTest.php`, `tests/Feature/Chat/CustomFieldsBridge/DisplayFormatterTest.php`

**Interfaces:**
- Produces: `fromHtml()`, `linesFromHtml()` and `paragraphsFromHtml()` prefix each
  `li[data-type="taskItem"]` with `[x] ` when `data-checked` is `"true"`, else `[ ] `. Attribute order
  on the `<li>` does not matter. HTML without task items returns exactly what it returns today.

- [ ] **Step 1: Write the failing tests:**
  - In `WorkspaceActivityLogTest.php`, `test('a ticked checklist item shows in the activity log')`:
    a `custom_field_changes` activity on a task whose old and new `description` differ only in one
    item's `data-checked`. Assert the page shows `[ ] Call Bob` and `[x] Call Bob`.
  - In `DisplayFormatterTest.php`, `it('shows a ticked checklist item on the proposal card')`: old
    value unticked, new value ticked. Assert `old` is `[ ] Call Bob` and `new` is `[x] Call Bob`.
- [ ] **Step 2:** Run both files with `--filter=checklist`. Expected: FAIL, the text has no markers.
- [ ] **Step 3:** Add `private static function markTaskItems(string $html): string` and call it first
  in the three public methods. Use `preg_replace_callback` on the `<li ...>` open tag, so the regex paths
  and the DOM path both see the marker as text.
- [ ] **Step 4:** Re-run both files in full. Expected: PASS, including the existing rich-text tests.
- [ ] **Step 5:** Commit: `feat(tasks): show checklist ticks in activity and proposal text`.

### Task 5: Markdown checklists from agents

**Files:**
- Create: `app/Support/ChecklistHtml.php`
- Modify: `app/Support/CustomFields/CustomFieldInput.php:160-169`
- Modify: `app/Enums/CustomFields/TaskField.php` (constant `CHECKLIST_INPUT_FORMAT`)
- Modify: `app/Mcp/Schema/CustomFieldSchema.php:~104`, `packages/Chat/src/Services/Tools/CustomFieldsSchemaDescriber.php:89`
- Test: `tests/Feature/Api/V1/CustomFieldWritesApiTest.php`, `tests/Feature/Mcp/CustomFieldWritesTest.php`,
  `tests/Feature/Chat/CustomFieldsBridge/SchemaDescriberTest.php`, `tests/Feature/Mcp/SchemaResourcesTest.php`

**Interfaces:**
- Consumes: `CustomField::holdsChecklists()` (Task 2).
- Produces: `App\Support\ChecklistHtml::fromMarkdownHtml(string $html): string`. Task 6 adds `progress()`.
- Produces: `TaskField::CHECKLIST_INPUT_FORMAT`:
  `'markdown items "- [ ]" and "- [x]" become a checklist; read back as li[data-type="taskItem"] with data-checked="true" or "false"'`.

- [ ] **Step 1: Write the failing tests:**
  - API: `it('stores markdown checklist items in a task description as a checklist')`: `POST /api/v1/tasks`
    with `"- [ ] Call **Bob**\n- [x] Send quote\n- [X] Book room\n  - [ ] Pick a date\n- Agenda"`. Assert
    the stored value has one `data-type="taskList"`, five `data-type="taskItem"`, `data-checked="true"`
    twice, `<strong>Bob</strong>`, `Pick a date` and `Agenda`, and no literal `[ ]` or `[x]`.
  - API: `it('keeps markdown checklist syntax in a fenced code block as code')`: a description of
    ```` ```\n- [ ] not a task\n``` ````. Assert `<code>` holds `- [ ] not a task` and there is no `taskList`.
  - API: `it('keeps markdown checklist items in a note as bullets')`: `POST /api/v1/notes` with body
    `- [ ] Call Bob`. Assert no `taskList` and the text `[ ] Call Bob` kept.
  - MCP: `it('stores markdown checklist items through the create task tool')`: `CreateTaskTool` with
    `- [ ] Call Bob`. Assert one `data-type="taskItem"` with `data-checked="false"`.
  - Rela: in `SchemaDescriberTest.php`, assert the task description line contains
    `TaskField::CHECKLIST_INPUT_FORMAT` and the note body line does not.
  - MCP schema: in `SchemaResourcesTest.php`, assert the task schema's `description` entry carries the
    hint in its `input_format`, and the note `body` entry does not.
- [ ] **Step 2:** Run the four files with `--filter=checklist`. Expected: FAIL.
- [ ] **Step 3:** Write `ChecklistHtml::fromMarkdownHtml()` with `Dom\HTMLDocument`, as
  `RichContentAttachments` does. A `ul` becomes a task list when any direct `li` starts with `[ ]`, `[x]`
  or `[X]` followed by a space. Every direct `li` becomes a task item: strip the marker, set
  `data-checked`, and wrap its inline content in the stored markup from Global Constraints. Lift a nested
  `ul`'s items into the parent list as flat items, in order. Never touch `ul` inside `pre` or `code`.
- [ ] **Step 4:** In `CustomFieldInput::richText()`, apply `fromMarkdownHtml()` to the markdown output
  when `$field->holdsChecklists()`, before `canonicalize()`. HTML input passes through as today.
- [ ] **Step 5:** Add `TaskField::CHECKLIST_INPUT_FORMAT`. In both describers, append it after
  `inputFormat()` when `$field->holdsChecklists()`. In `CustomFieldSchema`, join it into `input_format`
  with `'; '`.
- [ ] **Step 6:** Re-run the four files in full, plus `tests/Feature/CRM/SurfaceParityTest.php`.
  Expected: PASS.
- [ ] **Step 7:** Commit: `feat(tasks): turn markdown checklist items into a task checklist`.

### Task 6: Progress badge on the board card

**Files:**
- Modify: `app/Support/ChecklistHtml.php` (add `progress()`)
- Modify: `app/Filament/Resources/TaskResource/Pages/TasksBoard.php:91-126`
- Test: `tests/Feature/Filament/App/Pages/TasksBoardTest.php`

**Interfaces:**
- Produces: `ChecklistHtml::progress(string $html): ?array` returning
  `array{checked: int, total: int}`, or `null` when the HTML has no task item. It counts
  `li[data-type="taskItem"]` and those with `data-checked="true"`.

- [ ] **Step 1: Write the failing tests** in `TasksBoardTest.php`:
  - `it('shows checklist progress on a task card')`: a task whose description has two task items, one
    ticked. Assert the board shows `1/2`.
  - `it('shows no checklist badge on a card without a checklist')`: a task with `<p>Plain</p>`. Assert
    no `data-checklist-progress` element renders for it.
  - `it('reads checklist progress without a query per card')`: render the board with 1 task and with 5
    tasks, each holding a checklist. Assert both renders run the same number of queries.
- [ ] **Step 2:** Run `php artisan test --compact tests/Feature/Filament/App/Pages/TasksBoardTest.php --filter=checklist`.
  Expected: FAIL.
- [ ] **Step 3:** Add `ChecklistHtml::progress()`.
- [ ] **Step 4:** Add a `TextEntry` badge to the card's `CardFlex`, before the priority badge. State
  from the raw description value via `progress()`, formatted `{checked}/{total}`. Visible only when
  `progress()` is not null. Icon `heroicon-o-check-circle`, colour `gray`, `grow(false)`, hidden label,
  `extraAttributes(['data-checklist-progress' => ''])`.
  Read the value from the custom field values the board already loads. If they are not loaded, add them to
  the board query's `with()` rather than querying per card.
- [ ] **Step 5:** Re-run the board file in full. Expected: PASS.
- [ ] **Step 6:** Screenshot the board with a checklist card in light and dark mode under `.context/`.
- [ ] **Step 7:** Commit: `feat(tasks): show checklist progress on board cards`.

### Task 7: Gates and pull request

- [ ] **Step 1:** `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`,
  `vendor/bin/phpstan analyse`, `composer test:lint`, `composer test:arch`. Expected: all clean. Remove
  nothing from `phpstan.neon`, and add no ignore.
- [ ] **Step 2:** `pnpm run rich-editor:check`. Expected: exit 0.
- [ ] **Step 3:** Push `feat/task-checklists`, open the PR against `main` with a full body per the
  user's PR rules, and watch the `Tests` workflow as a background task.
