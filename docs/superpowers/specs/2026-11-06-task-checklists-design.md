# Task checklists: tickable items in a task's description

**Date:** 2026-11-06
**Status:** Design, pending founder approval

## Summary

A task's description can hold a checklist. In the task editor, `/checklist` or typing `[ ] ` at the
start of a line starts one, and each item has a box the user ticks. The form's Save stores the ticked
state. The board card shows a "2/5" progress badge. Agents write `- [ ]` and `- [x]` markdown through
the API, the MCP server and Rela, and it lands as a real checklist. Read-only views draw the boxes.

The editor is TipTap's official task list (`@tiptap/extension-list`), loaded into Filament's own
TipTap instance. Filament ships no task list, up to and including v5.11.1, the latest release.

## Decisions (founder, 2026-11-06)

| # | Question | Choice |
|---|---|---|
| 1 | Where items are ticked | In the task edit modal only, saved with the form's Save. No autosave, no ticking on the board |
| 2 | Which editors get checklists | The task description field only (`TaskField::DESCRIPTION`). Notes and custom rich-text fields do not |
| 3 | How a checklist starts | Slash menu item plus the typed `[ ] ` shortcut. `Cmd/Ctrl+Shift+9` comes with TipTap and stays |
| 4 | How the official extension loads | Add `@tiptap/extension-list`. Build it as an ES module whose `@tiptap/*` imports resolve to `window.FilamentRichEditor.tiptap`, and commit the output under `public/js/app/` |
| 5 | Board card | A "checked/total" progress badge beside priority and due date. The stripped description text stays as it is |
| 6 | Agents | `- [ ]` and `- [x]` markdown becomes a real checklist in the task description. Reads stay HTML |
| 7 | Read-only rendering | Boxes drawn in CSS from `data-checked`, ticked items muted. The sanitizer allows that one attribute |
| 8 | Ticks in plain text | `App\Support\PlainText` writes `[x] ` or `[ ] ` before each task item's text. The activity log, Rela's approval card, notifications and email snippets inherit it |
| 9 | Existing descriptions | Count first, then decide. The count was near zero, so no backfill: old rows keep literal `[ ]` bullets |

## Existing data

A read-only production count on 2026-11-06 found 15 of 15,550 task descriptions holding list items
that start with `[ ]` or `[x]`, in 2 workspaces, 11 of them on live tasks. None holds
`data-type="taskList"` markup yet. That is too few rows to justify a migration and its production
rehearsal, so this work ships no backfill. A user who reopens one of those tasks can retype the items.

## Verified findings

Each was run in this checkout on 2026-11-06.

1. **The official JS nodes import only what Filament exposes.** `@tiptap/extension-list@3.32.2`
   `task-item.ts` and `task-list.ts` import `Node`, `mergeAttributes`, `wrappingInputRule`,
   `getRenderedAttributes`, `renderNestedMarkdownContent` and `parseIndentedBlocks` from `@tiptap/core`.
   Its helpers import from `@tiptap/pm/state` and `@tiptap/pm/model`. Filament sets
   `window.FilamentRichEditor.tiptap = { core, pmState, pmView, pmModel }`, and its bundle contains every
   one of those core names. Filament 5.9 builds against `@tiptap/*` `^3.30.5`.
2. **A plugin script must not import TipTap.** A second ProseMirror copy breaks its `instanceof` checks
   (`resources/js/filament/rich-content-plugins/slash-menu.js:1`).
3. **Filament's loading contract.** `rich-editor/extensions.js` calls `(await import(url)).default` per
   URL from `getTipTapJsExtensions()`, then calls it if it is a function. So the bundle is an ES module
   with one default export.
4. **Plugin PHP nodes win over `BulletList`.** `RichContentRenderer::getTipTapPhpExtensions()` lists
   plugin extensions first. With `TaskList` and `TaskItem` registered, `ul[data-type="taskList"]` parses
   as `taskList` and a plain `<ul>` still parses as `bulletList`.
5. **The official PHP `TaskItem` loses the ticked state.** `ueberdosis/tiptap-php` 2.2.1 (the latest
   release) declares `checked` with no `parseHTML`, so every item parses as unticked and renders
   `data-checked="false"`. Filament's state cast parses stored HTML through the PHP editor on every form
   open, so the official node would untick the whole list on the next Save. A subclass that reads
   `data-checked === 'true'` and renders `"true"`/`"false"` round-trips and is stable on a second pass.
