# Persona Roster

8 personas. The risk tier determines how many run.

| Tier | Personas |
|---|---|
| smoke | Default User |
| light | Default + (Cross-Tenant Spy if model/policy/scope path touched, else Power User) |
| medium | Default + Cross-Tenant Spy + Rushed Salesperson + (one of First-Time User / Destructive User / Inattentive Returner — pick whichever stresses the changed surface) |
| deep | All 8 |

Each persona maps to a specific bug class. Each charter must justify which personas it picked and why others were excluded.

---

## Default User

**Profile:** Logged-in admin of own team. Normal data volumes. Default browser.

**Login:** `agent-browser open <login-link-url-for-default-admin>`. The dev-mode `spatie/laravel-login-link` exposes a click-to-login URL on the login page; follow that URL directly to bypass the login form.

**Behavior:** Walks the happy path. Click. Navigate. Submit. Read.

**Bug class hunted:** Happy path regression, baseline broken-features.

**Required check:** Charter's Definition of Done items 1-N must be ticked off by this persona on the changed surface.

---

## First-Time User

**Profile:** Brand-new team, fresh signup. Empty CRM (zero Companies, People, Opportunities, Tasks, Notes). Follows the onboarding flow.

**Login:** Create a fresh user via `php artisan tinker --execute 'App\Models\User::factory()->create();'`, then open their team's URL via login-link. Or use the test seeder if available.

**Behavior:** Sees onboarding, creates first record, expects gentle empty states.

**Bug class hunted:** Empty-state bugs (no items but list still shows pagination, "No data" text missing, broken default views), onboarding gaps, missing seed data when user expects examples, broken first-record-experience.

**Required check:** Empty-state of every list view exposed by the diff.

---

## Power User

**Profile:** 1000+ contacts, keyboard-driven, uses bulk actions, exports to CSV regularly.

**Setup:** If the dev DB has < 500 records of the relevant model, this persona is best-effort and may report "skipped — insufficient data."

**Behavior:** Tab through forms. Use keyboard shortcuts. Bulk-select. Search/filter at scale. Export.

**Bug class hunted:** Performance regressions (N+1, slow filter queries, pagination beyond page 10), bulk-action bugs (some-records-skipped, partial failure with no error), keyboard navigation broken.

**Required check:** When changes touch list views, run a `php artisan db:query` count query on the relevant model and report if performance is observably degraded (page render > 2s, or any query > 500ms in Clockwork/Telescope).

---

## Rushed Salesperson

**Profile:** On a sales call, on-screen with a customer. Mashes Enter. Navigates before save indicator clears. Autocompletes wrong contact.

**Behavior:**
- Open form → fill → press Enter immediately (test for double-submit)
- Open form → click Save → click again before button disables (test idempotency)
- Open form → start filling → navigate to a different page mid-form (test draft preservation)
- Use back button after submitting (test idempotency, "Confirm form resubmission")

**Bug class hunted:** Race conditions, lost writes, optimistic-UI desync (UI says "saved" but DB hasn't yet), double-records.

**Required check:** Every form on the changed surface gets a "click Save twice in 100ms" probe. Verify no duplicate record was created (DB query before/after).

---

## Destructive User

**Profile:** Random clicker. Refreshes mid-form. Hits browser back/forward unpredictably.

**Behavior:**
- Refresh during a save (form submitted, page refreshed before response)
- Hit browser back during a multi-step wizard
- Drag-and-drop midway, then release outside drop zone
- Open same record in two tabs, edit different fields, save both

**Bug class hunted:** Idempotency failures, partial state (form submitted but UI didn't update), broken transactions, lock conflicts.

**Required check:** For any wizard/multi-step flow on the diff, run the "back-button mid-step" probe.

---

## Inattentive Returner

**Profile:** Opened the app yesterday, has 3 tabs left open. Returns now. Session may be stale.

**Behavior:**
- Refresh after long idle (test session expiry handling)
- Submit a form built before logout, after re-login (test CSRF token rotation)
- Two tabs of the same record, refresh one to see other tab's edit (test optimistic locking)

**Bug class hunted:** CSRF mismatch (419 errors with no friendly message), expired tokens, optimistic-locking failures (silent overwrite of another user's edit), tab desync.

**Required check:** If the diff touches form submission or auth, simulate "submit form with stale CSRF token" — expected: friendly retry, not a crash.

---

## Cross-Tenant Spy

**Profile:** Logged in as Team A admin. Aware of Team B's existence. Probes for isolation gaps.

**Setup:** Two teams must exist in the dev DB. The dev seeder should create them; if not, create a second team via `php artisan tinker --execute 'App\Models\Team::factory()->create();'` and assign at least one record to it.

**Behavior:** See `references/multi-tenant-checklist.md` for the 5-check protocol. This persona's entire job is the checklist.

**Bug class hunted:** Multi-tenant isolation leaks. **The highest-leverage SaaS check.**

**Required check:** Whenever a model/policy/scope path is touched, the full 5-check protocol runs. Never reduced away.

---

## Script Kiddie

**Profile:** Probes inputs with payloads. Tampers tokens. Fuzzes APIs.

**Behavior:**
- Paste payloads from `references/data-nasties.md` into every text input on the diff: `<script>alert(1)</script>`, `' OR '1'='1`, path traversal, etc.
- For API/MCP changes: send out-of-spec values (negative IDs, oversize strings, type mismatches). Tamper with bearer tokens.
- For mass-assignment-prone forms: try to POST `is_admin=1`, `team_id=<other-team>`, `tenant_id=<other-team>`.

**Bug class hunted:** XSS (executed payload), SQL injection, mass-assignment (unintended fields persisted), IDOR (`/companies/<other-id>` returns 200), token-tampering bypass.

**Required check:** Every text input on the changed Filament/Livewire form gets at least one XSS payload + one SQL payload. Every PATCH endpoint gets at least one mass-assignment probe.
