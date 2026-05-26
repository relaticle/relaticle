# Browser Patterns — Relaticle (Filament v5 + Livewire v4 + Alpine.js v3)

Helper for running `agent-browser` against the local Relaticle site at `https://relaticle.test`. These workarounds are **not bugs in Relaticle** — they're limitations of generic browser automation against custom widget libraries (Filament's Select, date pickers, etc.).

Read this before attempting any browser automation during Phase 6. It will save 15-30 minutes of debugging.

## URL is fixed for Relaticle

Unlike workspace-derived multi-clone setups, Relaticle's local URL is constant:

```bash
export RELATICLE_HOST="relaticle.test"
export RELATICLE_URL="https://$RELATICLE_HOST"
export AB_SESSION="relaticle-review"
```

Sanity check we're in a Laravel/Relaticle checkout:

```bash
[ -f artisan ] && [ -f composer.json ] && grep -q "relaticle" composer.json \
  || { echo "Not in a Relaticle checkout — stop and ask the user."; exit 1; }
```

All examples below use `$RELATICLE_URL` and `$AB_SESSION`.

## TL;DR — the golden rules

1. **Always use `--session "$AB_SESSION"`** on every command, or another agent's session can hijack your navigation.
2. **Set viewport explicitly** (`set viewport 1920 1080`) — default is 1280x720, which clips Filament modals.
3. **Plain `agent-browser click` does NOT work on Filament Select dropdowns or Filament form submit buttons.** Use `eval` with dispatched `mousedown`+`mouseup`+`click` events, or set Livewire state directly via `$wire.set(...)`.
4. **Plain `agent-browser type` does NOT work on Filament date pickers.** Use `$wire.set('data.field_name', 'YYYY-MM-DD', true)`.
5. **Refs (`@eXX`) shift between every `snapshot` call.** Use `find text "..." click` or keep snapshot→interaction close together.
6. **When in doubt, read and write Livewire state directly via `$wire`.** Faster and more reliable than clicking through custom widgets.
7. **To submit a Filament action modal (Delete, custom row action, anything with a form)** use `$wire.mountAction(...)` + `$wire.set('mountedActions.0.data.FIELD', value, true)` + `$wire.callMountedAction()`. **Every `$wire` call must be `await`-ed.** This is the single most useful pattern in the whole skill.
8. **Use `-i -c -d 8` on snapshot calls** for a focused accessibility tree instead of a massive dump.
9. **Switching tenant changes the URL slug** — `/app/<team-slug>/...`. After a team switch, re-derive the panel URL before navigating.

## Session setup (do this first, every time)

```bash
agent-browser --session "$AB_SESSION" set viewport 1920 1080
agent-browser --session "$AB_SESSION" open "$RELATICLE_URL"
```

All subsequent commands assume `--session "$AB_SESSION"` is being passed. Omitted from examples below for brevity.

Without `--session`, sessions across parallel agents share the browser daemon and can redirect each other. If your navigation suddenly goes to a different domain, check for a session conflict.

## Test credentials

Seeded by `database/seeders/LocalSeeder.php` (skips outside local env) and `SystemAdministratorSeeder`.

| Surface | Email | Password | Notes |
|---|---|---|---|
| App panel (`/app`) | `manuk.minasyan1@gmail.com` | `password` | Factory default password |
| Sysadmin panel (`/sysadmin`) | `sysadmin@relaticle.com` | `password` | `SystemAdministratorSeeder` |

If creds don't work after a fresh checkout: `php artisan db:seed --class=LocalSeeder` (it gates on `app()->isLocal()`).

NEVER `migrate:fresh` mid-review.

For per-review test users (paid plan, multi-team scenarios), create via factory in tinker and document in the test plan:

```bash
php artisan tinker --execute '
$u = \App\Models\User::factory()->withPersonalTeam()->create([
  "email" => "br-local-2tenants@example.test",
  "name" => "BR Local 2-tenant",
]);
echo $u->id;
'
```

## Login — two surfaces

Both panels use Filament's stock login (not Volt). Relaticle does NOT have a separate Livewire/Volt user-app login.

### App panel login (`/app/login`)

