# Growth/GTM Master Program Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Execute the growth/GTM master spec end to end — measurement bootstrap, distribution week-1, five code PRs (G1–G5), the 8-post content wave + blog launch, the coordinated launch moment, and the standing sales/ops rituals.

**Architecture:** Ops tasks (measurement, directories, outreach, launch) are checklist-driven with hard founder-approval gates before anything external is posted. Code tasks are standard Relaticle PRs: Pest Feature tests first, marketing routes inside the `ProvideMarkdownResponse` group, JSON-LD via `Spatie\SchemaOrg\Graph`, SystemAdmin widgets for internal metrics. A single competitor-facts data file feeds both the fact-sheet page and comparison pages so numbers never diverge.

**Tech Stack:** Laravel 12 / PHP 8.4, Filament 4 (SystemAdmin), Pest 4, spatie/schema-org, spatie/laravel-sitemap, ink blog + `/mcp/blog` (Sanctum PAT), agent-browser, `gh` CLI.

**Spec:** `docs/superpowers/specs/2026-08-13-growth-gtm-master-design.md` (reads with `docs/superpowers/specs/2026-08-11-seo-geo-strategy-design.md` for editorial/metadata standards).

## Global Constraints

- Quality gates before every commit: `vendor/bin/pint --dirty --format agent` · `vendor/bin/rector --dry-run` (apply if it suggests) · `vendor/bin/phpstan analyse` · `composer test:type-coverage` (100%) · `php artisan test --compact --filter=<relevant>`.
- All user-facing strings wrapped in `__()` (custom PHPStan rules enforce; no new phpstan.neon ignores).
- Test files only inside `tests/{Arch,PHPStan,Smoke,Feature,Browser}` — extend existing files covering the same surface before creating new ones.
- Titles: `<Base Title> - Relaticle`, base ≤60 chars, unique site-wide. Descriptions: 140–160 chars, unique, front-loaded, no unverifiable claims.
- No FAQPage/HowTo schema on new pages. No llms.txt-as-SEO claims.
- **External-communication gate**: every PR to a third-party repo, directory submission, outreach email, and social post is shown to the founder and posted only after an explicit "post". Nothing auto-sent, ever.
- Blog flag `RELATICLE_FEATURE_BLOG` stays `false` until 3+ posts are reviewed (owner decision).
- Every public product claim must be verifiable in the current codebase; every competitor fact carries a `verified:` date.
- `docs/*` is gitignored — commit plan/spec/tracking files with `git add -f` (established repo convention).
- Migrations (if any): `up()` only. Writes go through `app/Actions/*` (none expected in this plan).
- UI changes are screenshot-verified with agent-browser (light + dark) before a task is reported done.

---

## Phase A — Measurement bootstrap + distribution week-1

### Task 1: Citation-panel baseline run + tracking file

**Files:**
- Create: `docs/gtm/citation-panel.md`

**Interfaces:**
- Produces: the 10 canonical prompts (fixed wording, reused every month) and the baseline row format `| date | prompt | engine | mentioned? | cited? | linked? | notes |`.

- [ ] **Step 1: Create the tracking file** with the 10 fixed prompts from spec S2: best open source CRM · self-hosted CRM · open source Attio alternative · CRM with MCP server · connect Claude to CRM · AI agent CRM · self-hosted CRM with AI · Laravel CRM · flat-price CRM unlimited users · Twenty alternatives. Include a "Method" section: engines = ChatGPT, Claude, Perplexity; a mention = Relaticle named in the answer; a citation = relaticle.com or github.com/relaticle appears as a source; run prompts fresh (no conversation context).
- [ ] **Step 2: Run the baseline panel.** Perplexity via web (no login needed). ChatGPT + Claude via agent-browser using founder-logged-in sessions (`agent-browser open https://chat.openai.com --session gtm-panel`; ask the founder to log in once per engine, then reuse the session). If an engine is unreachable, record `n/a` — never fabricate a result.
- [ ] **Step 3: Record all 30 rows** (10 prompts × 3 engines) in the table with today's date.
- [ ] **Step 4: Commit**

```bash
git add -f docs/gtm/citation-panel.md
git commit -m "docs(gtm): citation panel baseline run"
```

### Task 2: GSC + Fathom setup (founder runbook, agent-verified)

**Files:** none (external dashboards)

