---
name: agent-browser-relaticle
description: "Use whenever driving agent-browser against the local Relaticle app (relaticle.test and its panels) for testing, QA, business review, or UI automation. Covers Filament v5 + Livewire v4 quirks specific to this codebase: panel URL derivation (domain-routed vs path-routed — never assume), login flows for the app and sysadmin panels, seeded credentials, Select/date-picker interaction, the $wire.mountAction gold pattern, tenant switching, Reverb/queue hazards, and session isolation. Every hard fact here is a DATED CACHED HINT — when one fails, re-derive from the running app and update this file (self-heal). Not for other sites or generic browser automation."
---

# agent-browser × Relaticle — cookbook (cached hints, verified dates, self-healing)

**Prime rule: facts below are cached hints, not truth.** The app's URLs, routes,
selectors, and seeders change. When a documented pattern fails **twice**, stop retrying:
re-derive it from the running app (procedures below), make it work, then **update this
file** with the new pattern and today's `verified:` date.

## 1. URL derivation (NEVER hardcode; panels are conditionally domain-routed)

```bash
php artisan tinker --execute 'echo json_encode([
  "base"            => config("app.url"),
  "app_domain"      => config("app.app_panel_domain"),
  "app_path"        => config("app.app_panel_path", "app"),
  "sysadmin_domain" => config("app.sysadmin_domain"),
  "sysadmin_path"   => config("app.sysadmin_path", "sysadmin"),
]);'
```

- app panel = `https://{app_domain}` if set, else `{base}/{app_path}`
- sysadmin  = `https://{sysadmin_domain}` if set, else `{base}/{sysadmin_path}`
- **Routing mode is per-checkout — derive it, never carry it over from another
  workspace.** Both modes are live in the wild:
  - Conductor workspace `bamako`, `APP_PANEL_DOMAIN`/`SYSADMIN_DOMAIN` empty →
    path-routed: `https://bamako.test/app`, `https://bamako.test/sysadmin`
    (verified: 2026-08-12).
  - A checkout with the `*_DOMAIN` envs set → domain-routed, e.g.
    `https://app.relaticle.test`, `https://sysadmin.relaticle.test`
    (verified: 2026-06-12).

  Each Conductor workspace is served by Herd under its own `https://<workspace>.test`,
  so the host changes too. Run the `tinker` block above every run and use what it
  returns.
- Login entry points are Filament-registered routes; ground truth:
  `php artisan route:list --json` filtered for `login` (names like
  `filament.app.auth.login`). If a URL 404s, check the route table before anything else.
- Host unreachable? `herd sites` / `herd links` shows what Herd actually serves this
  checkout as (catches renamed dirs / Polyscope clones). `.env` vs `config()` mismatch →
  `php artisan config:clear`.

## 2. Session setup (every time)

```bash
export AB_SESSION="<purpose>-<run-id>"     # ALWAYS unique per agent — sessions are machine-global
agent-browser --session "$AB_SESSION" set viewport 1920 1080
agent-browser --session "$AB_SESSION" open "$APP_PANEL_URL"
```

Pass `--session "$AB_SESSION"` on EVERY call (or export `AGENT_BROWSER_SESSION`).
Default 1280x720 clips Filament modals (verified: 2026-05).

## 3. Credentials (seeded; re-derive when login fails)

| Surface | Login | Password | Source |
|---|---|---|---|
| app panel | `manuk.minasyan1@gmail.com` | `password` | `database/seeders/LocalSeeder.php` (verified: 2026-06-12) |
| sysadmin | `sysadmin@relaticle.com` | `password` | `SystemAdministratorSeeder` (verified: 2026-06-12) |
| per-run test users | `br-rel-<run>-…@example.test` | `password` | factory |

Login failing? In order: `php artisan db:seed --class=LocalSeeder` (local-gated; also
tops AI credits) → `--class=SystemAdministratorSeeder` → factory-create a namespaced
user (`User::factory()->withPersonalTeam()->create([...])`). If the seeder emails
changed, fix this table (self-heal).

Dev-login affordance: the app registers `laravel-login-link` (route `loginLinkLogin`,
POST `laravel-login-link-login`; verified: 2026-06-12 via `route:list`) — local login
pages may render one-click "Login as …" links; prefer them over typing credentials when
present.

## 4. Login flow (both panels — Filament stock login)

