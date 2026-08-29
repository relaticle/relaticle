# Marketing Navigation Restructure + Product Pages (`/ai`, `/self-hosted`)

**Date:** 2026-08-19
**Status:** Approved (design), pending implementation plan
**Branch:** `ManukMinasyan/header-footer-nav-options`

## Goal

Move the marketing site to a mega-menu header (two dropdowns), a four-column
footer driven by a single navigation source of truth, and ship two new
product pages: `/ai` (Rela, the AI assistant) and `/self-hosted`. World-class
execution: instant, fluid, no jank ("Telegram-level feel").

## Decisions locked (founder-approved 2026-08-19)

| Decision | Choice |
|---|---|
| Header structure | Option C: `Product ▾`, `Resources ▾`, Pricing, Discord ↗, GitHub ★ |
| New pages now | `/ai` and `/self-hosted` only; `/features` deferred |
| Assistant name | **Rela** (matches `chat.assistant_name`, PR #506) |
| GitHub star badge | Yes, count cached server-side |
| Discord | Stays top-level in the header |
| PR strategy | Two stacked PRs, one spec |
| Comparison URLs | Keep both namespaces (`/compare/relaticle-vs-{x}`, `/alternatives/{x}`); no hub yet |
| Footer comparison anchors | Keep descriptive anchors ("Relaticle vs Twenty") under a `Compare` heading |
| `/ai` demo | Unique to Relaticle; extend the existing `hero-agent-*` mockup system; never copy competitor visuals |

## Evidence base

Research: `~/.claude/research/marketing-nav-header-footer-2026-08.md` (NN/g,
W3C, Google quotes; live extractions from attio.com, twenty.com, supabase.com,
posthog.com, cal.com, sentry.io, metabase.com, 2026-08-19).

Key inputs:
- Convergent header shape at Attio/Twenty/PostHog/Sentry: `Product ▾ + Resources ▾ + Pricing + extras`. Item count is not a constraint (NN/g: the 7-item rule is a myth).
- Every sampled peer gives its AI a sub-brand and a dedicated page (Ask Attio `/platform/ask`, Seer `/product/seer/`, Cal.ai `/ai`, Metabase AI `/features/metabase-ai`).
- Attio's Ask page (extracted 2026-08-19) leads on trust: "Real information, not AI invention", "Your permissions, enforced", "Intelligent suggestions. Human decisions." Trust messaging alone is table stakes; our differentiation is *showing* the real approval flow, open source, MCP, and self-hosted AI.
- Twenty's homepage (extracted 2026-08-19) has zero AI/agent/MCP links.
- Metabase runs a flat `COMPARISONS` footer column with 5 vs-links; no sampled site ships a compare hub.
- NN/g menu guidelines: click-activated submenus (not hover), caret icons, `aria-current` ("the single most common mistake" is omitting current location).

## Hard constraint: sitemap crawl

`app/Console/Commands/GenerateSitemapCommand.php` builds `sitemap.xml` by
crawling from `app.url`. Any marketing page not reachable through rendered
HTML links silently drops out of the sitemap. The footer must keep flat,
non-JS links to every comparison/alternatives page, and a Feature test must
assert reachability for every slug in `config/comparisons.php` plus the two
new pages.

## Architecture

### Navigation source of truth

`App\Support\MarketingNavigation`: one class returning typed nav structures
consumed by all three surfaces (desktop header, mobile menu, footer). No
surface hardcodes its own item list again. Feature gates
(`App\Features\Documentation`, `App\Features\Blog`) evaluate inside it.
Comparison links derive from `config('comparisons.compare')` and
`config('comparisons.alternatives')` so new slugs appear in the footer
automatically.

Shape (illustrative, final API decided in the plan):

- `header()`: list of items; an item is a link or a group with children
- `footer()`: list of columns, each `label` + flat links
- `mobile()`: same tree as `header()`, rendered as an accordion

### Header (Option C)

- `Product ▾` (single column): Rela (`/ai`), Features (`/#features`), Self-hosted (`/self-hosted`), MCP & API (`/developers`)
- `Resources ▾` (two-column panel): **Resources**: Help center, Developers, Blog | **Compare**: Relaticle vs Twenty, Relaticle vs EspoCRM, Attio alternative, HubSpot alternative
- Pricing
- Discord ↗ (external, `rel="noopener noreferrer"`: currently missing)
- GitHub ★ with star count (see badge section)
- Sign In, Start for free (unchanged)

Behavior: click-activated dropdowns (Alpine), caret icon rotating on open,
Escape closes, click-outside closes, focus trapped sensibly (focus returns to
trigger on close). No hover-open. Panels overlay content; never full-screen
on desktop.

### Mobile menu

Same tree from `MarketingNavigation::mobile()`, rendered inside the existing
full-screen menu as accordion groups (Product, Resources expand in place),
followed by Pricing and Discord. Contact leaves the mobile menu and lives in
the footer Company column, same as desktop. Both surfaces render the same
source tree, so the current desktop/mobile drift (Contact mobile-only,
Discord desktop-only) disappears by construction.

### Footer

Brand block (logo, tagline, social icons) plus four columns:

| Product | Resources | Compare | Company |
|---|---|---|---|
| Features | Help center | Relaticle vs Twenty | Contact |
| Rela | Developers | Relaticle vs EspoCRM | GitHub |
| Pricing | Blog | Attio alternative | Privacy Policy |
| Self-hosted | Press kit | HubSpot alternative | Terms of Service |

Bottom bar: copyright, theme switcher, `llms.txt` link (Attio precedent).
All footer links are plain anchors (crawlable, no JS dependency).

### GitHub star badge

Server-side cached count (daily refresh via scheduled command in
`bootstrap/app.php` `withSchedule()`, or cache-remember with 24h TTL on
render; decided in the plan). Displays compact form ("1.5k"). Falls back to
plain "GitHub" link when the count is unavailable. Never a client-side API
call.

### Accessibility

- Each `<nav>` landmark gets a unique `aria-label` (Main, Mobile, Footer)
- Footer link groups live inside a labelled `<nav>`
- `aria-current="page"` on the active link in every surface
- Dropdown triggers: `aria-expanded`, `aria-controls`; items reachable by keyboard
- All user-facing strings wrapped in `__()` (i18n PHPStan rules apply)

## Page: `/ai`: Meet Rela

Route: `Route::get('/ai', ...)` name `ai` in the marketing middleware group
(`ProvideMarkdownResponse`, `AddVaryAcceptHeader`). Feature-gate: none (page
is static marketing; it describes the product truthfully whether or not the
visitor's plan includes every model).

Sections:

1. **Hero.** H1 "Meet Rela" + one-line: the AI assistant inside Relaticle
   that does real CRM work with your approval. CTA pair (Start for free /
   see it work). Title tag: "Rela: the AI assistant in Relaticle CRM".
2. **The approval flow, shown not told.** Animated demo extending the
   existing `resources/views/home/partials/hero-agent-*` mockup system
   (mirrors the real app 1:1 per UI rules): user asks → Rela streams a
   proposal card → approve → records appear. This is the centerpiece.
3. **What Rela does** (each claim verified in code, see table below):
   create/update/delete companies, people, opportunities, tasks, notes;
   batch operations in one approval; knows your custom fields automatically;
   answers product questions from the docs; guides you to the right page
   instead of refusing.
4. **Control.** Nothing writes without your approval; batches are
   all-or-nothing; model choice (Claude, GPT); transparent credits.
5. **MCP.** Use Relaticle from Claude or ChatGPT via the built-in MCP
   server. Link to `/developers` guide.
6. **Self-hosted AI.** On your own server, bring your own key or run local
   models via Ollama. Links to `/self-hosted`.
7. **FAQ** in direct Q&A pairs (GEO: structured Q&A is extracted by AI
   engines). FAQPage schema via spatie/schema-org (same pattern as compare
   pages).
8. **CTA.**

### Verified claim inventory (`/ai`)

| Claim | Evidence |
|---|---|
| Assistant named Rela | `packages/Chat/config/chat.php` `assistant_name` (PR #506) |
| Create/update/delete + batch tools | `packages/Chat/src/Tools/` Base{Write,Read}* + per-entity dirs; delete `ids[]` batch → one PendingAction |
| Custom fields automatic | bridge services `packages/Chat/src/Services/Tools/` (schema describer, label→id translation) |
| Approval cards / PendingAction | chat proposal flow, `pending_actions` |
| Docs Q&A | `SearchDocsTool` (PR #500) |
| Guide-to-page | `GuideToPageTool` + `DestinationResolver` |
| Model choice | model catalog in `chat.php` (Sonnet 4.6, Opus 4.7, GPT 5.5/5.4; plan-gated) |
| Credits | `tool_call_credit_bonus`, credit system in `packages/Chat` |
| MCP server | `routes/ai.php`, `app/Mcp/` |
| Self-hosted custom models | `SELF_HOSTED_AI_URL/KEY/MODELS` in `chat.php`; Ollama documented in self-hosting guide |

Honesty rule: the catalog models are cloud (`self_hosted => false`); the page
must not imply hosted models ship with self-hosted installs. Self-hosted AI =
your key / your local models.

## Page: `/self-hosted`: Own your CRM

Route: `Route::get('/self-hosted', ...)` name `selfHosted`, same middleware
group.

Sections:

1. **Hero.** Positioning: your data, your server, the whole CRM. Real
   quick-start snippet, verbatim from the working guide:
   `curl -o compose.yml https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml`
   → `docker compose up -d`.
2. **Why self-host.** Data ownership, AGPL-3.0 license (verified: `LICENSE`),
   no per-seat pricing on your own hardware.
3. **Quick start** distilled from
   `packages/Documentation/resources/content/docs/guides/self-hosting.md`,
   deep-linking to `/developers/self-hosting` for the full guide.
4. **AI on your server.** Rela works self-hosted with your own API key or
   local models via Ollama (`OLLAMA_BASE_URL`, `SELF_HOSTED_AI_*`). Honest
   framing: cloud model catalog is Relaticle Cloud; self-hosted brings its own.
5. **Cloud vs self-hosted table.** Honest in both directions (managed
   updates/backups vs control/ownership).
6. **Community proof.** GitHub stars (same cached count), Discord,
   contributing link.
7. **FAQ** + FAQPage schema: license, updates (pull new image), what AI
   needs, where data lives.
8. **CTA pair:** deploy self-hosted (GitHub) / try Cloud (register).

## Motion & feel ("Telegram-level")

- Motion communicates state, never decorates: dropdown open/close 150-200ms
  ease-out, transform+opacity only (compositor-friendly, no layout thrash)
- Demo animations run on scroll-into-view, pause off-screen, respect
  `prefers-reduced-motion` (existing hero already does this: reuse the pattern)
- No layout shift: star badge and demo containers have reserved dimensions
- Instant perceived nav: no artificial delays, no hover-intent timers
- Use design tokens from `resources/css/theme.css`; no ad-hoc values

## SEO / GEO

- `/ai` title carries "AI assistant" + "CRM"; Rela is the brand on the page,
  never the sole keyword (name is crowded: unrelated Rela apps exist)
- `/self-hosted` targets "self-hosted CRM" phrasing
- Both pages: meta description, OG tags, FAQPage + BreadcrumbList schema
  (reuse compare-page spatie/schema-org pattern)
- Both pages added to `HelpController::llmsTxt` marketing section
- Both reachable from footer (flat anchors) → sitemap crawl picks them up

## Testing

- Feature tests: 200 + key content for `/ai` and `/self-hosted`; nav renders
  on marketing pages; comparison slugs from config all present in footer HTML
- **Sitemap-reachability test:** every slug in `config('comparisons.*')` and
  both new pages appear as hrefs in rendered marketing HTML (guards the
  crawl-based sitemap)
- Smoke: routes added to the existing smoke suite convention
- Browser verification (not automated): agent-browser click-through of
  dropdowns (click-open, Escape, outside-click), mobile accordion, both
  themes, per UI rules: evidence screenshots before "done"
- i18n: PHPStan rules pass with no new ignores

## Delivery: two stacked PRs

**PR-1: nav foundation (target: merge before launch 2026-08-21):**
`MarketingNavigation` class, footer restructure (4 columns + llms.txt),
mobile/desktop drift fix, a11y (landmarks, `aria-current`), full `__()`,
`rel` fix on header Discord link, sitemap-reachability test. Header keeps
its current flat shape in PR-1. PR-1's footer Product column ships without
the Rela and Self-hosted links (those pages do not exist yet: no 404s);
PR-2 adds them to the nav source.

**PR-2: pages + header C (after PR #506 merges):**
`/ai`, `/self-hosted`, mega-menu header, mobile accordion, GitHub star
badge. Stacked on PR-1.

Rationale: PR-1 is near-zero risk and improves launch day even if PR-2
slips; the header flip and new pages land as one reviewable, verifiable unit.

## Out of scope

- `/features` page (follow-up; `Product ▾` keeps the `/#features` anchor)
- `/compare` hub (only when the comparison set outgrows a flat column, ~8+)
- Krayin/Frappe comparison pages (separate growth card)
- Any change to app-panel navigation
- Homepage hero changes