- [ ] **Step 1: Hand the founder this GSC runbook** and wait for confirmation:
  1. https://search.google.com/search-console → Add property → Domain → `relaticle.com`.
  2. Verify via DNS TXT record (add at the DNS host; value shown by GSC).
  3. Sitemaps → submit `https://relaticle.com/sitemap.xml`.
- [ ] **Step 2: Hand the founder this Fathom runbook**: Fathom dashboard → the relaticle.com site → create saved filters/segments for referral hostnames `chatgpt.com`, `chat.openai.com`, `perplexity.ai`, `claude.ai`, `copilot.microsoft.com`, named "AI referrals".
- [ ] **Step 3: Verify GSC** (after founder confirms): ask founder for the property's URL-inspection result on `https://relaticle.com/help` (indexed or "discovered"). Record the indexation count for the 54 sitemap URLs in the weekly snapshot (Task 24 format).
- [ ] **Step 4: Record completion** in `docs/gtm/citation-panel.md` under a "Infrastructure log" heading (`git add -f`, `git commit -m "docs(gtm): log GSC + Fathom setup"`).

### Task 3: awesome-selfhosted submission

**Files:** none in this repo (PR to `awesome-selfhosted/awesome-selfhosted-data`)

- [ ] **Step 1: Fork + clone** `gh repo fork awesome-selfhosted/awesome-selfhosted-data --clone` into a temp dir.
- [ ] **Step 2: Read their `CONTRIBUTING.md`** and one recently-merged software file (e.g. `software/twenty.yml`) to confirm the current schema before writing ours.
- [ ] **Step 3: Create `software/relaticle.yml`** following the schema they use. Content to adapt to their exact field names:

```yaml
name: Relaticle
website_url: "https://relaticle.com"
source_code_url: "https://github.com/relaticle/relaticle"
description: "Open-source CRM with native AI agent support (30 MCP tools, human-in-the-loop approvals), REST API, custom fields and kanban boards. Self-hostable with a single Laravel server."
licenses:
  - AGPL-3.0
platforms:
  - PHP
  - Docker
tags:
  - Customer Relationship Management (CRM)
depends_3rdparty: false
```

- [ ] **Step 4: Validate locally** if they ship a validation command (their CONTRIBUTING documents it — typically `make test` or a Python script); fix any schema errors.
- [ ] **Step 5: FOUNDER GATE** — show the branch diff and PR title/body draft. Wait for "post".
- [ ] **Step 6: Open the PR** with `gh pr create`, log the URL in `docs/gtm/citation-panel.md` Infrastructure log, commit the log line (`git add -f`).

### Task 4: openalternative.co data correction

**Files:** none in this repo

