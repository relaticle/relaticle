# Surface Playbooks

Concrete how-to for exercising each front door. All UI work goes through `agent-browser` per the global CLAUDE.md. API and MCP go through native tooling.

---

## Filament UI

**Login:** Use the dev login-link. The app's login page renders `<x-login-link email="manuk.minasyan1@gmail.com" />` in `@env('local')`. The rendered link target is the URL the skill follows.

```bash
# Get the login-link URL (locally, in the dev server)
agent-browser open https://calm-lemur.test/app/login
agent-browser get html '.fi-login-link' | grep -oE 'href="[^"]+"' | sed 's/href="//;s/"$//'
```

**Drive a page:**

```bash
agent-browser open <login-link-url>
agent-browser snapshot -i           # capture interactive elements as @e1, @e2, ...
agent-browser click @e3             # operate by ref, never raw selectors
agent-browser fill @e5 "Acme Corp"
agent-browser screenshot --annotate /tmp/cell-5.png
agent-browser console               # capture browser console output
agent-browser errors                # capture page errors
```

**Per matrix cell, capture:**

- Annotated screenshot to `.context/testing/reports/<run-slug>/cell-<N>.png`
- Console output (full, not just errors)
- Page errors
- Network 4xx / 5xx (via `agent-browser eval` reading performance entries)

**Specific watches in Filament:**
- Loading spinners must clear within 5s.
- Notifications appear after every successful Action.
- Validation errors render inline (per-field), not just as a banner.
- Bulk-action confirmations show the affected record count.

---

## Livewire

Same browser harness as Filament. Specific watches:

- After every navigation, re-snapshot — Livewire DOM updates won't reflect in stale snapshots.
- After actions that update the URL, verify `agent-browser get url` matches expected.
- For polling components (`wire:poll`), watch the network tab via `agent-browser eval`:

```javascript
performance.getEntriesByType('resource').filter(r => r.name.includes('livewire'))
```

- Inspect Livewire state when a bug is suspected:

```bash
agent-browser eval "JSON.stringify(window.Livewire.all().map(c => ({id: c.id, name: c.name, data: c.data})))"
```

---

## REST API

**Setup:** Mint a Sanctum token for the test user.

```bash
php artisan tinker --execute "
  \$user = App\Models\User::factory()->create();
  \$token = \$user->createToken('manual-testing')->plainTextToken;
  echo \$token;
"
```

**Drive an endpoint:**

```bash
curl -sS -H "Authorization: Bearer $TOKEN" \
     -H "Accept: application/json" \
     -H "Content-Type: application/json" \
     -X GET https://calm-lemur.test/api/v1/companies | jq .
```

**Per cell, capture:**

- Full HTTP response (status, headers, body)
- Response time (`-w '%{time_total}\n'`)
- Diff against the API resource shape in `app/Http/Resources/`

**Specific watches:**
- Error envelope is consistent: 422 for validation, 401 unauth, 403 forbidden, 404 not found, 500 server error.
- Pagination metadata present on list endpoints (`data`, `links`, `meta`).
- Encrypted custom fields are returned only to the owner — see `references/multi-tenant-checklist.md`.

---

## MCP server

**Drive a tool via the MCP test harness:**

For the manual testing skill's purposes, exercise the changed MCP tool by calling `mcp__laravel-boost__database-query` first to capture the DB state, then invoking the tool, then re-querying to compare.

Tools to exercise per entity (each entity has all 5):

- `List<Resource>Tool`
- `Show<Resource>Tool`
- `Create<Resource>Tool`
- `Update<Resource>Tool`
- `Delete<Resource>Tool`

If the diff touched a `Base*Tool` (e.g., `BaseUpdateTool`), all 29 entity tools must be smoke-tested.

**Per cell, capture:**

- Tool input/output JSON
- DB state before/after
- Whether the tool's `description` field reflects the change (run a list-tools probe)

**Specific watches:**
- Tool descriptions match the changed schema/validation.
- Error responses match the MCP spec (have a clear, machine-readable error code).
- No encrypted-field plaintext leaks in tool responses.
- Tools refuse cross-tenant operations (see `references/multi-tenant-checklist.md`).
