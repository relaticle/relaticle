# Checks Matrix — Relaticle CRM

Combines per-element checks and diff-to-scenario mapping into a single reference.

---

## Per-element checks

**This is a reference, not a checklist.** Read it during Stage 2 (Run) planning to consider checks that matter for each interactive element type in the diff. Apply selectively based on scope — don't blanket-run every entry.

For each element type, the table lists checks worth considering, how to verify, and what evidence type the check produces. Evidence types defined in `run.md` "Evidence types" section.

### Table of contents

- [CRM Record (Company / People / Opportunity / Task / Note)](#crm-record)
- [Custom Field](#custom-field)
- [Pipeline / Kanban](#pipeline--kanban)
- [Filament Table](#filament-table)
- [Filament Modal](#filament-modal)
- [Filament Action](#filament-action)
- [Toast Notification](#toast-notification)
- [Form Field](#form-field)
- [Multi-tenant (Team) Scope](#multi-tenant-team-scope)
- [Import Wizard](#import-wizard)
- [AI Chat](#ai-chat)
- [Sysadmin Panel](#sysadmin-panel)
- [Feature-flag-gated UI (Pennant)](#feature-flag-gated-ui-pennant)
- [REST API](#rest-api)

---

### CRM Record

Companies, People, Opportunities, Tasks, Notes. Resources live under `app/Filament/Resources/`.

When `change_types` includes `mutation`, `form`, or `table` on a resource path, consider:

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Create record | Open Create page, fill required fields, submit | Row persists in DB scoped to current team; redirect to view page | deterministic |
| Required-field block | Submit with `name` empty | Validation error visible, no DB write | deterministic |
| Edit record | Open record, change a field, save | DB row updated; `updated_at` advances | deterministic |
| Edit doesn't leak across teams | Switch tenant, try to access the original record's URL | 403/redirect; record not visible | deterministic |
| Delete record (and cascade) | Trigger Delete action, confirm | DB row gone; related records (notes, tasks) handled per migration design | deterministic |
| Soft-delete behavior | If model uses SoftDeletes, verify deleted record absent from default queries | `deleted_at` set; record hidden from index | deterministic |
| Bulk delete | Select N rows, trigger bulk delete | All N rows removed in single action | deterministic |
| Audit trail (activity log) | After any write, check `activity_log` table | New entry references the record + acting user | deterministic |
| Custom fields preserved | Edit record with custom fields populated | Custom field values round-trip on save | deterministic |
| AI summary regenerate | If model uses `AiSummary`, trigger regenerate | New summary row; old credit balance debited correctly | deterministic |

---

### Custom Field

`app/Models/CustomField.php`, `CustomFieldOption`, `CustomFieldSection`, `CustomFieldValue`. Models with the `UsesCustomFields` trait merge `custom_fields` into `$fillable` automatically — do NOT manually `saveCustomFields()` in actions.

When `change_types` includes `custom_fields`:

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Create field per type | Create a Text / Number / Date / Select / Multi-select / Boolean / Currency field | Field appears on target resource's edit form | a11y_ref |
| Value round-trip per type | Save record with each type populated; reload | Stored + displayed value matches input exactly (incl. emoji, special chars) | deterministic |
| Select option labels render | Save Select/Multi-select value | API + UI both render `{id, label}` shape, not raw ULID | deterministic |
| Required custom field | Mark a field required, attempt save without it | Validation blocks save; correct error message | deterministic |
| Section grouping | Group fields into sections | Edit form renders fields under correct section headers | snapshot_diff |
| Field reorder | Drag to reorder fields/sections | New order persists; reflected on next page load | snapshot_diff |
| Tenant isolation | Verify same field code in two teams is independent | Team A field changes do not leak to Team B | deterministic |
| API write attempts silently ignored | `POST /api/v1/companies` with `custom_fields: {...}` | Record created without custom_fields; no 5xx | deterministic |
| Delete field with values | Delete a field that has values in N records | Values cleaned up; no orphan `CustomFieldValue` rows | deterministic |

---

### Pipeline / Kanban

Opportunity stages, drag-and-drop pipeline boards.

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Stage column renders | Open pipeline view | Each configured stage appears as a column | snapshot_diff |
| Drag opportunity to next stage | Drag card from "Lead" to "Qualified" | Card moves; DB stage value updates; activity log entry created | deterministic |
| Optimistic UI rollback | Drag, then simulate server error (5xx) | Card returns to original column; toast surfaces error | snapshot_diff |
| Empty stage state | View pipeline with empty stage | Empty-state message visible, no broken column | snapshot_diff |
| Filter pipeline by team member | Apply owner filter | Cards reduce to matching; counts update per column | deterministic |
| Stage WIP limit | If limits configured, exceed one | Drag blocked / warning shown per spec | deterministic |

---

### Filament Table

Selectors: `.fi-ta`. Used across all CRM resource lists.

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Empty state | View table with zero rows | Empty-state message visible, no broken layout | snapshot_diff |
| Single row | View table with one row | Renders correctly, no plural-only copy | snapshot_diff |
| Many rows / pagination | View table > page-size | Pagination controls visible, total count correct | deterministic |
| Sort column | Click sortable header | Rows reorder, sort indicator updates | a11y_ref |
| Filter (Spatie QueryBuilder) | Apply filter via URL `?filter[name]=foo` | Rows reduce to matching; works in both UI + REST API | deterministic |
| Search | Type in search input | Rows filter in real-time | deterministic |
| Bulk action | Select multiple, trigger bulk action | Action applies to selected rows only | deterministic |
| Row action | Click row action button | Modal/action triggers for that row's data | deterministic |
| Mobile layout | View at 375x667 | Scrollable or stacked, no horizontal overflow | a11y_ref |
| Multi-tenant scope | View table as Team A user | Only Team A rows visible; Team B rows not present | deterministic |

---

### Filament Modal

Selectors: `.fi-modal`, `<x-filament::modal>`.

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Opens on trigger | Click trigger button | `.fi-modal` exists & visible in DOM | a11y_ref |
| X icon closes | Click `.fi-modal-close-action` | `.fi-modal` removed from DOM within 500ms | snapshot_diff |
| Escape closes | `keyboard.press('Escape')` | `.fi-modal` removed from DOM | snapshot_diff |
| Backdrop closes | Click `.fi-modal-window` outside `.fi-modal-content` | `.fi-modal` removed | snapshot_diff |
| Focus trapped | Tab cycles within modal | Active element stays within `.fi-modal` | a11y_ref |
| Restore focus on close | Close modal | Focus returns to original trigger | a11y_ref |
| State preserved (or cleared) on reopen | Open → fill → close → reopen | Per intent: persisted or empty | DOM read |
| Stacked modal sanity | Open modal A → open modal B → close B | Modal A still rendered, focus returns to it | a11y_ref |

Filament-specific gotcha: modal fade animation ~300ms — assert removal with `setTimeout(..., 500)` or `waitForElementHidden`. `wire:click="mountAction()"` may dispatch toast notifications; verify both modal close AND toast appearance.

---

### Filament Action

Selectors: `[wire\\:click^="mountAction"]`, `.fi-ac-`.

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Action opens correctly | Click action trigger | Modal/redirect/notification per action type | snapshot_diff |
| Action form filled & submitted | Fill nested form, submit | Action's body runs, side effect verified | deterministic |
| Action cancel | Click cancel inside action | No side effect, modal closes | DOM read |
| Action permission denied | Trigger as unauthorized user / different team | Action button not visible OR explicit denial message | deterministic |
| Action confirmation modal | If action has confirmation | Confirm/Cancel both wired up | a11y_ref |
| Action calls correct action class | If using `app/Actions/` per CLAUDE.md | Action class invoked; side effects (notifications, syncs) fire | deterministic |
| `->after()` hook fires | After native CRUD action | Hooked actions (notifications, etc.) execute | deterministic |

---

### Toast Notification

Selectors: `.fi-no-notification`, Livewire-emitted toasts.

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Appears on action | Trigger action | `.fi-no-notification` in DOM | snapshot_diff |
| Message text correct | Inspect `.fi-no-notification-title` | Matches expected text exactly | deterministic |
| Body text correct | Inspect `.fi-no-notification-body` | Matches expected | deterministic |
| Auto-dismisses | Wait 6 seconds | Removed from DOM | snapshot_diff |
| Manual dismiss | Click X inside toast | Removed from DOM | snapshot_diff |
| Stacking | Trigger 3 actions in 200ms | All 3 appear, oldest dismisses first | DOM count |

---

### Form Field

Filament `TextInput`, `Select`, `Textarea`, `Checkbox`, custom field renderers in CRM resources.

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Required-field error | Submit with required field empty | Error message visible, no success | deterministic |
| Format error | Enter invalid email/url/phone | Field-level error, submit blocked | deterministic |
| Server-side error | Submit data that passes client validation but fails server rules | Server-error message rendered, no DB write | deterministic |
| Error clears on fix | Fix invalid field | Error message disappears on input/blur | snapshot_diff |
| Field error highlighting | After validation error | Field has `aria-invalid="true"` or error class | a11y_ref |
| Long input (500+ chars) | Paste 500-char string | Input accepts OR truncates per schema cap | deterministic |
| Special characters | Paste `<>&"'\`` | Input accepts, no XSS on render | deterministic |
| Emoji | Paste 🎉 | Accepted, persists through submit | deterministic |
| Empty whitespace | Submit field with only spaces | Treated as empty per rules | deterministic |
| Double-submit safety | Click submit twice rapidly | Only one submission persists (DB row count = 1) | deterministic |
| Cancel button | Click Cancel | Form discards changes, returns to prior state | snapshot_diff |

---

### Multi-tenant (Team) Scope

Filament tenancy via `Team::class`, slug-based, ownership relationship `team`.

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Team switch persists | Switch tenant via menu | URL slug updates; subsequent records scoped to new team | deterministic |
| Cross-team data leak | As Team A user, GET URL of Team B's record | 403/redirect; record not visible | deterministic |
| Tenant context in tinker queries | Run query without tenant context | Throws or returns empty depending on scope; never leaks all teams | deterministic |
| Tenant menu items render | Open tenant menu | Custom fields + import history links visible | snapshot_diff |
| Invite team member | Send invite, accept via link | New `Membership` row; new user can access team's records | deterministic |
| Remove team member | Remove user from team | Membership gone; their tokens for that team revoked | deterministic |

---

### Import Wizard

`packages/ImportWizard/`. CSV → records pipeline; multi-step wizard.

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Upload CSV | Drop a small valid CSV | File accepted, preview renders first N rows | snapshot_diff |
| Column mapping | Map CSV columns to record fields | Mapping persists across wizard steps | deterministic |
| Multi-value input | Use multi-value component (e.g. tags) | x-teleport panel renders, wire:ignore prevents Livewire morph | snapshot_diff |
| Invalid CSV row | Include row with empty `name` | **Known bug** (project memory): empty-name rows currently accepted; flag for fix | deterministic |
| Type coercion | CSV column declared as Date with string value | Row rejected with clear error; not silently coerced to null | deterministic |
| Dry-run preview | Run dry-run | Preview shows what WOULD be created; no DB writes | deterministic |
| Commit import | Run commit | All valid rows inserted; invalid rows surfaced in error list | deterministic |
| Resume interrupted import | Close wizard mid-flow, reopen | State preserved OR wizard restarts cleanly per spec | deterministic |
| Import history | Open team menu → Import History | Past imports listed with row counts + status | snapshot_diff |
| Custom fields in import | CSV column maps to a custom field | Value round-trips into `CustomFieldValue` correctly per type | deterministic |

---

### AI Chat

`packages/Chat/`. AI chat with credit tracking.

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Send message | Type prompt, submit | Response streams in; credit debited from team balance | deterministic |
| Insufficient credits | Drop balance to 0, attempt | Submit blocked with upgrade prompt | deterministic |
| Credit transaction logged | After any message | New `AiCreditTransaction` row with correct `type` and idempotency key | deterministic |
| Tool calls execute | Prompt that triggers CRM tool (e.g. "create company X") | Tool runs in tenant context; record persists | deterministic |
| Conversation persists | Reload page mid-conversation | History reloads correctly | snapshot_diff |
| Schema describer accuracy | Ask "what fields does Company have" | Lists base + tenant's custom fields | deterministic |
| Cross-tenant isolation | Send identical prompt in Team A vs Team B | Each sees only its own records | deterministic |

---

### Sysadmin Panel

`packages/SystemAdmin/`. Internal admin panel at `/sysadmin`.

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Sysadmin login | Log in as `sysadmin@relaticle.com` | Lands in `/sysadmin` panel | deterministic |
| Non-sysadmin denied | Try `/sysadmin` as regular user | Redirect/403 | deterministic |
| Resource lists render | Visit each resource index | Records visible, no broken layout | snapshot_diff |

Per project memory: keep sysadmin tests minimal — basic render + record visibility only, no exhaustive column/sort/search coverage. It's internal, not user-facing.

---

### Feature-flag-gated UI (Pennant)

When `change_types` includes `feature_flag`:

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Off state | Set flag off, visit feature surface | Feature UI absent, fallback visible | snapshot_diff |
| On state | Set flag on, visit feature surface | Feature UI present, fully functional | a11y_ref |
| Mid-session toggle | Toggle off while user is in feature | Graceful handle per spec | DOM read |
| API check matches UI | Off-state UI vs server-side `Feature::active()` | Consistent | deterministic |

How to toggle for a team:

```bash
php artisan tinker --execute 'Feature::for(\App\Models\Team::find(1))->deactivate("FlagName");'
```

---

### REST API

Sanctum-authenticated, Spatie QueryBuilder integration. Per project memory: custom fields readable but **not writable** via API (silently ignored).

| Check | Action | Expected | Evidence type |
|---|---|---|---|
| Auth required | Hit endpoint without token | 401 | deterministic |
| Tenant context applied | Hit endpoint with token for Team A | Only Team A records returned | deterministic |
| Filter via QueryBuilder | `?filter[name]=foo` | Results reduce; non-allowed filters rejected | deterministic |
| Sort via QueryBuilder | `?sort=-created_at` | Sorted DESC; non-allowed sorts rejected | deterministic |
| Pagination | `?per_page=5&page=2` | Returns page 2; meta block correct | deterministic |
| Include relations | `?include=people` (when whitelisted) | Eager-loaded relation in response | deterministic |
| Custom fields readable | GET a record | `custom_fields` block present with `{id, label}` for select types | deterministic |
| Custom fields write silently ignored | POST with `custom_fields: {...}` | Record created without custom_fields; no error | deterministic |
| Validation errors | POST with missing required | 422 with field errors | deterministic |
| Scribe docs generate | Run `php artisan scribe:generate` | No errors; new endpoint documented (DB-free per project memory) | deterministic |

---

### Adding new entries

When a real review surfaces a missing check, the workflow is:

1. Add a one-line note to `gotchas.md` describing the symptom and how you caught it.
2. If the same gotcha appears in three independent reviews, promote it to a table row here.

---

## Change-type to suggested scenarios

After `classify_diff.py` produces a `change_types[]` list, use this table to surface scenarios worth considering. **Suggestions, not requirements.**

Cross-reference with the [Per-element checks](#per-element-checks) section above for the specific check tables.

### Mapping

| change_type | What it means | Suggested scenarios |
|---|---|---|
| `modal` | A Filament modal or custom `<x-filament::modal>` was touched | Modal close paths (X, Escape, backdrop) · focus trap · state preserved on reopen |
| `form` | Filament form schemas (`->schema(`, `TextInput::make`), form components, or any Filament Resource form() changed | Required-empty submit · long input · special chars · emoji · double-submit · cancel button · paste handling |
| `validation` | A `Rule`, `FormRequest@rules`, or validation array changed | Required-field error · format error · server-side error · error clears on fix · field highlight |
| `table` | `*Table.php`, `.fi-table-`, table components, or Filament Resource table() changed | Empty state · single row · pagination · sort · filter · search · bulk action · row action · mobile layout · multi-tenant scope |
| `feature_flag` | `config/pennant.php` or `Feature::define` / `Feature::active` / `@feature` changed | Off state · on state · mid-session toggle · API vs UI consistency |
| `mutation` | Controller `store/update/destroy` body changed, action class in `app/Actions/` changed, or model `creating/updating/deleting` hooks changed | Success state · server-error path · audit-log entry created · tenant scope honored |
| `blade` | Any `*.blade.php` touched | Mobile viewport (375×667) of touched surface · console-clean check on touched route |
| `livewire` | `*.php` Livewire components or `wire:` directives changed | `wire:loading` not stuck · validation feedback · component re-render after action |
| `custom_fields` | `app/Models/CustomField*`, `UsesCustomFields` trait, or custom-field renderers touched | Per-type round-trip · select option label shape · tenant isolation · API write-ignore behavior |
| `import_wizard` | Anything under `packages/ImportWizard/` | CSV upload · column mapping · invalid rows · dry-run vs commit · import history |
| `ai_chat` | Anything under `packages/Chat/` | Credit debit · tool call execution · cross-tenant isolation · conversation persistence |
| `sysadmin` | Anything under `packages/SystemAdmin/` | Sysadmin-only access · resource render (keep tests minimal) |
| `tenant` | `Team` / `Membership` / tenant middleware changed | Cross-team data leak · team switch persistence · invite/remove flows |
| `route` | `routes/*.php` touched | Route resolves with auth · route resolves without auth (where appropriate) · middleware stack applies |
| `api` | `routes/api.php` or `app/Http/Controllers/Api/` touched | Auth required · tenant scope · QueryBuilder filter/sort/paginate · validation errors · Scribe docs generate |

> **`infra_only` is NOT in `change_types[]`.** `classify_diff.py` emits it as a separate top-level boolean field on the JSON output. When `infra_only: true` AND `change_types: []`, treat the diff as backend-only and skip the browser — see below.

### When `infra_only: true` and `change_types: []`

Skip the browser. Run the relevant Pest test file(s) instead:

```bash
php artisan test --compact --filter=<test-name>
```

A successful Pest run with no new failures is the verdict signal. Still emit a REVIEW.md summarizing what was tested.

---

## How to use this matrix

1. Read `diff-classification.json` (output of `classify_diff.py`).
2. For each `change_type`, glance at the suggested scenarios above.
3. Cross-reference with the per-element checks for the specific tables.
4. Plan cases that cover what the AC require AND the highest-risk scenarios.
5. Prioritize ruthlessly — for a wide diff, pick the highest-bug-risk scenarios.