- [ ] **Step 1: Locate the correction channel.** Check github.com/piotrkulpinski/openalternative for whether tool data lives in-repo (then a PR) or their DB (then the site's submit/edit form or `hello@openalternative.co`).
- [ ] **Step 2: Draft the correction**: `isSelfHosted: true`; AI-native: true; tagline → "Self-host-first, agent-native open-source CRM — AI that acts with your approval, one flat price for the whole team."; confirm license AGPL-3.0, current screenshots.
- [ ] **Step 3: FOUNDER GATE** — show the draft (PR or email). Wait for "post"/"send". If email, stage it via Gmail draft (`mcp__claude_ai_Gmail__create_draft`) for the founder to send.
- [ ] **Step 4: Log the submission** in the Infrastructure log and commit.

### Task 5: alternativeto + madewithlaravel profile refresh

**Files:** none in this repo

- [ ] **Step 1: Draft profile copy** (one short + one long description) using the S1 identity verbatim, plus a current feature list (MCP 30 tools, AI chat with approvals, custom fields, kanban, REST API, import wizard, self-host) and 3 fresh screenshots captured with agent-browser from the live seeded app (light mode, 1440px).
- [ ] **Step 2: Hand the founder the paste-ready copy + screenshot files** with per-site steps (both sites require the owner account). Wait for confirmation.
- [ ] **Step 3: Verify live** — fetch both public profiles, confirm the new copy renders; log in the Infrastructure log and commit.

### Task 5b: MCP directory submissions completion

**Files:** none in this repo

- [ ] **Step 1: Establish status of the in-flight Claude + ChatGPT directory submissions** (check the submission emails/dashboards with the founder; the MCP server itself passed readiness work in #255/#447/#451).
- [ ] **Step 2: Complete whatever the pending step is** (form fields, verification, listing copy using the S1 identity). FOUNDER GATE on any submitted text.
- [ ] **Step 3: Submit to community MCP directories** (mcpmarket.com, lobehub, and the top results for "MCP server directory" at run time) with the same listing copy. FOUNDER GATE per submission.
- [ ] **Step 4: Log all listing URLs/statuses in the Infrastructure log; commit.**

---

## Phase B — Code PRs (each task = one PR, branched from current branch)

### Task 6: Competitor-facts data file + stale-facts command

**Files:**
- Create: `resources/data/competitor-facts.php`
- Create: `app/Support/CompetitorFacts.php`
- Create: `app/Console/Commands/ReportStaleCompetitorFactsCommand.php`
- Test: `tests/Feature/Commands/ReportStaleCompetitorFactsCommandTest.php`

**Interfaces:**
- Produces: `CompetitorFacts::all(): array<string, array{name: string, license: string, stars: int, stars_verified: string, pricing: string, pricing_verified: string, stack: string, self_host: string, ai: string, verified: string}>` keyed by slug (`relaticle`, `twenty`, `espocrm`, `attio`, `hubspot`); `php artisan gtm:stale-facts` exit code 0 with a table of facts whose `verified` date is >90 days old. Consumed by Tasks 7 and 8.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Support\CompetitorFacts;

it('exposes dated facts for every competitor used on public pages', function (): void {
    $facts = CompetitorFacts::all();

    expect($facts)->toHaveKeys(['relaticle', 'twenty', 'espocrm', 'attio', 'hubspot'])
        ->and($facts['twenty']['verified'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($facts['relaticle']['pricing'])->toContain('$24');
});

it('reports stale facts older than 90 days', function (): void {
    $this->artisan('gtm:stale-facts')
        ->assertSuccessful();
});
```

- [ ] **Step 2: Run it, verify it fails** (`php artisan test --compact --filter=ReportStaleCompetitorFacts`) — class not found.
- [ ] **Step 3: Implement.** `resources/data/competitor-facts.php` returns the array; seed values from the 2026-08-13 research (Twenty: 54,876 stars, $9–19/user/mo + $50k enterprise, Node/NestJS+Redis+Postgres, AGPL+exception, cloud-first MCP; EspoCRM: GPLv3, $15–69/user cloud; Relaticle: 1,506 stars, $24/mo flat or $19 annual, Laravel single-server, AGPL, 30 MCP tools self-host-included; Attio/HubSpot: closed-source SaaS pricing tiers), each entry `verified => '2026-08-13'`. `CompetitorFacts::all()` is a final readonly class with one static method requiring + returning the file. The command lists entries where `verified` < now()-90d using `$this->table()`, comment "All facts fresh." when none (command output before processing per conventions).
- [ ] **Step 4: Run the test — PASS.** Run the quality gates.
- [ ] **Step 5: Commit** `feat(gtm): dated competitor facts source + stale-facts report command`.

### Task 7: G5 fact-sheet/press page

**Files:**
- Create: `resources/views/press.blade.php`
- Modify: `routes/web.php:51-58` (add route inside the `ProvideMarkdownResponse` group)
- Modify: `resources/views/components/footer.blade.php` (or the footer partial found in `resources/views/` — locate with `grep -rn "Privacy" resources/views/components/`) — add a "Press kit" link so the sitemap crawler discovers the page
- Test: `tests/Feature/PressPageTest.php`

**Interfaces:**
- Consumes: `CompetitorFacts::all()['relaticle']` for the facts table.
- Produces: route name `press` at `/press`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

it('renders the press page with facts and unique metadata', function (): void {
    $this->get('/press')
        ->assertOk()
        ->assertSee('AGPL-3.0')
        ->assertSee('30 MCP tools')
        ->assertSee('<title>' . e(__('Press Kit & Facts')) . ' - Relaticle</title>', false);
});

it('serves the press page as markdown', function (): void {
    $this->get('/press', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->assertHeader('content-type', 'text/markdown; charset=UTF-8');
});
```

- [ ] **Step 2: Run, verify 404 failure.**
- [ ] **Step 3: Implement the view**: sections = one-paragraph boilerplate (S1 identity verbatim), facts table (name, founded 2024, license, stack, stars + as-of date from `CompetitorFacts`, pricing, MCP tool count, links), 3 product screenshots (reuse existing homepage assets), logo download links, founder contact. All strings via `__()`. Meta description (140–160 chars, unique). No FAQPage schema; `Organization` JSON-LD only if not duplicating the homepage graph.
- [ ] **Step 4: Tests + gates pass; agent-browser screenshot light+dark.**
- [ ] **Step 5: Commit** `feat(gtm): press kit page with dated facts` and open the PR.

### Task 8: G2 comparison-page engine (/compare + /alternatives)

**Files:**
- Create: `app/Http/Controllers/ComparisonController.php` (`show`)
- Create: `app/Http/Controllers/AlternativesController.php` (`show`)
- Create: `resources/views/compare/show.blade.php`, `resources/views/alternatives/show.blade.php`
- Create: `config/comparisons.php` — declares the four launch pages and their copy keys: `compare: ['twenty', 'espocrm']`, `alternatives: ['attio', 'hubspot']`
- Modify: `routes/web.php:51-58` — `Route::get('/compare/relaticle-vs-{competitor}', ComparisonController::class)` + `Route::get('/alternatives/{competitor}', AlternativesController::class)` inside the markdown group; 404 for undeclared slugs
- Modify: footer partial — links to the four pages (crawler discovery)
- Test: `tests/Feature/ComparisonPagesTest.php`

**Interfaces:**
- Consumes: `CompetitorFacts::all()`.
- Produces: URLs `/compare/relaticle-vs-twenty`, `/compare/relaticle-vs-espocrm`, `/alternatives/attio`, `/alternatives/hubspot`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

it('renders each declared comparison page with a facts table and dates', function (string $url, string $expect): void {
    $this->get($url)
        ->assertOk()
        ->assertSee($expect)
        ->assertSee(__('Facts verified'));
})->with([
    ['/compare/relaticle-vs-twenty', 'Twenty'],
    ['/compare/relaticle-vs-espocrm', 'EspoCRM'],
    ['/alternatives/attio', 'Attio'],
    ['/alternatives/hubspot', 'HubSpot'],
]);

it('404s for undeclared competitors', function (): void {
    $this->get('/compare/relaticle-vs-salesforce')->assertNotFound();
});

it('serves comparison pages as clean markdown with the table intact', function (): void {
    $markdown = $this->get('/compare/relaticle-vs-twenty', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('Twenty')
        ->and($markdown)->not->toContain('Skip to');
});
```

- [ ] **Step 2: Run, verify failures.**
- [ ] **Step 3: Implement.** Controller resolves the competitor from `config('comparisons')`, aborts 404 otherwise, passes both fact rows to the view. View structure (GEO-first): answer-first opening paragraph (honest recommendation, when to pick which), one comparison table (license, pricing model, stars as-of, stack, AI/MCP capabilities incl. self-host parity, deployment requirements), prose sections per dimension, "Facts verified <date>" line sourced from the facts file, CTA. Alternatives pages: same engine, framed for migration intent ("leaving <X>"), plus a short migration path (CSV import wizard, API). Use `<ul>`-safe structures where the markdown converter would mangle complex tables (verified constraint from #456: `league/html-to-markdown` collapses some `<table>` layouts — keep tables simple, one per page). JSON-LD: `WebPage` + `BreadcrumbList` graph. All copy via `__()`.
- [ ] **Step 4: Tests + gates pass; screenshots light+dark; verify `Accept: text/markdown` output manually once.**
- [ ] **Step 5: Regenerate sitemap locally** (`php artisan sitemap:generate` — confirm the exact signature with `php artisan list | grep sitemap`) and assert the four URLs appear.
- [ ] **Step 6: Commit** `feat(gtm): comparison and alternatives pages driven by dated facts` and open the PR.

### Task 9: G1 homepage markdown-negotiation cleanup

**Files:**
- Modify: `resources/views/layouts/guest.blade.php` and/or `config/markdown-response.php`
- Test: `tests/Feature/MarkdownResponseTest.php` (extend — existing file, 17 lines)

**Interfaces:** none new.

- [ ] **Step 1: Reproduce locally**: `curl -s -H "Accept: text/markdown" https://relaticle.test/ | head -40` — confirm the duplicated nav links and the `= 768) mobileMenu = false"` fragment appear (they do on production; if local is clean, diff deployed layout vs this branch first).
- [ ] **Step 2: Write the failing test** (append to `MarkdownResponseTest.php`)

```php
it('serves the homepage as markdown without nav chrome or alpine fragments', function (): void {
    $markdown = $this->get('/', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->not->toContain('mobileMenu')
        ->and($markdown)->not->toContain('Skip to main content')
        ->and(substr_count($markdown, 'Pricing'))->toBeLessThanOrEqual(1);
});
```

- [ ] **Step 3: Run, verify it fails** on the current output.
- [ ] **Step 4: Diagnose + fix.** Known origin (SEO spec D6 note): the mobile-menu carries `@resize.window="if (window.innerWidth >= 768) mobileMenu = false"` — a raw `>` inside a quoted attribute that naive tag-stripping splits. The docs pages are clean because their chrome sits in `<header>/<nav>/<footer>` landmarks the configured preprocessors remove — inspect the marketing layout for nav markup *outside* those landmarks (mobile menu panel, skip-link anchor) and either wrap it in the landmarks the preprocessors strip or move the Alpine expression to a method call that contains no `>` (e.g. an `x-data` helper). Fix the layout, not the converter, unless the converter is provably at fault.
- [ ] **Step 5: Test passes; gates pass; verify the rendered homepage is visually unchanged (agent-browser screenshot light+dark, mobile menu still opens).**
- [ ] **Step 6: Commit** `fix(geo): strip marketing chrome from homepage markdown output` and open the PR.

### Task 10: G3 clone-deployment marketing-route disable

**Files:**
- Modify: `config/relaticle.php:50-57` — add `'marketing' => (bool) env('RELATICLE_FEATURE_MARKETING', true),` to `features`
- Modify: `routes/web.php:51-58` — wrap the marketing group; when disabled, `/` redirects to the app login
- Modify: `packages/Documentation/resources/content/docs/guides/` self-hosting article — document the env var
- Test: `tests/Feature/MarketingFeatureFlagTest.php`

**Interfaces:**
- Produces: env `RELATICLE_FEATURE_MARKETING` (default `true`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

it('serves marketing routes when the marketing feature is enabled', function (): void {
    config()->set('relaticle.features.marketing', true);

    $this->get('/pricing')->assertOk();
});

it('redirects marketing routes to the app when disabled for self-hosted installs', function (): void {
    config()->set('relaticle.features.marketing', false);

    $this->get('/')->assertRedirect();
    $this->get('/pricing')->assertRedirect();
});
```

- [ ] **Step 2: Run, verify the disabled case fails.**
- [ ] **Step 3: Implement.** Gate the route group registration on the config (follow how the existing `blog`/`documentation` feature flags gate routes — grep `features.` in route/provider files and mirror the established pattern; note routes are registered at boot, so gate at registration, not middleware, matching the existing flags). `/help` and `/developers` stay available (self-hosters need docs); only the marketing pages (home, pricing, contact, press, compare, alternatives, legal) are gated. Redirect target: the app login URL helper already used at `routes/web.php:44`.
- [ ] **Step 4: Tests + full marketing-page sweep pass** (`php artisan test --compact --filter="Marketing|Pricing|Press|Comparison|Home"`).
- [ ] **Step 5: Update the self-hosting guide article** (front-matter `updated:` today) with a "Disable the marketing site" section.
- [ ] **Step 6: Commit** `feat: marketing-site feature flag for self-hosted installs` and open the PR.

### Task 11: G4 funnel instrumentation (SystemAdmin)

**Files:**
- Create: `packages/SystemAdmin/src/Filament/Widgets/FunnelWidget.php`
- Modify: `packages/SystemAdmin/src/Filament/Pages/EngagementDashboard.php` (register the widget)
- Test: `tests/Feature/SystemAdmin/` — extend the existing dashboard test (locate with `grep -rln "EngagementDashboard" tests/`)

**Interfaces:**
- Consumes: `ActivationRateWidget`'s creator-source approach (`getActiveCreatorIds`) — reuse, don't reimplement.
- Produces: three stats — Organic sign-ups, Activated teams, Subscribed teams — for the selected period.

- [ ] **Step 1: Define the metrics in code comments-free logic** (SystemAdmin is PHPStan-excluded but keep types):
  - *Organic sign-up*: `User` whose earliest `team_user` pivot row for a team they do **not** own is absent OR later than 24h after `users.created_at` (invited users join another team's roster at registration; pivot `created_at` ≈ user `created_at`).
  - *Activated team*: team with ≥1 record whose creator passes the same source filter `ActivationRateWidget` already applies (mirror its query at team grain).
  - *Subscribed team*: `subscriptions` row with `stripe_status` in `['active','trialing']` (confirm column values against the existing billing code — grep `stripe_status` in `app/`).
- [ ] **Step 2: Write the failing test** (SystemAdmin tests stay minimal per project convention — render + count only):

```php
it('shows the funnel widget on the engagement dashboard', function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');

    livewire(EngagementDashboard::class)->assertOk();
});
```

  (Match the existing dashboard test's auth/guard setup exactly — copy its `actingAs` line.)
- [ ] **Step 3: Implement the widget** as a `StatsOverviewWidget` following `ActivationRateWidget`'s structure (`HasPeriodComparison`, `InteractsWithPageFilters`, sparklines optional — skip them, YAGNI).
- [ ] **Step 4: Tests + gates pass** (PHPStan skips SystemAdmin; still run the suite filter). Screenshot the dashboard via agent-browser (sysadmin panel).
- [ ] **Step 5: Commit** `feat(sysadmin): signup-to-subscription funnel widget` and open the PR.

### Task 12: Pricing-page founder-call link + D9 edge caching runbook

**Files:**
- Modify: `resources/views/pricing.blade.php` (one link under the Pro card CTA)
- Test: `tests/Feature/PricingPageTest.php` (extend)

- [ ] **Step 1: Get the scheduling URL from the founder** (Cal.com/Calendly — their choice). BLOCKED until provided; skip and return if not available.
- [ ] **Step 2: Failing test**: `->assertSee(__('Talk to the founder'))` on `/pricing`.
- [ ] **Step 3: Implement** — a single outbound link (rel noopener), `__()` copy, no embed.
- [ ] **Step 4: Tests + gates + screenshot; commit** `feat(pricing): founder call link`.
- [ ] **Step 5: Hand the founder the D9 runbook** (Laravel Cloud dashboard): enable edge caching for GET routes `/`, `/pricing`, `/press`, `/compare/*`, `/alternatives/*`, `/help/*`, `/developers/*` with a short TTL (≤1h), excluding cookies-varying auth routes. Verify after deploy: `curl -sI https://relaticle.com/ | grep -i cache` shows a public/edge cache header. Log in the Infrastructure log.

### Task 12b: S1 positioning rollout — homepage hero + GitHub repo front door

**Files:**
- Modify: `resources/views/home/index.blade.php` (hero headline/subline) and its lang strings
- Modify: `readme.md` (opening paragraph + tagline) — check actual filename case with `ls`
- Test: existing homepage test (locate with `grep -rln "get('/')" tests/Feature/` and extend its assertion for the new headline)

**Interfaces:** none — copy only.

- [ ] **Step 1: Draft the copy set** from the S1 identity: hero headline + subline, GitHub repo description (≤350 chars), README opening paragraph. All four surfaces verbatim-consistent on the identity clauses; homepage keeps the non-dev tone split (works-out-of-the-box + flat price lead, AI supporting).
- [ ] **Step 2: FOUNDER GATE on the copy set** (this is the brand voice — one review covers all surfaces).
- [ ] **Step 3: Failing test**: extend the homepage test to `assertSee` the approved headline; run, verify it fails.
- [ ] **Step 4: Implement** homepage hero (strings via `__()`; keep JSON-LD `SoftwareApplication` description in sync) + README opening. Update the SoftwareApplication/OG descriptions if they still carry the old tagline (`grep -rn "Human-First\|CRM Built for People" resources/ lang/ config/`).
- [ ] **Step 5: Tests + gates pass; agent-browser screenshot light+dark+mobile.**
- [ ] **Step 6: Update the GitHub repo description**: `gh repo edit relaticle/relaticle --description "<approved copy>"`.
- [ ] **Step 7: Commit** `feat(marketing): sharpened positioning on homepage and repo front door` and open the PR.

---

## Phase C — Content wave 1 + blog launch

Shared process for Tasks 13–17 (each post): draft via the blog MCP (`/mcp/blog`, sysadmin Sanctum PAT — token stays in MCP client config, never in files) as **draft** status → fact-check every claim against the codebase (grep/screenshots) → founder editorial review → publish only at launch (Task 18). Editorial standards from the SEO/GEO spec apply (answer-first, one table per comparison, Person author = founder, 140–160-char descriptions, dated facts).

### Task 13: Post 1 — "Self-hosting an AI-native CRM in 2026"

- [ ] **Step 1: Verify the claims list before writing**: Docker deploy path (`grep -rn "dockerfile\|docker-compose" . --include="*.yml" -l`, self-hosting guide), Laravel Cloud + Coolify instructions (existing `/developers/self-hosting` content), Ollama provider (`config/ai.php:104`), MCP available self-hosted (routes/ai.php not cloud-gated — verify), credit system behavior self-hosted.
- [ ] **Step 2: Draft** (~1,500–2,500 words): answer-first ("what you need, how long it takes"), sections per deploy target with real commands, one table (deploy option × requirements × time), honest limitations section, screenshots from a real local deploy where feasible.
- [ ] **Step 3: Stage as draft via blog MCP; re-fetch via `GetPostTool` and verify stored content matches.**
- [ ] **Step 4: FOUNDER GATE** — editorial review; revise until approved. Log approval in the post's front matter or the ops log.

### Task 14: Post 2 — "Relaticle vs Twenty: an honest comparison"

- [ ] **Step 1: Source every fact from `resources/data/competitor-facts.php`** (Task 6) — the post and `/compare/relaticle-vs-twenty` must never disagree. Add any new fact to the data file first, then cite it.
- [ ] **Step 2: Draft**: opening verdict paragraph naming when Twenty is the better choice (candor is the differentiator), one comparison table, sections: AI/MCP (cloud-first vs self-host parity), pricing model, deployment, extensibility, community. Link the live `/compare` page.
- [ ] **Step 3–4: Stage, verify, FOUNDER GATE** (as Task 13 steps 3–4).

### Task 15: Post 3 — "Let Claude Code run your sales pipeline" (launch anchor)

- [ ] **Step 1: Build the real walkthrough**: connect Claude (or Claude Code) to the production MCP server against a demo workspace; script 5 scenes — import leads, agent creates companies/opportunities with approval cards, pipeline review, task creation, a refused destructive action (shows the approval model). Capture screenshots at each step with agent-browser.
- [ ] **Step 2: Record the demo video** (use the product-demo-video skill): ≤2 min, captioned MP4, the 5 scenes above, no audio narration needed.
- [ ] **Step 3: Draft the post** around the walkthrough: every command/tool call shown verbatim, honest failure notes included, closes with the MCP setup guide link (`/developers/mcp`).
- [ ] **Step 4–5: Stage, verify, FOUNDER GATE.**

### Task 16: Post 4 — "Open-source Attio alternatives (and when to pick which)"

- [ ] **Step 1: Facts from the data file**; cover Relaticle, Twenty, EspoCRM, Frappe honestly (we are one option among several — the candor earns the citation).
- [ ] **Step 2: Draft**: answer-first ranking table by use-case (agent-native self-host / build-a-platform / SMB out-of-box / suite), one paragraph per option, migration-from-Attio section linking `/alternatives/attio`.
- [ ] **Step 3–4: Stage, verify, FOUNDER GATE.**

### Task 17: Posts 5–8 (post-launch cadence, 1–2/week)

- [ ] **Step 1:** Post 5 "Connect Claude to your CRM with MCP — the complete guide" (expand `/developers/mcp` into a narrative guide; verify OAuth flow claims against `routes/ai.php`).
- [ ] **Step 2:** Post 6 "What agent-native CRM actually means" (category definition; the four-mechanism definition from spec S1).
- [ ] **Step 3:** Post 7 "Migrating from HubSpot/Attio to a self-hosted CRM" (import wizard walkthrough with real CSVs; verify each record type's requirements against the `/help/import` category).
- [ ] **Step 4:** Post 8 "30 MCP tools for a CRM: design lessons" (engineering post; source from `routes/ai.php` + tool classes; dev.to/HN cross-post asset).
- [ ] Each: stage → verify → FOUNDER GATE → publish on the cadence calendar (ceiling 1–2/week).

### Task 18: Blog launch

- [ ] **Step 1: Preconditions check**: ≥3 posts approved (Tasks 13–15), categories seeded, RSS enabled (`config/ink.php` `features.feed`), draft preview verified.
- [ ] **Step 2: FOUNDER ACTION**: set `RELATICLE_FEATURE_BLOG=true` in Laravel Cloud env + deploy.
- [ ] **Step 3: Publish the 3 posts via MCP; re-fetch each live URL** (200, correct title/description/JSON-LD, markdown negotiation works: `curl -H "Accept: text/markdown" https://relaticle.com/blog/<slug>`).
- [ ] **Step 4: Verify sitemap regenerated with blog URLs** (scheduled daily; or run the command) and `/blog` renders in light+dark via agent-browser.
- [ ] **Step 5: Log launch date + URLs in the Infrastructure log; commit.**

---

## Phase D — The launch moment

### Task 19: Launch gate check + date pick

- [ ] **Step 1: Evaluate the gate** (spec S5): MCP directory listings (Claude/ChatGPT) live? If yes → combined launch. If week 6 (~2026-09-24) arrives first → content-anchored launch. Record the decision + date with the founder.

### Task 20: Launch asset preparation (all drafted, none posted)

- [ ] **Step 1: Show HN draft**: title options (e.g. `Show HN: Relaticle – open-source CRM your AI agents can safely write to` — ≤80 chars, no marketing tone), body = founder story (why another CRM), what's genuinely different (approval model, self-host AI parity), architecture notes, honest Twenty acknowledgment, link. Plus a prepared first-comment with technical depth.
- [ ] **Step 2: Product Hunt kit**: tagline, gallery (5 screenshots + the Task 15 video), maker comment, launch-day FAQ answers.
- [ ] **Step 3: r/selfhosted + r/laravel posts** (different angles: self-host walkthrough vs Laravel architecture), X thread (6–8 posts), dev.to cross-post of Post 8.
- [ ] **Step 4: FOUNDER GATE on every asset.** Store approved copies in `docs/gtm/launch-assets/` (`git add -f`).

### Task 21: Launch day + follow-through

- [ ] **Step 1: FOUNDER posts** Show HN (morning US Eastern, Tue–Thu), then PH/Reddit/X per the approved 48h schedule. Founder answers comments; agent monitors threads and drafts reply suggestions (never posts).
- [ ] **Step 2: Record the outcome** (HN points/comments, PH rank, star delta, signup delta, referrers) in the weekly snapshot within 72h.
- [ ] **Step 3: Next-month check**: listicle/citation lift attributable to launch (Task 23's monthly run).

---

## Phase E — Standing rituals

### Task 22: Weekly outbound ritual (S6) — recurring

- [ ] **Step 1 (agent, weekly):** Mine 5 candidates: `gh api repos/relaticle/relaticle/stargazers --paginate` (recent, with company profiles), activated teams (founder pastes from FunnelWidget until prod access exists), Discord joiners, comparison-page referrers (Fathom).
- [ ] **Step 2 (agent):** Draft 5 personalized touches referencing the real signal; stage as Gmail drafts (`mcp__claude_ai_Gmail__create_draft`).
- [ ] **Step 3 (founder):** Review, edit, send. Log contact/signal/date/outcome in the ClickUp outreach ledger (create a "GTM Outreach" list on first run).

### Task 23: Monthly citation panel + listicle sweep — recurring

- [ ] **Step 1:** Re-run the 10-prompt panel exactly as Task 1; append rows to `docs/gtm/citation-panel.md`.
- [ ] **Step 2:** Re-run the SERP sweep ("best open source CRM <year>", "attio alternative open source"); log new listicles, send inclusion requests (FOUNDER GATE) with the `/press` fact sheet.
- [ ] **Step 3:** Run `php artisan gtm:stale-facts`; refresh any stale competitor facts (new `verified:` dates) in a small PR.
- [ ] **Step 4:** One-page progress report against the 90-day targets (stars/listicles/citations/paying); commit to `docs/gtm/` (`git add -f`).

### Task 24: Weekly ops snapshot — recurring

- [ ] **Step 1 (agent):** Assemble: stars + 30d delta (`gh api`), signups/activation (founder pastes or prod access), Fathom visitors + AI referrals, GSC clicks/impressions (founder pastes or API later), outreach ledger status, content queue state.
- [ ] **Step 2:** Post as the weekly 30-min review agenda; capture the founder's one prioritized next action.
- [ ] **Step 3 (optional, once):** Offer to automate steps 1–2 as a scheduled routine (schedule skill) — founder opt-in.
- [ ] **Step 4 (once, weeks 1–2):** Apply the S2 re-baseline rule: after two clean post-Turnstile weeks, record the honest organic signup baseline in the spec's baseline section (small docs commit).

---

## Execution notes

- Task order within phases is the delivery order; Phases A and B can run in parallel. Task 6 blocks 7, 8, 14, 16. Task 12b (approved copy) should land before Tasks 4/5 submit directory copy where feasible — otherwise submit with the spec's S1 identity verbatim. Task 15 blocks 18–20. Task 18 blocks 21.
- Each Phase-B task is its own PR from a `feat/`- or `fix/`-prefixed branch; run the full pre-commit gate list every time.
- Anything blocked on founder input (Task 12 URL, logins, env flips) is skipped-and-returned-to, never worked around.