```bash
agent-browser open "$RELATICLE_URL/app/login"
agent-browser fill 'input[name="email"]' "manuk.minasyan1@gmail.com"
agent-browser fill 'input[name="password"]' "password"
# Filament's submit button: dispatch the mount sequence
agent-browser eval '(async () => {
  const btn = document.querySelector("form button[type=submit]");
  ["mousedown","mouseup","click"].forEach(t => btn.dispatchEvent(new MouseEvent(t, {bubbles:true})));
})()'
agent-browser wait --load networkidle
```

After login, the user lands on `/app/<team-slug>/...` (default team's slug). To navigate further, re-derive the slug from `location.pathname`.

### Sysadmin panel login (`/sysadmin/login`)

Same shape, different email:

```bash
agent-browser fill 'input[name="email"]' "sysadmin@relaticle.com"
agent-browser fill 'input[name="password"]' "password"
# (same submit-button sequence as above)
```

## Filament Select dropdown — the click sequence

```bash
# Plain click on trigger does NOT show options reliably:
# agent-browser click '.fi-fo-select-trigger'  ← unreliable

# Instead, use the a11y tree to find and click options:
agent-browser snapshot -i -c -d 8 | grep -i 'combobox\|select'
agent-browser find role combobox "Company" click
# Then options:
agent-browser find role option "Acme Corp" click
```

Or set the value directly via Livewire:

```bash
agent-browser eval 'await $wire.set("data.company_id", 42, true)'
```

## Filament date picker

```bash
# Plain type does NOT work — Filament uses a custom widget.
# Use $wire directly:
agent-browser eval 'await $wire.set("data.closes_at", "2026-06-15", true)'
```

## Filament action modal — the gold pattern

For any Delete action, custom row action, or any button-triggered modal with a form:

```javascript
// Open the action programmatically (avoids click flakiness)
await $wire.mountAction('delete', { recordKey: 42 });

// Fill any form fields inside the action
await $wire.set('mountedActions.0.data.reason', 'no longer needed', true);

// Call the mounted action (submits)
await $wire.callMountedAction();
```

Wrap in `agent-browser eval '(async () => { ... })()'`. Always `await` each `$wire.*` call.

## Read Livewire state

```bash
agent-browser eval 'JSON.stringify($wire.get("data"))'
# Or for an action modal:
agent-browser eval 'JSON.stringify($wire.get("mountedActions.0.data"))'
```

## Tenant switching mid-session

```bash
# Read current team slug from URL
agent-browser eval 'location.pathname.split("/")[2]'
# Switch via tenant menu (after clicking it open):
agent-browser find role menuitem "Other Team Name" click
agent-browser wait --load networkidle
```

After switch, the panel URL prefix changes. Re-derive `$BASE_PANEL_URL` from `location.pathname`.

## Asserting a CRM record's existence (DB-direct)

Browser-based assertion is brittle for records visible in long tables. Prefer DB checks:

```bash
php artisan tinker --execute '
$c = \App\Models\Company::where("name", "BR Test Co")->first();
echo $c ? "found:".$c->id : "missing";
'
```

For tenant-scoped queries, set tenant context first:

```bash
php artisan tinker --execute '
$team = \App\Models\Team::find(1);
\Relaticle\\CustomFields\\Services\\TenantContextService::setTenantId($team->id);
$count = \App\Models\Company::count();
echo $count;
'
```

## Snapshot discipline

```bash
# Bad: dumps the whole page, blows context
agent-browser snapshot

# Good: interactive elements only, focused depth
agent-browser snapshot -i -c -d 8

# Better: filter to the area you care about
agent-browser snapshot -i -c -d 8 --root '.fi-modal-content'
```

## CSRF / session quirks

- Long-idle sessions can hit 419 (CSRF mismatch). `agent-browser reload` before the offending case — Livewire pulls a fresh token.
- Switching branches between reviews invalidates server-side sessions tied to the old DB state. First case in a batch should always be a fresh login.

## When the page renders blank after a Livewire action

Common causes (in order):

1. `wire:loading` stuck — health-gate flags this. Check the network tab for a hung request.
2. A Livewire validation error rendered into a hidden slot — `agent-browser get text 'body'` will show it.
3. Console threw — `agent-browser console | tail -20`.

## Closing modals reliably

Filament modals fade ~300ms. Don't assert removal immediately after click:

```bash
agent-browser click '.fi-modal-close-action'
agent-browser wait '.fi-modal-window:not([data-state="open"])' 2000
# OR navigate away and back if cleanup matters
```
