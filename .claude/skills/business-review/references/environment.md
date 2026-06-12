# Stage 0 — Environment discovery (nothing is assumed; everything is derived)

The skill must keep working when app code, routes, domains, panels, or seeders change.
The rule that makes that true:

> **Every environment fact in this skill's files is a CACHED HINT, not truth.**
> Stage 0 re-derives the volatile facts from the **running app** each run, uses the
> derived values, and — when a derived value contradicts a cached one — **updates the
> cached file in the same run** (self-heal) and bumps its `verified:`/`cached_at` date.

Cached hints live in `relaticle-profile.json` (`volatile_fields`) and the
`agent-browser-relaticle` skill. Derived values for THIS run are written to
`$REVIEW_DIR/project-profile.json`, which every later stage reads.

## 1. URL resolution (never guess a domain)

The panels are conditionally domain-routed (`AppPanelProvider`, `SystemAdminPanelProvider`):
a configured domain wins; otherwise a path under the base URL. Derive, in order:

```bash
php artisan tinker --execute 'echo json_encode([
  "base"            => config("app.url"),
  "app_domain"      => config("app.app_panel_domain"),
  "app_path"        => config("app.app_panel_path", "app"),
  "sysadmin_domain" => config("app.sysadmin_domain"),
  "sysadmin_path"   => config("app.sysadmin_path", "sysadmin"),
]);'
```

- app panel URL = `https://{app_domain}` when set, else `{base}/{app_path}`
- sysadmin URL  = `https://{sysadmin_domain}` when set, else `{base}/{sysadmin_path}`

Confirm each derived URL actually responds before trusting it:

```bash
curl -ks -o /dev/null -w '%{http_code}\n' "$APP_PANEL_URL/login"   # expect 200/302, not 000/5xx
```

**Fallback chain when a derived URL does not respond** (work the chain, don't ask yet):

1. `php artisan route:list --json | python3 -c 'import json,sys;\
   rs=json.load(sys.stdin); print([r["uri"] for r in rs if "login" in (r.get("name") or "")+r["uri"]][:10])'`
   — the route table is ground truth for entry points (Filament registers
   `filament.<panel>.auth.login`). A changed login path shows up here first.
2. `herd sites` / `herd links` — the actual hostnames Herd serves this checkout under
   (catches renamed dirs and Polyscope clones).
3. `.env` (`APP_URL`, `*_DOMAIN` keys) read directly — config cache may be stale; if
   `.env` disagrees with `config()`, run `php artisan config:clear` and re-derive.
4. Only if all of the above fail → fold ONE question into the end-of-Stage-1 batch.

**Self-heal:** if the working URL differs from `relaticle-profile.json.panel_urls` or
from `agent-browser-relaticle`'s documented URLs, update those files now, stamp
`cached_at`/`verified:` with today, and note the heal in REVIEW.md.

## 2. Credentials resolution (per role in scope)

For each role the planned journeys need (`profile.role_credentials`):

1. **Try the cached login** through the real login form (browser).
2. On failure, **re-seed**: `php artisan db:seed --class=LocalSeeder` (gated to local env;
   also tops AI credits) and `php artisan db:seed --class=SystemAdministratorSeeder`;
   retry the login.
3. Still failing → **bootstrap as setup** (browser-truth §3b): create a namespaced user
   via factory (`User::factory()->withPersonalTeam()->create([...])`, password
   `password`, email prefixed `br-rel-…`), least-invasive, never mutate a real account.
4. Nothing works → credentials **Context gap** (end-of-Stage-1 question or
   `ai-needs-human`) — **never `blocked`** (the browser channel is fine).

A role with no obtainable login is recorded as a structured frontier item
(`{item, why_unreached, how_to_close}`), never silently skipped.

**Self-heal:** if a seeder email/password changed (login only works with new values),
update `relaticle-profile.json.role_credentials` + the `agent-browser-relaticle` table.

## 3. Selector & interaction resilience (browser layer)

- Prefer **semantics over selectors**: `agent-browser find role <role> "<label>" click`,
  `$wire.set(...)`/`$wire.mountAction(...)` state writes, and a11y snapshots — these
  survive Blade/Tailwind refactors that break CSS-class selectors.
- A cookbook pattern (from `agent-browser-relaticle`) that fails **twice** is treated as
  stale: re-derive the interaction from a fresh `snapshot -i -c -d 8`, make it work, then
  **update the cookbook** with the new pattern + `verified:` date. Never keep retrying a
  dead selector, and never conclude "feature broken" from a selector miss alone — the
  discriminator for *app-broken vs approach-wrong* is reading the page (health gate,
  `preflight.md` §3).

## 4. Infra discovery (queue / Redis / Reverb / credits)

Derive, don't assume:

```bash
php artisan tinker --execute 'echo json_encode([
  "queue"      => config("queue.default"),
  "redis_db"   => config("database.redis.default.database"),
  "redis_queue_conn" => config("queue.connections.redis.connection") ?? null,
  "reverb"     => [config("broadcasting.default"), config("reverb.servers.reverb.host") ?? null, config("reverb.servers.reverb.port") ?? null],
  "horizon"    => class_exists(\Laravel\Horizon\Horizon::class),
]);'
php artisan horizon:status        # running / paused / inactive
```

- **Queue-ownership sentinel probe** (the false-signal killer — see gotchas: shared local
  Redis once let ANOTHER Herd app's Horizon consume this app's jobs): dispatch a trivial
  queued job tagged `br-rel-sentinel-<run>` (e.g. a queued closure isn't possible from
  tinker — use a real lightweight app job or `php artisan queue:work --once` on a test
  dispatch) and confirm THIS checkout's worker consumed it (`horizon` dashboard or log
  line from this process). If another consumer ate it → queued-flow verification is
  invalid; fix (`.env` dedicated `REDIS_DB`/prefix, restart Horizon) before any queued
  journey, or record the gap as `blocked` for queue-dependent journeys at Tier 3.
- **Reverb reachability from the automation browser**: open the app, check
  `window.Echo` connects (no repeated websocket errors in `agent-browser console`).
  A wrong-host websocket is an env defect to fix in setup, not a product finding
  (verified: 2026-06-10, PR 322 incident).
- **AI credits**: chat journeys drain credits; check and top up via
  `LocalSeeder` (re-seed) BEFORE chat journeys, not after the first
  insufficient-credits error mid-journey.

At **Tier 3** the full set (async Horizon on a dedicated Redis DB + sentinel pass +
Reverb connected + credits topped + per-role creds) is a hard gate — a miss is `blocked`
with `how_to_close` (e.g. "set REDIS_DB=3 for this checkout and restart Horizon"), never
a silently weaker verdict. At Tier ≤ 2, record misses honestly in the frontier and judge
only what the environment can actually support.

## 5. Output

Stage 0 ends by writing `$REVIEW_DIR/project-profile.json`: the static fields copied
from `relaticle-profile.json`, the volatile fields replaced with TODAY's derived values,
plus:

```json
{
  "derived_at": "<iso datetime>",
  "env_realism": {
    "queue": "redis", "horizon": "running", "redis_db": 3, "sentinel": "owned",
    "reverb": "connected", "credits": "topped", "tier3_ready": true
  },
  "heals": ["panel_urls.app: app.relaticle.test -> crm.relaticle.test (config changed)"]
}
```

`heals[]` lists every cached file updated this run — the report's Notes section surfaces
them so the human sees the skill adapting to the app.