6. **Without the PHP nodes, a checklist renders as bullets.** `RichContentRenderer` with no plugins
   turns the markup into `<ul><li>`.
7. **The sanitizer strips the state.** `toHtml()` runs `Str::sanitizeHtml()`. Filament's
   `HtmlSanitizerConfig` (`vendor/filament/support/src/SupportServiceProvider.php:114`) allows
   `data-type` but drops `data-checked` and `<input>`.
8. **Attachment rewriting keeps the markup.** `RichContentAttachments::canonicalize()` and
   `rewriteAttachmentUrls()` edit a DOM and keep unknown attributes.
9. **Agent markdown today.** `CustomFieldInput::richText()` (`app/Support/CustomFields/CustomFieldInput.php:160`)
   converts non-HTML input with Spatie's `MarkdownRenderer` (CommonMark core, tables, heading permalinks).
   `- [ ] Call Bob` saves as `<li>[ ] Call Bob</li>`.

## Design

### Editor (task description only)

- A new rich-content plugin, `App\Filament\RichEditor\ChecklistPlugin`, beside `SlashMenuPlugin`:
  - `getTipTapJsExtensions()`: the built checklist bundle's script URL.
  - `getTipTapPhpExtensions()`: `Tiptap\Nodes\TaskList` and an app `TaskItem` that extends
    `Tiptap\Nodes\TaskItem` and only fixes the `checked` attribute (finding 5).
  - `getEditorTools()`: a `taskList` `RichEditorTool` whose JS handler toggles a task list.
- `RichEditorFieldType` adds `ChecklistPlugin` only when the field is the task description. It already
  identifies that field in `DOCUMENT_FIELDS`, so the builder reuses that lookup.
- The slash menu gains a `taskList` entry in the `lists` group with the `[ ]` shortcut hint.
  `SlashMenuPlugin::attributes()` throws for an item with no tool, and the tool exists only on the task
  editor. The menu must skip items whose tool is absent, or take the list from the field. Labels go in
  `lang/en/filament/rich-editor.php` under `slash_menu.items.taskList`.
- `nested` stays `false`, TipTap's default. The PHP `TaskItem` content must accept what the JS node emits.
- Ticking a box in the editor changes form state only. The form's Save persists it.

### The JS bundle

- Add `@tiptap/extension-list` to `dependencies` at `^3.32.2`, the line the other `@tiptap` packages use.
- Source: a small module in `resources/js/filament/rich-content-plugins/` whose default export is one
  TipTap `Extension` with `addExtensions()` returning `TaskList` and `TaskItem` (finding 3).
- A build script resolves `@tiptap/core`, `@tiptap/pm/state`, `@tiptap/pm/model` and `@tiptap/pm/view`
  to the four `window.FilamentRichEditor.tiptap` objects, so the output carries no TipTap or ProseMirror
  code of its own. It writes an ES module that `FilamentAsset` registers and `filament:assets` publishes
  to `public/js/app/`. That copy is committed, like `rich-editor-slash-menu.js`.
- `FilamentAsset::appVersion()` keys on the slash menu file's mtime today (`AppServiceProvider.php:851`).
  It must also change when the checklist bundle changes.
- The output must contain no ProseMirror class definitions. The build or a check fails if it does.

### Server rendering and the sanitizer

- `RichContentEntry` registers the task-list PHP nodes on its renderer for every rich-text field. They
  match only `data-type="taskList"` markup, so other fields render as before. This keeps one renderer
  and keeps decision 2 about editing, not reading.
- `AppServiceProvider` extends Filament's `HtmlSanitizerConfig` binding to allow `data-checked` on `li`.
  Nothing else widens. `<input>` stays stripped.
- CSS draws the box from `li[data-type="taskItem"][data-checked]` in read-only prose and styles the live
  checkbox in the editor. Filament ships no task-item styles. Styles go in core `theme.css` so Cloud's
  panel themes inherit them.

### Board card

- A "checked/total" badge in the card's `CardFlex`, visible only when the description holds at least one
  task item. It counts `li[data-type="taskItem"]` and those with `data-checked="true"` in the raw stored
  value, never the rendered entry, which has already lost the attribute.
- The counter is a pure HTML parser with no business rule, so it may live in `Support`.

### Plain text (decision 8)

