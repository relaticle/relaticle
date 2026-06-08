# Tour Catalogue

8 Whittaker-style exploration tours, mapped to Relaticle workflows. Tours are *what to do*; oracles are *whether what happened is wrong*.

## Tour selection

The skill picks tours by surface. Always-include rules trump risk tier:

| Surface in matrix | Tours added |
|---|---|
| Filament UI | Garbage Collector, Landmark, Supermodel |
| Livewire | Interrupt, Supermodel |
| REST API | Data Flow, Stress |
| MCP server | Data Flow |
| Migration in diff | Stress (record-count boundary) |
| Auth/policy in diff | Sensitive Data (always-include) |
| Custom-fields-bearing model in diff | Sensitive Data (always-include) |
| deep tier (any diff) | Back Alley (always-include at deep tier) |

Risk tier caps the *count* of tours run per surface (smoke: 1, light: 2, medium: 3, deep: all relevant).

---

## Garbage Collector

Visit every menu, dropdown, error message, and empty state on the changed surface, **once**. Don't pursue depth — touch breadth.

**Relaticle steps:**
1. Open the changed Filament resource list view.
2. Click every header column to sort.
3. Open the filter panel; toggle each filter once.
4. Open the action menu; click each action (cancel modals immediately).
5. Trigger the empty state (filter to nothing) — does it render correctly?
6. Trigger an error state (e.g., visit `/<resource>/0` for a non-existent ID).

**Bug class hunted:** Forgotten error handling, broken filters, missing empty-state copy.

---

## Landmark

Companies → People → Opportunities → Tasks → Notes in random order. Catches state-leak between resources.

**Relaticle steps:**
1. Open Companies list. Note the URL.
2. Click into a Company detail. Note URL.
3. Click "Tasks" in the sidebar. Note URL.
4. Click into a Task. Click "Back to Companies" or use breadcrumb.
5. Confirm Companies list state (filters, page) is preserved or sensibly reset.

**Bug class hunted:** Stale URL state, breadcrumb bugs, sidebar state desync, search-context leakage.

---

## Data Flow (FedEx)

Follow data through every front door. Same data must round-trip identical.

**Relaticle steps:**
1. Create a record via Filament UI (capture all custom-field values).
2. `GET /v1/<resource>/<id>` via REST — assert payload matches.
3. Call `Show<Resource>Tool` via MCP — assert payload matches.
4. Export to CSV (if the resource supports it) — assert columns and values.
5. (Optional) Re-import the CSV — assert no duplication, no truncation, no encoding loss.

**Bug class hunted:** API/UI/MCP shape drift; encoding loss (Unicode/RTL); validation drift across surfaces.

---

## Sensitive Data (Money Tour, repurposed)

Touch every place that handles PII or encrypted custom fields. Verify encryption + tenant scoping at each touchpoint.

**Relaticle steps:**
1. List every encrypted-flagged custom field on the affected models (`SELECT * FROM custom_fields WHERE encrypted = true`).
2. For each, verify: stored as ciphertext (DB query), decrypted only for owner, returned as plaintext in API only for owner.
3. Cross-tenant probe each: as Team A, attempt to read Team B's encrypted field via API/MCP — expected: 403 or zero data.
4. PII fields (email, phone): same probes.

**Bug class hunted:** Encryption bypass, plaintext leak in logs, cross-tenant PII leak.

---

## Interrupt (Rained-Out Tour)

Start an action, abandon midway. Various interruption modes.

**Relaticle steps:**
1. Open a multi-step form. Start filling. Hit browser back. Return — is the form draft preserved or cleanly cleared?
2. During a save request, refresh the page. After refresh: did the save complete? Is the UI consistent with DB state?
3. Drag-and-drop a Task on the Tasks board. Release outside the drop zone. Where does the Task end up?
4. Double-click Submit on a form. How many records are created (expected: 1)?

**Bug class hunted:** Idempotency, partial state, transaction integrity, drag-drop edge cases.

---

## Back Alley

Least-used features. Where bugs hide because nobody looks.

**Relaticle steps:**
1. Custom field admin (create/edit/delete a custom field on the affected model).
2. API tokens page (create, revoke, regenerate).
3. Scribe API docs page (does it render after the diff?).
4. System administrator panel (if accessible — `/sysadmin/*` routes).
5. Profile / EditTeam pages.

**Bug class hunted:** Forgotten admin features, broken doc generation, sysadmin surface regressions.

---

## Stress (Intellectual Tour)

Push the limits.

**Relaticle steps:**
1. Max-length strings in every text input on the diff (use `references/data-nasties.md` payloads).
2. 10K records pagination: navigate to page 50, page 100, page 500. Time the response.
3. Deeply nested custom fields: a record with values for all 22 custom-field types simultaneously.
4. JSON / CustomFieldValueData bombs: payloads with deeply nested arrays, max-int values.

**Bug class hunted:** Memory blowups, query timeouts, JSON serialization failures, off-by-one pagination bugs.

---

## Supermodel

Visual / layout. Hybrid: deterministic probes (`references/visual-probes.md`) plus Claude reading screenshots and applying the Image-oracle rubric (`references/oracles.md`).

**Relaticle steps:**

For every page the diff plausibly touched:

1. **Viewport sweep** — set viewport to each of `320×568`, `375×812`, `768×1024`, `1024×768`, `1440×900`. At each: open the page, `agent-browser wait --load networkidle`, sleep 1s, run probes P1–P8 from `references/visual-probes.md`, take `agent-browser screenshot --annotate`. Skip-rules per viewport are in `visual-probes.md`'s viewport sweep table.
2. **State explosion** — at the developer's default viewport (1440×900), walk every applicable state in `visual-probes.md`'s state-explosion checklist: empty, one-row, many-rows (100+), loading (network throttled), error (`/0` or invalid input), disabled, hover, focus, dark mode. Screenshot each.
3. **Long-content stress** — Company name 200 chars, Note 5000 chars, custom-field text 10000 chars. Re-run P1 (overflow) and P2 (clipping) after each insertion.
4. **Image-oracle pass** — open the screenshots from steps 1–3, answer the Image-oracle Y/N rubric in `oracles.md`. Each "No" is a candidate finding.
5. **Aggregate** — merge probe offenders that fire at multiple viewports into one finding (record viewports as `viewports: [320, 375]`). Drop offenders that aren't in or downstream of the diff (move to "neutral notes").

**Bug class hunted:** Visual inconsistency, mobile/tablet layout breakage, content overflow, low contrast, missing focus rings, undersized tap targets, broken images, layout shift, AI-slop alignment, inconsistent typography.

**Always-include rule:** Supermodel is **mandatory** for any matrix that contains a Filament UI or Livewire surface, regardless of risk tier. The tier-cap on tour count (smoke=1, light=2, …) does not apply — Supermodel does not count against that cap. Reason: the diffs most likely to ship visual bugs (CSS, Blade, Tailwind config, view-component changes) score smoke or light on the risk rubric, and skipping the only visual tour at those tiers is exactly when bugs slip through.
