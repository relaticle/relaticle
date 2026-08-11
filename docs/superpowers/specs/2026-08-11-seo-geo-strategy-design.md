# Relaticle SEO/GEO Strategy — Design

Audited 2026-08-11 against the live site (relaticle.com), the open-source CRM
competitive landscape, and current Google/GEO primary guidance; all codebase claims
re-verified against this repo the same day. This is the single spec for the whole
program: strategy plus the technical design of every code workstream (W0-W2). The
implementation plan lives in `docs/superpowers/plans/`.

## Goal

Make Relaticle the answer — in Google and in AI engines (ChatGPT, Claude,
Perplexity, AI Overviews) — for the queries its product truth can win, on a
process that compounds without campaign spend. Success in 12 months:

- Own the **agent-native CRM** query space (MCP + CRM, connect Claude/agents to CRM).
- Page-one presence for the self-hosted/open-source CRM comparison long tail.
- Cited by name in AI answers for "open source CRM with AI agents" class prompts.
- Content engine (blog + help) shipping on a product-coupled cadence, not campaigns.

## Where we are (audit, 2026-08-11)

**SEO health: ~60/100.** Technical foundation is decent; the corpus is the problem —
15 indexable URLs, no blog, no help centre. You cannot rank or be cited for content
that does not exist.

What is already right:

- Clean 301s (www→apex, http→https), HTTPS, canonicals, OG/Twitter cards complete.
- robots.txt explicitly allows GPTBot/ClaudeBot/PerplexityBot/Google-Extended.
- Rich homepage JSON-LD: SoftwareApplication (featureList, AGPL license), Organization
  (sameAs → GitHub, Discord), WebSite, FAQPage.
- Markdown content negotiation is live (`ProvideMarkdownResponse`) — ahead of most
  of the market — but the output is polluted (see defects).
- TTFB ~350ms, total ~590ms homepage. No CWV red flags visible (PSI quota blocked
  full measurement; re-run before/after W0).

Defects found (all reproduced live):