- `PlainText::fromHtml()`, `linesFromHtml()` and `paragraphsFromHtml()` prefix each
  `li[data-type="taskItem"]` with `[x] ` when `data-checked="true"` and `[ ] ` otherwise. Text without
  task items comes out exactly as before.
- Its 8 callers inherit the markers. A Save that only ticks a box now differs in plain text, so the
  activity log records it (`ActivityValue.php:30` and the unchanged-row filter) and Rela's approval card
  (`CustomFieldsDisplayFormatter.php:231`) shows the before and after.

### Agents (API, MCP, Rela)

- In `CustomFieldInput::richText()`, markdown for the task description field converts `- [ ]` and
  `- [x]` items into TipTap's markup: `ul[data-type="taskList"]` and `li[data-type="taskItem"]` with
  `data-checked`, the label and checkbox, and the item text wrapped in `<div><p>`. Other fields keep
  today's conversion, so `- [ ]` in a note still saves as a bullet.
- The agent-facing hint says that `- [ ]` and `- [x]` make a checklist and that reads return HTML with
  `data-checked`. Two classes describe fields to agents: `App\Mcp\Schema\CustomFieldSchema` for the MCP
  server and `packages/Chat/src/Services/Tools/CustomFieldsSchemaDescriber.php` for Rela. Both take
  their wording from `CustomFieldType::inputFormat()`, which covers every rich-text field and so cannot
  carry the hint. *Builder's call, set during review:* a per-field method on `TaskField` owns the hint,
  and both describers append it.
- Ticking from an agent means rewriting the description, as any edit does today.
- *Builder's call, set during review:* nested markdown items (`  - [ ] sub`) become flat task items with
  their text kept, because `nested` is `false` and the editor would otherwise delete them on Save. A
  list that mixes `- [ ]` and plain `- ` items stays one task list, with the plain items unticked.
- *Builder's call, set during review:* checklist HTML sent to a note or a custom rich-text field is
  accepted as today's unsupported markup is. That editor has no task nodes, so a later Save turns it into
  a bullet list and keeps the text. No validator change.

## Tests

Entry points only, per `.ai/rules` testing rules. Extend the existing files that cover these surfaces.

- **Edit form round trip:** a task whose description holds a ticked and an unticked item opens in the
  edit form and saves unchanged. This guards finding 5 and is the most important test.
- **API:** `POST` a task with `- [ ] Call Bob\n- [x] Send quote` as the description. The stored HTML has
  two task items, one with `data-checked="true"`. The same markdown on a note stays a bullet list.
- **MCP and chat:** the same markdown through their create tools, if `SurfaceParityTest` or the existing
  tool tests do not already cover rich-text conversion for them.
- **Board:** a card shows "1/2" for that task and no badge for a task without a checklist.
- **Read-only view:** the rendered entry keeps `data-checked` and drops `<input>`.
- **Activity log:** a Save that only ticks one item records an activity whose old and new text differ
  by `[ ]` and `[x]`.
- **Rela approval card:** a proposal that ticks an item shows `[ ] Call Bob` before and `[x] Call Bob`
  after.
- **Browser (`tests/Browser/`):** `/checklist`, then `[ ] `, tick an item, Save, reopen: the tick holds.
  Run it under `--browser safari` too, because the drawn boxes are pseudo-elements.
- **Slash menu:** a note's editor has no checklist entry and does not throw.

## Non-goals

- Ticking outside the editor: board cards, the read-only view, or an autosave on tick.
- Checklists in notes or custom rich-text fields.
- A structured checklist table, per-item assignees or due dates.
- Returning markdown to agents, or a dedicated "tick item N" tool.
- Nested checklist items.
- Converting existing `[ ]` bullets (decision 9). No migration, no config key, no env var.

## Left to the builder

- The build tool: a Vite config, Rolldown or esbuild, and how the four `@tiptap` specifiers map to the
  globals. Mirror `bin/build-brand-assets.mjs` and its `--check` mode. Whether CI runs that check is the
  builder's call, stated in the PR.
- Where the badge sits in the card, its icon, and its colour, within the existing badge style.
- The CSS for the drawn box, checked in light and dark mode, Chromium and WebKit.
- Whether the slash menu skips missing tools or reads its item list from the field.
- An upstream fix for finding 5 in `ueberdosis/tiptap-php` is welcome but does not block this work.