**CORRECTION (verified: 2026-06-12, review PR 336):** the `input[name="email"]` selector
is WRONG — it matches a **hidden** input belonging to the `laravel-login-link` dev package
(the page has hidden `_token`/`email`/`key`/`guard`/`user_model` inputs from that form).
`agent-browser fill` against that hidden field **hung the daemon** (`os error 35`,
"daemon may be busy or unresponsive") and never submitted. The REAL Filament inputs have
NO `name` attribute — they are `id="form.email"` / `id="form.password"` with
`wire:model="data.email"` / `data.password`, inside the `<form wire:submit="authenticate">`.

The recipe that works when `fill`/`type` hang (eval-driven, daemon-safe):

```bash
export AGENT_BROWSER_SESSION="<unique>"
agent-browser open "$PANEL_URL/login"
agent-browser eval '(() => {
  const e=document.getElementById("form.email"), p=document.getElementById("form.password");
  e.value="'"$LOGIN"'"; e.dispatchEvent(new Event("input",{bubbles:true}));
  p.value="password";  p.dispatchEvent(new Event("input",{bubbles:true}));
  const f=[...document.querySelectorAll("form")].find(x=>x.getAttribute("wire:submit")==="authenticate");
  f.requestSubmit(); return "submitted";
})()'
sleep 4
agent-browser eval 'location.pathname'   # confirm you left /login (lands on /<team-slug>)
```

- **Daemon hangs on `fill`/`type`** in this environment (verified: 2026-06-12). When a
  command returns `os error 35` / no output, `pkill -9 -f agent-browser; sleep 3` and
  re-open. `open`/`eval`/`snapshot`/`screenshot` are reliable; `click` is flaky — prefer
  `eval` with `el.click()` for `<a wire:navigate>` links.
- Many stale `--session` entries overload the daemon; keep ONE session per run and chain
  commands with `&&` in a single shell call (the daemon persists the browser).

<details><summary>Older recipe (fill+click) — left here for reference, did NOT work on 2026-06-12</summary>

```bash
agent-browser --session "$AB" open "$PANEL_URL/login"
agent-browser --session "$AB" fill 'input[name="email"]' "$LOGIN"
agent-browser --session "$AB" fill 'input[name="password"]' "password"
sleep 1
agent-browser --session "$AB" click "Sign in"
agent-browser --session "$AB" wait --load networkidle
agent-browser --session "$AB" eval 'location.pathname'   # confirm you left /login
```
</details>

- **`click` / `fill` take the element's VISIBLE TEXT or a CSS selector, NOT
  `find role button "<name>"`** — that subcommand syntax errors on this binary
  (verified: 2026-06-12). Use `agent-browser click "Sign in"`.
- After **app-panel** login you land on the default team path `…/<team-slug>/…`
  (e.g. `/tapix`) — re-derive the slug from `location.pathname` before navigating further
  (verified: 2026-06-12).
- After **sysadmin** login you land on `/` (Dashboard) on `sysadmin.relaticle.test`
  (verified: 2026-06-12).
- A **"Developer Login"** button is present on the login page but clicking it alone did
  not establish a session in testing — prefer the fill+click recipe above
  (verified: 2026-06-12).

## 4b. Screenshot paths — ALWAYS absolute

`agent-browser screenshot` parses a RELATIVE path containing `/` as a CSS selector and
fails (`Unexpected token "/" while parsing css selector`). Always pass an absolute path:
`agent-browser --session "$AB" screenshot "$(pwd)/.context/reviews/<dir>/case-X/shot.png"`
(verified: 2026-06-12).

## 5. The gold patterns (Filament v5 + Livewire v4)

Prefer **semantics over CSS selectors** — a11y-role finds and Livewire state survive
Blade/Tailwind refactors.

**`$wire` is NOT in scope inside `agent-browser eval`** (it's an Alpine magic; eval runs
in plain page context — verified: 2026-06-12, cost a run 4 round-trips + one
self-inflicted 500 where the server was asked to call a method literally named
`$wire`). Resolve the component first, then use `.set(...)` / `.call(...)`:

```js
// by name (page components):
const meta = window.Livewire.all().find(c => /TasksBoard/.test(c.name)); // metadata ONLY: {id, name}
const comp = window.Livewire.find(meta.id);                              // the real component
await comp.call("moveCard", "<recordId>", "<columnId>");
// or from a DOM element (modals, nested components):
const comp2 = window.Livewire.find(el.closest("[wire\\:id]").getAttribute("wire:id"));
await comp2.set("mountedActions.0.data.title", "value", true);
await comp2.call("callMountedAction");
```

