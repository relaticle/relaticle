# Relaticle Growth/GTM Master Spec — Design

Authored 2026-08-13 from same-day primary research: a live production re-audit of
relaticle.com (all W0/W2 hygiene verified fixed), a deep competitive teardown of
Twenty's marketing and sales engine, and current platform baselines from the
sysadmin dashboards. This is the growth program's single spec: positioning,
metrics, content, distribution, launch, sales motion, and the small code
workstreams they need. It builds on — and does not duplicate — the technical
SEO/GEO program in `2026-08-11-seo-geo-strategy-design.md`, which remains the
authority for W0–W5 mechanics (site hygiene, help centre, markdown negotiation,
editorial standards).

Research artifacts (compressed, off-repo): `~/.claude/research/
opensource-crm-seo-geo-landscape-2026-08.md` (audit + 2026-08-13 delta) and
`~/.claude/research/twenty-gtm-marketing-sales-2026-08.md` (Twenty teardown).

## Goal

Turn Relaticle's verified product truths into paying workspaces, on processes
that compound without campaign spend. Success in 90 days (by ~2026-11-15):

- GitHub stars 1,506 → **3,000** (north-star leading indicator).
- **5+ listicle/directory inclusions** (currently 0 listicles).
- Cited in **≥3 of 10** monthly citation-panel prompts across ChatGPT/Claude/Perplexity.
- **First 3–5 paying workspaces** (outcome metric; baseline 0 — billing shipped
  days ago).

Success in 12 months: own the self-host-first agent-native CRM position the way
Twenty owns "open source CRM" — present in every category listicle, cited by AI
engines for agent+CRM prompts, with a working signup → activation → subscription
funnel measured end to end.

## Where we are (baselines, 2026-08-13)

