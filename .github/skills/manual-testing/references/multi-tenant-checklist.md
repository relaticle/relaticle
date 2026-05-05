# Multi-Tenant Cross-Tenant Spy Protocol (5 Checks)

Run as a single block whenever any model/policy/scope path is touched. **Never reduced away** while the skill is running. The skip conditions in `SKILL.md` are the only escape — comment-only / doc-only diffs where the skill itself doesn't run.

Each failure is **Critical** severity. Hard block. No auto-fix. Halt and report.

## Setup

The skill needs **two teams** with at least one record each. Verify and create if needed:

```bash
php artisan tinker --execute '
    if (App\Models\Team::count() < 2) {
        $userB = App\Models\User::factory()->create();
        $teamB = $userB->personalTeam();
        App\Models\Company::factory()->for($teamB, "team")->create(["name" => "TeamB Cross-Tenant Probe Co"]);
    }
    echo App\Models\Team::count() . " teams\n";
'
```

Capture: Team A's user (default `manuk.minasyan1@gmail.com`), Team B's user, Team B's record IDs.

## Check 1: Direct URL probe

Logged in as Team A admin via `agent-browser open <login-link-url-team-a>`, navigate to:

- `/app/<team-b-slug>/companies`
- `/app/<team-b-slug>/companies/<team-b-company-id>`
- `/app/<team-b-slug>/companies/<team-b-company-id>/edit`
- Same paths for People, Opportunities, Tasks, Notes (whatever the diff touched)

**Expected:** 403 or 404 page. Filament should redirect to the team-A workspace or show a "not found" / "not authorized" message.

**Forbidden:** 200 with data visible. Any HTML containing the Team B record's name.

**Capture:** screenshot per probe; HTTP status from `agent-browser eval "fetch(window.location.href).then(r => r.status)"`.

## Check 2: REST API probe

With Team A's Sanctum token:

```bash
TOKEN_A=$(php artisan tinker --execute '
    $user = App\Models\User::firstWhere("email", "manuk.minasyan1@gmail.com");
    echo $user->createToken("xt-spy-A")->plainTextToken;
')

# For each touched resource and Team-B record id:
for op in GET PATCH DELETE; do
    curl -sS -X $op -H "Authorization: Bearer $TOKEN_A" \
        -H "Accept: application/json" \
        -H "Content-Type: application/json" \
        -d '{"name":"hijacked"}' \
        https://calm-lemur.test/api/v1/companies/<team-b-company-id>
    echo
done
```

**Expected:** 403 or 404 on all three. No data returned.

**Forbidden:** 200 with data. PATCH succeeds (DB query confirms mutation). DELETE succeeds (DB query confirms removal).

**Capture:** Response status + body for each. Run `php artisan tinker --execute 'echo App\Models\Company::find("<team-b-id>")->name ?? "DELETED";'` after PATCH/DELETE to confirm DB integrity.

## Check 3: MCP tool probe

Call the Show / Update / Delete tool with another team's record ID. The harness depends on how MCP tools are tested — typical flow is to invoke them through the AI route or directly through the MCP class with Team A's auth context.

```bash
php artisan tinker --execute '
    $userA = App\Models\User::firstWhere("email", "manuk.minasyan1@gmail.com");
    Auth::login($userA);
    $tool = new App\Mcp\Tools\Company\ShowCompanyTool();
    try {
        $result = $tool->run(["id" => "<team-b-company-id>"]);
        var_dump($result);
    } catch (Throwable $e) {
        echo "EXPECTED FAIL: " . $e->getMessage() . "\n";
    }
'
```

**Expected:** Exception or error response with no data leak.

**Forbidden:** Tool returns the Team B record.

**Capture:** Output of the tinker session.

## Check 4: Search / filter leak probe

Logged in as Team A, perform searches in Filament Global Search and individual resource table filters using text known to match a Team B record (the seeder above used `"TeamB Cross-Tenant Probe Co"`).

**Expected:** Zero results visible. No record card, no count, no link.

**Forbidden:** Any preview, count, or link to the Team B record.

**Capture:** Screenshot of search results.

## Check 5: Bulk-action ID injection

Open a list view as Team A, identify a bulk-action endpoint. Use `agent-browser eval` or DevTools to inspect the form's POST shape. Construct a forged POST that includes a Team B record ID alongside Team A IDs:

```bash
# Inspect the bulk-action form
agent-browser eval "document.querySelector('form[action*=bulk]').outerHTML"

# Forge a request — typical Filament pattern uses Livewire over a /livewire/update endpoint.
# Substitute the actual endpoint and CSRF token from the page.
curl -sS -X POST -H "Cookie: <session-cookie>" \
    -H "X-CSRF-TOKEN: <token>" \
    -d "selectedRecords[]=<team-a-id-1>&selectedRecords[]=<team-b-id-1>&action=delete" \
    https://calm-lemur.test/livewire/update
```

**Expected:** Either a 403 error, or the action only affects Team A records (DB query confirms Team B record untouched).

**Forbidden:** Team B record mutated/deleted.

**Capture:** Response body + DB state of Team B record after the probe.

## Reporting

Each failed check produces a Critical finding. The finding's RIMGEA enrichment must:

- **Generalize:** re-run the same check on sibling resources (Companies/People/Opportunities/Tasks/Notes). One Critical finding per resource that fails.
- **Articulate:** name the surface, the operation, the team-B record ID, and the observed leakage in plain English.
- **Cite oracle:** `Claims` (README "5-layer authorization" violated) is the standard oracle for these failures.

After reporting, the skill **halts**. No further matrix cells run. Auto-fix is permanently disabled in tenant code per `references/risk-rubric.md`.