- **`Livewire.all()` entries have NO `.call`/`.set`** — they are metadata; always pass
  the id through `Livewire.find()` (verified: 2026-06-12).
- **Select dropdowns** (plain click is unreliable):
  `agent-browser find role combobox "<label>" click` then
  `agent-browser find role option "<option>" click` — or `comp.set("data.company_id", 42, true)`.
- **Date pickers** (plain type does nothing): `comp.set("data.closes_at", "2026-06-15", true)`.
- **Action modals (Delete, custom row actions) — the single most useful pattern:**
  ```js
  await comp.call("mountAction", "delete", { recordKey: 42 });
  await comp.set("mountedActions.0.data.reason", "why", true);
  await comp.call("callMountedAction");
  ```
  Every call must be `await`-ed. (verified: 2026-06-12 via the create-task modal)
- **Read Livewire state**: `agent-browser eval '... JSON.stringify(comp.get("data"))'`
- **Snapshots**: `agent-browser snapshot -i -c -d 8` (focused), never bare `snapshot`.
  Refs (`@eXX`) shift between snapshots — keep snapshot→interaction adjacent or use
  `find role/text … click`.
- **Modals fade ~300ms** — `agent-browser wait '.fi-modal-window:not([data-state="open"])' 2000`
  before asserting removal.
- **Tenant switching is browser-only** (in-app switcher; tinker tenant-switch breaks the
  session → persistent 403s). After a switch the URL slug changes — re-derive.

## 6. Environment hazards (dated)

- **Shared local Redis across Herd apps**: another app's Horizon can consume this app's
  queue jobs (verified: 2026-06-11 — Journey ate Relaticle chat jobs). Use a dedicated
  `REDIS_DB` in `.env`; before queue-dependent testing, dispatch a sentinel job and
  confirm THIS checkout's worker consumed it.
- **Reverb/websockets**: agent-browser's Chromium may use a wrong websocket host or a
  stale built bundle — looks like a dead page, is an env defect. `npm run build`, check
  `agent-browser console` for websocket errors (verified: 2026-06-10).
- **419 CSRF after idle** → `agent-browser reload` and retry once (verified: 2026-05).
- **A failed Livewire request leaves a full-screen error overlay in the DOM** (Laravel
  error page in a modal) that silently photobombs every later screenshot — the page
  underneath still works, so nothing looks wrong until you read the PNG back. After ANY
  errored `comp.call`, `agent-browser open` the page fresh (or remove the overlay)
  before shooting (verified: 2026-06-12 — a stale overlay replaced the board in an
  evidence shot).
- **Stale session after branch switches** → first action of a batch is a fresh login.
- **AI credits drain during chat testing** → re-seed `LocalSeeder` to top up before
  chat-heavy flows.
- **Screenshot pipeline can serve STALE FRAMES from a dead target** (verified:
  2026-08-18): after long sessions / viewport changes, `screenshot` kept returning a
  frame that no longer matched the DOM (evals said dark theme + correct state; PNG
  showed an old light half-render). Detection: `eval 'document.body.style.outline="40px
  solid red"'` → screenshot → if no red border, the pipeline is stale. Fix:
  `pkill -9 -f agent-browser; sleep 3`, new session, re-login. Don't debug the "bug"
  in the PNG before running the red-outline probe. UPDATE (verified: 2026-08-23): the
  pkill+new-session fix did NOT clear it, a fresh session's very first screenshots
  again failed the red-outline probe (dark-mode text read black in the PNG while
  `getComputedStyle` + `elementFromPoint` at the same coordinates said white). When
  the probe fails twice, stop shooting: assert via DOM reads (computed styles,
  elementFromPoint, rects) and treat those as the truth for visual verification.

## 7. DB-assert (corroboration only — the UI is the proof)

```bash
php artisan tinker --execute '$c = \App\Models\Company::where("name", "br-rel-test")->first(); echo $c ? "found:".$c->id : "missing";'
```

Tenant-scoped query? Set context first:
`\Relaticle\CustomFields\Services\TenantContextService::setTenantId($teamId);`
Never use tinker/DB writes to fix or fake a result — an on-screen error is a finding.

## 8. Screenshots

For any deliverable screenshot, invoke `Skill('screenshot-with-callout')` per shot
(annotate → verify-crop → shoot → read-back). Throwaway debug shots exempt.