**Platform**: 4,382 total users. Signups ran ~60–110/week May–Aug; disposable-email
blocking + Turnstile shipped ~2026-08-10 (#444), and the latest week dropped to
~36 — treat pre-Turnstile volume as spam-suspect and **re-baseline after two
clean weeks** before trusting any signup target. Every signup auto-creates a
workspace (Jetstream personal team); the dashboard's "New Teams" series counts
*additional* teams only and is not a funnel signal. In-app activation metric
(ActivationRateWidget): **activated = user created ≥1 record**. Paying
workspaces: **0** (Free + Pro $24/mo or $19/mo annual, unlimited users, AI
credit packs; Enterprise is internal-only per the 2026-08-12 owner decision).

**Site**: SEO health ~75/100, up from 60. Corpus 15 → 54 sitemap URLs; /help
(7 categories, ~37 articles) and /developers live; llms.txt live; clean docs
markdown negotiation; app panels noindexed; metadata standards enforced by
tests. Residuals: homepage `Accept: text/markdown` output still leaks nav
blocks + an Alpine fragment (G1); no lastmod on marketing/legal/category URLs;
no edge caching (D9); **/blog still 404 — authoring MCP shipped, zero posts**.

**Authority**: 1,506 stars (+58/30d) vs Twenty's 54,876. Absent from every
"best open source CRM 2026" listicle checked and from awesome-selfhosted (no
submission ever attempted). openalternative.co: #11/13 on the Attio-alternatives
page with wrong data (`isSelfHosted: false`, `isAiNative` unset, stale generic
tagline). alternativeto: zero engagement. One independent editorial mention
total (blog.brightcoding.dev, 2025-12). GitHub topics fixed 2026-08-13 (mcp,
ai-agents added). Fathom analytics live; GSC status unverified.

## The competitive read (Twenty teardown, 2026-08-13)

Twenty runs an 11-person, ~$5.5M company with **no sales team, no marketing
hires, and no blog**. Its growth engine, ranked by evidence: (1) GitHub as the
funnel — README-as-landing-page, weekly public releases, AI-agent-friendly repo;
(2) launch moments — Launch HN 2023 (415 pts), HN 2024 (378 pts), PH 2025 (926
upvotes), PH 2.0 2026 (#2 of day); (3) third-party SEO — listicles, benchmarks,
and 23 implementation partners write the content that ranks; (4) partner channel
+ enterprise logos feeding a $50k/yr tier. Cloud pricing $9–19/user/mo plus
metered workflow credits. (Ignore aggregator claims of a $43M raise — that is a
different company also named Twenty.)

**Twenty 2.0 (2026-04-21) contests our wedge**: first-party MCP server, AI
agents, whole-company repositioning to "designed for AI" (+9k stars in the two
months after). "We have an MCP server" is now table stakes.

**Twenty's exposed flanks** (each one a positioning clause below): AI/MCP is
cloud-first with a muddy self-host story; the 2.0 "build your Enterprise CRM"
platform pivot abandons teams that want a CRM that just works; self-hosting
means Node/NestJS + Redis + workers vs our one-server Laravel deploy; per-seat
pricing vs our flat price; no content engine; no Attio comparison page; weak
community chat (7k Discord / 55k stars); empty app marketplace.

Adjacent monetization refs: EspoCRM ($15–69/user cloud + paid extensions,
long-tail SEO machine), Krayin (free core + paid extensions + Webkul services),
Frappe CRM ($5/mo unlimited-user hosting, anti-per-seat positioning).

## S1 — Positioning & narrative

**Identity**: *the self-host-first, agent-native open-source CRM — AI that acts
with your approval, works out of the box, one flat price for the whole team.*

| Clause | The proof we own | The flank it attacks |
|---|---|---|
| Self-host-first agent-native | MCP + AI chat work identically self-hosted (incl. Ollama support); 30 first-party MCP tools | Twenty: "every **Cloud** workspace ships with a native MCP server" |
| AI with your approval | Human-in-the-loop PendingAction approvals, no-dead-ends escort, agent-writable custom fields | Undifferentiated agent UX elsewhere |
| Works out of the box | Seeded onboarding, help centre, no build step to a usable CRM | Twenty 2.0's "build your Enterprise CRM" platform pivot |
| Flat price, whole team | $24/mo unlimited users + transparent credit packs | Twenty $9–19/user, EspoCRM $15–69/user |

Rules:

- **"Agent-native" is defined by depth, never by MCP's existence.** Every public
  claim must name a mechanism (approvals, tool count, custom-field write path,
  escort). Claims must be verifiable in the current codebase (workflow rule).
- The identity lands, verbatim-consistent, on: homepage hero, GitHub repo
  description + README opening, every directory tagline (openalternative,
  alternativeto, madewithlaravel), the pricing page, and every comparison page.
  Entity consistency is itself a GEO signal — one story everywhere.
- Tone split: developer surfaces lead with agent-native + self-host; non-dev
  surfaces lead with "works out of the box, flat price" and let AI support.
- Laravel/PHP is a channel and a deploy-ease proof point, not the identity.

## S2 — Metrics, baselines & instrumentation

- **North star (leading)**: GitHub stars. **Outcome**: paying workspaces.
  **Guardrails**: organic signups/week (post-Turnstile definition) and
  activation rate (user created ≥1 record — the existing widget definition,
  pinned here; workspace-level "activated team" = team with ≥1 member-created
  record, to be added in G4).
- **90-day targets**: 3,000 stars · 5+ listicle inclusions · ≥3/10 citation
  prompts · 3–5 paying workspaces.
- **Re-baseline rule**: no signup-derived target is set until two full
  post-Turnstile weeks establish the honest organic rate.
- **Instrumentation to ship** (part of G4 + ops):
  1. Verify GSC property + submit sitemap; check indexation of the 54 URLs.
  2. Fathom: segment referrers chatgpt.com / perplexity.ai / claude.ai /
     copilot.microsoft.com as "AI referrals".
  3. Monthly citation panel: 10 fixed prompts (best open source CRM ·
     self-hosted CRM · open source Attio alternative · CRM with MCP server ·
     connect Claude to CRM · AI agent CRM · self-hosted CRM with AI · Laravel
     CRM · flat-price CRM unlimited users · Twenty alternatives), run across
     ChatGPT/Claude/Perplexity; record mention/citation/link per prompt.
     Agent-run, results appended to a tracking file in this repo.
  4. Sysadmin funnel view: organic signup (exclude invited members) →
     activated team → subscribed (G4).
- Weekly snapshot (stars, signups, activation, AI referrals, paying) prepared
  for the weekly ops loop (S8).

## S3 — Content engine (extends W1; the blog)

The single biggest pending asset. Infrastructure is done (ink blog + MCP
authoring endpoint + sysadmin tokens, all verified); zero posts exist. Owner
rule stands: **launch on content** — flip `RELATICLE_FEATURE_BLOG` only when
3+ posts are reviewed and staged.

Wave 1, reordered to lead where Twenty structurally can't follow:

1. **Self-hosting an AI-native CRM in 2026** (Docker, Laravel Cloud, Coolify) —
   attacks the cloud-first flank; r/selfhosted asset.
2. **Relaticle vs Twenty: an honest comparison** — table-first, dated facts,
   both products' strengths stated plainly. Nobody has written this.
3. **Let Claude Code run your sales pipeline** — the launch anchor (S5);
   engineering walkthrough + demo video.
4. **Open-source Attio alternatives (and when to pick which)** — the
   first-party page Twenty never built; feeds the same query the directories
   own today.
5. Connect Claude to your CRM with MCP — the complete guide.
6. What "agent-native CRM" actually means — category definition.
7. Migrating from HubSpot/Attio to a self-hosted CRM.
8. 30 MCP tools for a CRM: design lessons.

Process: agent-drafted via the blog MCP → founder review (every claim verified
in-product) → publish → re-fetch and verify rendered output (the SEO spec's
publish-verification loop). Cadence ceiling 1–2/week. Editorial and metadata
standards are inherited from the SEO/GEO spec verbatim — answer-first, one
comparison table per comparison post, real author with Person schema, dated
facts, 140–160-char unique descriptions.

## S4 — Distribution & authority (W4, executed)

Week-1 checklist (all free, none attempted before):

- [x] GitHub topics: mcp, ai-agents (done 2026-08-13).
- [ ] **awesome-selfhosted PR** — repo qualifies (age, activity, AGPL); follow
  their data-repo contribution format; Twenty/EspoCRM/Krayin/Monica all listed.
- [ ] **openalternative.co correction** — `isSelfHosted: true`, `isAiNative:
  true`, tagline updated to the S1 identity.
- [ ] alternativeto + madewithlaravel profile refresh (screenshots, S1 tagline,
  current feature list).

Continuous program:

- **Fact-sheet page** (G5): one URL with everything a listicle author needs —
  facts table (license, stack, stars, pricing, MCP tool count), screenshots,
  logo pack, boilerplate paragraph. Makes inclusion a 10-minute job for them.
- **Listicle outreach**: tracked ledger (ClickUp); one inclusion request per
  ranked listicle (crm.org, webkul, nutshell, founding.dev,
  opensourcealternatives.to, techradar, dench, techesperto) — fact sheet
  attached, no pitch. Monthly SERP re-sweep for new listicles.
- **MCP directories**: finish the in-flight Claude + ChatGPT submissions;
  add mcpmarket/lobehub-class directories.
- **Release-driven posting**: meaningful releases → r/selfhosted, r/laravel,
  dev.to, X. Substance only; no launch-frequency inflation.
- Per the external-communication rule: every outbound draft (PRs to
  awesome-selfhosted included) is shown and approved before posting.

## S5 — The launch (the concentrated moment inside the flywheel)

- **Gate**: MCP directory listings live **or** week 6 from spec approval,
  whichever comes first. If directories stall, launch content-anchored.
- **Package**: blog live with ≥3 posts · post #3 (Claude Code runs your
  pipeline) as anchor + a short demo video · Show HN with prepared narrative
  (founder story, self-host-first agent-native angle, honest Twenty
  acknowledgment — HN rewards candor) · Product Hunt · r/selfhosted + r/laravel
  · X thread. All assets drafted and approved before the date; posted within
  the same 48h window.
- **No-repeat rule**: max one big coordinated moment per quarter, and each
  needs a genuinely new story (Twenty's cold re-posts flopped at 3–4 points).
- Success is measured in stars + signups that week and listicle/citation lift
  the following month — logged against S2.

## S6 — Sales motion (light, founder-led)

- **ICP**: Laravel agencies and dev shops (also the contributor funnel);
  self-host-minded SMBs; AI-forward small sales teams already using
  Claude/ChatGPT.
- **Weekly ritual (~1h founder time)**: 5 outbound touches. Sources, in order
  of signal strength: activated teams (in-product signal) · GitHub
  stargazers/forkers with company profiles · Discord joiners · comparison-page
  visitors (once G2 ships). Agent drafts personalized notes from the signal;
  founder approves and sends — **nothing auto-sent, ever**.
- **Pricing page**: add a "talk to the founder" scheduling link (single link,
  no calendly-embed project).
- **Ledger**: outreach tracked in ClickUp (contact, signal, date, outcome);
  reviewed in the weekly loop.
- **Explicit non-goals now**: no partner program until ~10 paying references;
  no outbound tooling/sequences; no hiring implied.

## S7 — Code workstreams (small PRs, each independently shippable)

- **G1 — homepage markdown-negotiation cleanup**: `Accept: text/markdown` on
  `/` still emits duplicated header/footer nav and a leaked Alpine fragment
  (`= 768) mobileMenu = false"`); docs/help output is clean. Diagnose why the
  configured preprocessors miss the marketing layout (the mobile-menu `>`
  inside a quoted attr is the known trigger — see SEO spec D6 note); fix in
  `config/markdown-response.php` preprocessors or the layout markup; pin with
  a test asserting no nav/Alpine content in `/` markdown output.
- **G2 — comparison-page engine**: `/compare/{a}-vs-{b}` and
  `/alternatives/{x}` in the marketing app. Facts live in a versioned data
  file (per-competitor: license, stars + as-of date, pricing + as-of date,
  stack, AI/MCP capabilities, self-host requirements) rendered into
  table-first pages — a stale comparison is a liability, so every fact carries
  a `verified:` date and a scheduled command (not a CI-blocking test — no
  wall-clock time bombs in the suite) reports facts older than 90 days into
  the weekly ops snapshot. Launch set: vs Twenty, vs EspoCRM, alternatives/attio,
  alternatives/hubspot. JSON-LD + markdown negotiation via the existing
  plumbing. Blog post #2 and the vs-Twenty page share one fact source.
- **G3 — clone-deployment marketing-route disable** (SEO spec D10): config
  default that serves marketing routes only for the configured canonical host;
  self-hosted installs get app + docs, not our homepage. Protects against
  duplicate-content noise (glazoncrm.space-class clones) as self-hosting grows.
- **G4 — funnel instrumentation**: sysadmin dashboard additions — organic vs
  invited signup split; activated-team metric (team with ≥1 member-created
  record); funnel widget organic signup → activated team → subscribed; keeps
  the existing user-level activation widget untouched.
- **G5 — fact-sheet/press page**: static marketing page per S4; content
  sourced from the same facts file as G2 so numbers never diverge.
- **Ops (not code)**: D9 edge caching for marketing routes at the Laravel
  Cloud/CDN layer; GSC verification.

All PRs follow the standing quality gates (pint, rector, phpstan, type
coverage, tests) and the write-path/action conventions.

## S8 — Cadence & governance

- **Weekly ops loop (30 min founder time)**: agent prepares the S2 metrics
  snapshot, outreach drafts, and content queue; founder decides. Output: sent
  touches, approved posts, one prioritized next action.
- **Monthly**: citation panel run + one-page progress report against the
  90-day targets; SERP re-sweep; directory data check.
- **Living doc**: this spec is updated on evidence (like the SEO/GEO spec's
  delta sections), not rewritten. Baselines re-stamped when the post-Turnstile
  rate is established.
- **Anti-goals (carried + new)**: no paid ads · no mass AI-generated content ·
  no per-seat pricing experiments · no head-term chasing ("best CRM") · no
  partner program pre-10-references · no auto-sent outreach · no second
  coordinated launch in the same quarter · llms.txt stays an agent index, not
  an SEO play.

## Owner decisions (2026-08-13, settled)

- Program shape: **flywheel-first spine** with the launch embedded as a
  milestone and outbound as a weekly ritual (options A+C+B-lite; B-as-primary
  and C-as-primary rejected — conversion work multiplies near-zero traffic,
  and a launch-bet leaves nothing compounding on a miss).
- Positioning: the S1 sharpened wedge (option a), simplicity tone for non-dev
  buyers, Laravel as channel.
- North star stars / outcome paying workspaces (option a with b tracked).
- Launch: combined content+directories moment if directories land by week 6,
  else content-anchored (option c, fallback a).
- Capacity: founder + agents, a few hours/week — every process above is
  designed agent-executable with founder approval gates (option a).
- Sales: self-serve + light founder outbound (option b).
- Baselines: 4,382 users / 0 paying accepted as the starting line; signup
  targets deferred to the re-baseline rule.

## Delivery order

1. **Week 1**: S4 week-1 checklist (awesome-selfhosted PR, directory fixes) ·
   S2 instrumentation items 1–2 (GSC, Fathom segment) · first citation-panel
   run (baseline) · start S6 weekly ritual.
2. **Weeks 1–3**: S3 posts 1–3 drafted and reviewed → blog launches · G1 ·
   G5 fact sheet → listicle outreach begins.
3. **Weeks 3–6**: G2 comparison engine + launch set · post 4 · S5 launch when
   gated conditions met · G3, G4.
4. **Continuous**: S6 ritual · S8 loops · remaining wave-1 posts · monthly
   panel.

## Alternatives considered

- **Revenue-first sprint** (conversion surfaces + outbound as the program):
  rejected as primary — 0 paying and low honest traffic mean conversion work
  multiplies a near-zero base; kept as the S6 ritual so learning accumulates.
- **Launch-centric bet** (everything staged for one moment): rejected as
  primary — high variance, Twenty's own later launches flopped; kept as the
  gated S5 milestone inside the flywheel.
- **Star-race vs Twenty on "open source CRM"**: rejected (36x lead decides
  listicle ordering; inclusion, not ordering, is our fight this year).
- **Partner channel now**: rejected until ~10 paying references exist.
- **Paid tooling (Ahrefs-class) now**: deferred — corpus and GSC first.