| # | Defect | Evidence |
|---|---|---|
| D1 | `/documentation/quickstart` (indexed in Google) 301s to `/docs/quickstart` which **404s** | curl chain 301→404 |
| D2 | `app.relaticle.com` login/register are **indexed** (no noindex, robots allows all) | `site:relaticle.com` shows both |
| D3 | Sitemap contains `/login`, `/register`, `/discord` (redirects/utility URLs); no `<lastmod>` anywhere | sitemap.xml |
| D4 | Docs article H1 renders literal markdown: `<h1># MCP Server</h1>` with mangled permalink id | /docs/mcp HTML |
| D5 | Docs sub-page meta descriptions duplicate the title ("Getting Started - Relaticle Documentation") | /docs/* HTML |
| D6 | Markdown-for-agents output includes nav junk and leaked Alpine attrs (`= 768) mobileMenu = false"`) | `Accept: text/markdown` on /docs/mcp |
| D7 | Pricing page ~280 words, no schema; docs index ~200 words | word counts |
| D8 | Homepage images 0/3 have alt text | HTML audit |
| D9 | Marketing pages sent with `cache-control: no-cache, private` — no edge/page caching | response headers |
| D10 | Clone deployments serve our marketing site verbatim (e.g. glazoncrm.space, no canonical) — AGPL self-hosts create duplicate-content noise | live clone |
| D11 | FAQPage schema targets a rich result Google removed (May 2026) — **no action**: zero cost, no penalty, some AI engines still parse it; the visible FAQ content is what matters | schema-types guidance |

## The market

- **Twenty** owns "open source CRM" (45.5k stars; in every listicle; "spiritual
  Attio successor" framing). Head-on assault on that term is a multi-year star race —
  not the wedge.
- Listicles and directories are the actual SERP owners for category terms
  (crm.org, webkul, nutshell, openalternative.co, alternativeto) — and they are what
  AI engines cite for "best X" prompts. Relaticle is on openalternative (12th on the
  Attio-alternatives page) and alternativeto, **absent from awesome-selfhosted** and
  from every major "best open source CRM 2026" listicle checked.
- **The "CRM + MCP / connect Claude to CRM" space is nascent and weakly held** —
  currently answered by HubSpot/Salesforce/Attio feature blogs and third-party
  middleware guides. No open-source player owns it. Relaticle's first-party,
  production-grade MCP server (30 tools) is the strongest product truth in this space.
- Laravel niche: "Laravel CRM" is held by Krayin (MIT, SME framing) — beatable, and
  the Laravel dev audience is exactly the self-host ICP.
- GitHub (1.5k stars) is the primary brand asset; dev.to/openalternative/madewithlaravel
  provide the current mention footprint.

## Strategy

### 1. One engine, two games

Google SEO and GEO share ~90% of the work: crawlable server-rendered HTML,
answer-first structure, entities kept consistent, brand mentions. Divergences that
matter: AI citations skew to **fresh** (<30d = ~3.2x), **structured** (H2/H3 +
bullets ≈ +40%), **tabular**, **statistic-citing** content, and to
directories/listicles; Google skews to E-E-A-T depth and links. One content engine,
authored once, serves both. Zero-click is the reality (60-69% of Google searches);
the KPI is *being the cited answer* — AI referrals convert ~10x better than organic,
so low volume is acceptable, invisibility is not.

### 2. Win the wedge, not the war

Priority of query spaces:

1. **Agent-native CRM (create the category)** — "CRM MCP server", "connect Claude to
   CRM", "AI agent CRM", "let agents manage CRM data". Product truth strongest,
   competition thinnest, and the buyer who asks an agent gets an answer sourced from
   whoever wrote the canonical material. This is where "top of top" is achievable in
   months, not years.
2. **Self-hosted / open-source CRM long tail** — honest comparison pages
   (vs Twenty, EspoCRM, SuiteCRM, Monica, Krayin; vs Attio/Folk/HubSpot for the
   "leave SaaS" migration intent), deployment guides (Docker, Laravel Cloud, Coolify),
   migration guides. Tables everywhere — AI engines lift tables.
3. **Laravel/PHP CRM** — the underpriced niche matching both the contributor funnel
   and the self-host ICP.
4. **CRM jobs-to-be-done long tail** — owned by the help centre, not the blog.

Anti-goal: chasing "best CRM" / "CRM software" head terms. That budget is better
spent making listicle authors include us (W4).

### 3. Docs-as-code freshness is the moat

The future-proof mechanism is process: content versioned with the product, so every
feature PR can carry its help article and changelog entry. Freshness, accuracy, and
coverage then compound at near-zero marginal cost — this is what competitors on
Zendesk/Notion-backed help cannot copy structurally. (Proven pattern: maxforms
`packages/docs` engine + content conventions.)

### 4. Agent experience (AX) is both plumbing and story

- Fix markdown content negotiation output (D6) — clean article markdown, no chrome.
  This is the mechanism that actually feeds agents (~85% token reduction); Google
  explicitly says llms.txt-style files are *not* needed for AI search.
- Ship a minimal, accurate llms.txt as an **agent discovery index for /docs (and
  /help when live) only** — justified as agent UX + the Chrome "Agentic Browsing"
  Lighthouse checkbox, never sold as ranking gains.
- MCP directory listings (Claude/ChatGPT — submission already in flight) are
  distribution, and "even our website is agent-legible" is a marketing story only
  we can tell honestly.

### 5. The open-source flywheel is the off-page engine

stars → listicle inclusion → brand mentions → AI citations → developer traffic →
stars. Brand-search volume is the single strongest AI-citation predictor (r≈0.334).
Feed the flywheel deliberately (W4) instead of buying links.

## Workstreams

### W0 — Hygiene (1 PR, this week) — technical design (verified 2026-08-11)

- **D1 redirect map**: `routes/web.php:75` blanket-301s `/documentation/{slug?}` →
  `/docs/{slug}`, but legacy slugs don't match current types (`quickstart` →
  404). Replace with an explicit slug map (`quickstart` → `getting-started`;
  unknown → `/docs`). Valid types are the keys of
  `config/documentation.php` `documents`: getting-started, import, developer,
  self-hosting, mcp, api.
- **D2 app-panel noindex**: app panel domain comes from
  `config('app.app_panel_domain')` (`AppPanelProvider:102`). Add an
  `X-Robots-Tag: noindex` middleware to the app panel (and sysadmin panel) —
  meta-tag-only or robots-disallow alone leaves already-known URLs indexed.
  Marketing domain stays untouched.
- **D3 sitemap**: `GenerateSitemapCommand` uses
  `SitemapGenerator::create(config('app.url'))` (crawl-based; scheduled daily in
  `bootstrap/app.php:115`). Add a `shouldCrawl`/URL filter excluding
  `/login`, `/register`, `/discord`, `/dashboard`; emit `<lastmod>` (docs pages
  from markdown file mtime; blog handled by `BlogSitemapGenerator` when flag on).
  Existing test: `tests/Feature/Commands/GenerateSitemapCommandTest.php`.
- **D4 docs H1 bug**: `config/markdown.php` `heading_permalink`
  (`symbol: '#'`, `insert: before`, `min_heading_level: 1`) +
  `add_anchors_to_headings` + `render_anchors_as_links: false` interact to render
  a literal `#` in heading text and a mangled slug id on `/docs/*`
  (`<h1 id="a-idmcp-server-href...">`). Reproduce locally first, then fix the
  pipeline config. Constraint: `DocumentData::extractTableOfContents()` regexes
  `<h2.*><a.*id="..."` — the TOC must keep working (its test must cover this).
  This renderer is shared with blog posts, so the fix benefits W1 too.
- **D5 docs meta descriptions**: per-type `description` already exists in
  `config/documentation.php` but the docs views emit the title as description.
  Pass `DocumentData->description` through (`packages/Documentation`
  views/controller).
- **D6 markdown-for-agents pollution**: `config/markdown-response.php`
  `preprocessors` only runs `RemoveScriptsAndStylesPreprocessor`. The package
  ships (unused) `RemoveNavigationPreprocessor`, `RemoveHeaderPreprocessor`,
  `RemoveFooterPreprocessor` — add them; verify `Accept: text/markdown` output is
  clean article markdown (current output leaks nav links and Alpine attrs).
- **D7 pricing depth**: `resources/views/pricing.blade.php` (~280 words). Add a
  pricing FAQ + plan-comparison content and a `Product`/`Offer` JSON-LD graph
  (same inline `Spatie\SchemaOrg\Graph` pattern as
  `resources/views/home/index.blade.php`). Existing test:
  `tests/Feature/PricingPageTest.php`.
- **D8 alt text**: `resources/views/home/partials/hero.blade.php:136,150,164`.
- **D9 caching**: an ops/deploy-layer task, not code — every marketing response
  sets session/XSRF cookies, so an app-layer `Cache-Control: public` would risk
  caches storing cookied responses. Configure edge caching for the marketing
  routes at the Laravel Cloud/CDN layer instead. Re-run PSI after W0 lands.
- **D10 clone deployments** (optional, separate PR, product decision): config
  default that disables marketing routes for non-relaticle.com installs.

### W1 — Blog launch + agent authoring pipeline (Phase 2 PR) — technical design (verified 2026-08-11)

Ink v2.3.1 already ships the full MCP surface (13 tools incl. preview URLs);
`InkServiceProvider` loads `routes/mcp.php` only when `config('ink.features.mcp')`
is true (defaults: path `/mcp/blog`, middleware `auth:sanctum`). What Relaticle
must add:

1. **Enable the endpoint**: `config/ink.php` `features.mcp => true`, relying on
   ink's defaults (`/mcp/blog`, `auth:sanctum`) — no custom `mcp` block.
   `laravel/mcp` ^0.9.1 is installed. The CRM MCP server (`routes/ai.php`,
   OAuth/Passport) is untouched — the blog MCP is a separate Sanctum-PAT
   endpoint, exactly the FilaForms-proven pattern.
2. **Policies outside the panel**: ink tools authorize via
   `Gate::forUser($caller)->authorize(...)`. `Gate::guessPolicyNamesUsing`
   (`AppServiceProvider:222`) only maps `Relaticle\SystemAdmin\Policies\*` when
   the *current Filament panel* is sysadmin — MCP requests aren't panel requests,
   so `PostPolicy`/`CategoryPolicy` (typed `SystemAdministrator`,
   SuperAdministrator-only) must be registered explicitly with `Gate::policy()`.
   Side benefit: `User`-token callers are denied by the type-hint — blog MCP is
   sysadmin-only by construction.
3. **Author resolution**: `config('ink.author_model')` is `User::class`; ink's
   default resolver returns null for a `SystemAdministrator` caller and the tool
   errors. Configure `Ink::resolveAuthorUsing()` in
   `AppServiceProvider::configureBlog()`: match `User` by the sysadmin's email;
   fail loudly (no silent fallback author).
4. **Token issuance UI**: `SystemAdministrator` already uses `HasApiTokens`; add
   an API-tokens relation manager to the existing `SystemAdministrators` resource
   (`packages/SystemAdmin/.../Resources/SystemAdministrators`), mirroring
   FilaForms' `ApiTokensRelationManager`: create (name, show plaintext once),
   list, revoke. Sanctum PATs authenticate via token lookup regardless of
   `sanctum.guard = ['web']`. SystemAdmin is PHPStan-excluded, so the
   Eloquent-write-outside-action rule doesn't block `createToken()` here.
5. **Launch**: set `RELATICLE_FEATURE_BLOG=true` in production
   (`config/relaticle.php:56` → Pennant `Blog` feature → ink `public_routes`).
   Checklist: categories seeded, wave-1 posts staged as drafts via MCP, sitemap
   regenerates with blog URLs (`GenerateSitemapCommand` gates on the flag), RSS
   feed live (`features.feed = true`), draft preview + sysadmin edit link works
   (`Ink::resolvePreviewEditUrlUsing` already configured).

Editorial standards (enforced in review, not tooling): answer in the first
paragraph; every claim sourced; one table per comparison post; author = real
person (founder) with Person schema + about page; 1-2 posts/week ceiling
(Google's Authenticity Update rewards lower frequency + higher quality; pure
AI-generated content without human oversight is penalized territory).

Wave-1 posts (agent-native cluster anchors + flywheel bait):

1. Connect Claude to your CRM with MCP — the complete guide
2. What "agent-native CRM" means (category definition post)
3. Relaticle vs Twenty: an honest comparison (table-first)
4. Self-hosting a CRM in 2026: Docker, Laravel Cloud, Coolify
5. 30 MCP tools for a CRM: design lessons from building them
6. Migrating from HubSpot/Attio to a self-hosted CRM
7. Why AGPL for a CRM (data ownership argument)
8. Let Claude Code run your sales pipeline (walkthrough)

Engineering posts (5, 8) double as HN/dev.to/Reddit distribution assets.

### W2 — /help customer help centre (Phase 3 PR) — technical design

Port the maxforms docs-engine pattern (max-forms PRs #476/#481: in-repo markdown,
front-matter, cached manifest, hub/category/article, section-level search index,
Article + BreadcrumbList JSON-LD, link-integrity tests, content-conventions
README) to **relaticle.com/help** — served directly by this app, no edge worker.

- **Surfaces**: two, per the landscape research — end-user `/help` (new) and
  developer `/docs` (stays; its URLs are indexed and the Scribe-generated API
  reference lives there — API/MCP is a product pillar, so the developer surface
  keeps its own home; maxforms' single-prefix choice was driven by a
  Cloudflare-zone constraint Relaticle doesn't have).
- **Engine placement**: evolve `packages/Documentation` (`Relaticle\Documentation`)
  into the shared engine rather than adding a second parallel markdown engine:
  front-matter articles under `resources/content/help/{category}/{slug}.md`,
  `HelpRepository`-style cached manifest (content-hash busted), readonly page/
  category value objects, category `_index.md` metadata. Existing `/docs` types
  keep their URLs and migrate onto the same rendering path (structurally fixing
  D4/D5's class of bug). Current `DocumentationService` (config-listed files,
  regex TOC) is absorbed/retired in the same change — no dual old/new paths.
- **Views**: hub (category cards), category (article list + body), article
  (breadcrumbs, TOC, related-articles footer, prev/next) — `docs::`-style
  namespaced views following the existing marketing layout (`x-guest-layout`).
- **SEO/GEO plumbing**: `Article` + `BreadcrumbList` JSON-LD via the installed
  `spatie/schema-org`; front-matter `title`/`description`/`updated` drive metas
  and sitemap `lastmod`; `.md` variants come free from the existing
  `ProvideMarkdownResponse` middleware (post-D6 fix); a small `/llms.txt` route
  generated from the manifest indexes `/help` + `/docs` (agent-discovery only —
  see anti-goals); `search-index.json` + client-side palette (section-level
  records, maxforms v2 contract).
- **Tests**: link-integrity Pest test (every `related:` entry, internal link, and
  image asset resolves against the manifest); route/meta/JSON-LD feature tests in
  `tests/Feature/`; arch coverage for the package namespace
  (`tests/Arch/ConventionsTest.php` requires conscious coverage for package
  namespaces; service layer readonly per ArchTest conventions).
- **Content wave 1 (~30 articles, 7 lifecycle categories)**: Getting started ·
  Records (companies/people/opportunities) · Custom fields & views ·
  Tasks/notes/pipeline · AI chat · Import, API & MCP · Workspace, team & billing.
  Titles in the user's vocabulary (what they'd type into a search box),
  answer-first bodies, real screenshots via agent-browser, never documenting
  flag-gated/unreleased behavior (maxforms content conventions adopted verbatim).

### W3 — Comparison & programmatic surfaces (after W1/W2)

`/compare/<x>-vs-<y>` and `/alternatives/<x>` pages — honest, table-first,
maintained (stale comparisons are a liability). Start: vs Twenty, vs EspoCRM,
vs Attio, vs HubSpot-free. Each page cites verifiable facts (stars, licenses,
pricing) with dates. No thin programmatic mass-generation — quality gates apply.

### W4 — Distribution & brand mentions (continuous, no code)

- PR to awesome-selfhosted (currently absent; Twenty/EspoCRM/Krayin/Monica listed).
- Outreach to every "best open source CRM" listicle found ranking (crm.org, webkul,
  nutshell, founding.dev, opensourcealternatives.to, techradar) — inclusion request
  with a fact sheet, not a pitch.
- Keep openalternative/alternativeto/madewithlaravel profiles complete and current.
- Release-driven launches: each meaningful release → HN Show HN / r/selfhosted /
  dev.to post / Product Hunt for majors. Lower frequency, real substance.
- MCP directories (Claude, ChatGPT) — finish the in-flight submissions.
- GitHub repo as landing page: README already strong; keep topics/description
  keyword-accurate ("mcp", "ai-agents" topics are missing from the repo topics).

### W5 — Measurement & governance (continuous)

- GSC (clicks/impressions/queries) + analytics segment for AI referrers
  (chatgpt.com, perplexity.ai, claude.ai, copilot).
- Monthly citation panel: ~10 canonical prompts ("best open source CRM",
  "self-hosted CRM with AI agents", "connect Claude to a CRM", …) run across
  ChatGPT/Claude/Perplexity; track mention + citation + link.
- Brand-query volume and GitHub stars as leading indicators.
- Freshness discipline: decaying posts get updated, not duplicated; content
  review on each minor release; no unreviewed AI-generated publishing.

## What we deliberately do NOT do

- No llms.txt as an SEO play (Google: explicitly unnecessary; zero correlation at
  300k-domain scale) — dev-docs agent index only.
- No FAQ/HowTo schema chasing (rich results removed).
- No subdomain help centre (subdirectory wins — landscape consensus).
- No mass AI-generated content, no 50-page programmatic bursts (quality gates;
  Authenticity Update).
- No paid SEO tooling until the corpus exists to measure.

## Delivery order

1. **PR-1: W0 hygiene** — immediate, unblocks everything, measurable in GSC in weeks.
2. **PR-2: W1 blog + MCP tokens** — the authoring pipeline is the prerequisite
   for every content workstream.
3. **PR-3: W2 /help engine** — largest build; starts once W1 ships.
4. **W3/W4** — continuous after W1; W4 outreach can start the day W0 lands.

## Alternatives considered

- **Compete head-on for "open source CRM"** — rejected as the primary play: Twenty's
  star lead (45.5k vs 1.5k) decides listicle placement; the wedge (agent-native) is
  winnable now and rises with the market instead of fighting it.
- **Hosted docs/help platform (Mintlify/GitBook/Zendesk)** — rejected: $65-450/mo,
  weakest SEO surfaces in the landscape research, content drifts from the product,
  and we already have a proven in-repo engine pattern to port.
- **llms.txt everywhere / "AI files" strategy** — rejected on primary-source
  evidence (Google AI-optimization guide, Mueller, SE Ranking data); content
  negotiation (already live, needs D6 fix) is the mechanism that actually works.
- **Aggressive programmatic SEO (integrations × use-cases matrix)** — deferred:
  thin-content risk before the brand has authority; revisit after W1-W3 establish
  the corpus and GSC shows appetite.
