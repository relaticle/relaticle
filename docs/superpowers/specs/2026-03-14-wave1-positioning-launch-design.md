# Wave 1: Calm Agent-Native CRM -- Positioning & Launch

**Date:** 2026-03-14
**Branch:** feat/agent-native-positioning
**Status:** Design approved, pending implementation plan

---

## Goal

Polish the Relaticle landing page, README, and GitHub presence for maximum launch impact, then execute a coordinated multi-channel launch announcing the pivot to "The Calm Agent-Native CRM." Everything must be ready before a simultaneous launch across all channels.

## Approach

**Polish-first.** Get everything right, then launch everything simultaneously. No phased rollout -- this is a major launch.

**Sequential pipeline (minus code quality):**
1. Phase 1: Positioning Polish -- landing page copy, README, schema markup, GitHub repo update
2. Phase 2: Launch Prep & Execute -- polish launch content, then simultaneous coordinated launch

All work happens on `feat/agent-native-positioning`. Branch merging and deploy are manual steps handled separately.

---

## Audience

**Primary:** Developer/sysadmin self-hosters -- people who deploy their own CRM and want to connect AI agents to it. Technical, care about MCP, API docs, Docker, AGPL.

**Secondary:** AI-forward startup founders -- building on top of agents, want a CRM that agents can operate natively. This audience overlaps heavily with primary.

**Rationale:** Self-hosted product requires technical ability. Launch channels (HN, Reddit, PH) are developer-heavy. MCP is still a developer concept. Early adopters of self-hosted tools are always technical.

---

## Section 1: Landing Page Copy Polish

### Hero (approved direction)

**Keep current headline:** "The Open-Source CRM Built for AI Agents"

This is the agent-forward positioning (similar to Attio's approach). Research across 7 comparable products (Laravel, Basecamp, Ghost, Plausible, Cal.com, n8n, Attio) confirmed this is the right position on the AI messaging spectrum -- bold, differentiated, and accurate since the MCP server is real.

**Sharpen subheading:** Current "MCP-native. Self-hosted. 20 tools for any AI to operate your CRM." needs tightening -- "operate your CRM" is vague. Subheading should be more concrete about what agents can do.

### Copy changes

| Element | Current | Action |
|---------|---------|--------|
| Hero headline | "The Open-Source CRM Built for AI Agents" | Keep |
| Hero subheading | "MCP-native. Self-hosted. 20 tools for any AI to operate your CRM." | Sharpen -- more concrete |
| Stats bar test count | "900+ tests" in CTA card | Update to "1,100+ tests" |
| AI Agent hero tab | Reuses Companies screenshot (placeholder) | Needs real MCP terminal mockup or screenshot |
| Features section header | "Built for humans. Accessible to AI." | Evaluate -- sharpen if needed |
| Community section heading | "Built in the Open" (current) | Evaluate for agent-native voice consistency |
| Community stats bar | "900+" Automated Tests | Update to "1,100+" |
| CTA card | "Give your AI agents a CRM they can actually use" | Keep -- it's strong |

### Copy approach

- Run copy-editing skill for 7-sweep pass on all landing page text
- Run page-cro skill for conversion analysis
- Fix stale numbers and placeholder content
- Keep visual design as-is -- this is a copy pass, not a redesign

### Number usage rule

- "22 field types" for credibility in technical contexts (stats bar, README, comparison tables)
- Capabilities for persuasion in marketing copy ("entity relationships, conditional visibility, per-field encryption" beats "22 types" alone)
- "1,100+ tests" everywhere the old "900+" appeared
- **Global search:** Run `grep -r "900" --include="*.blade.php" --include="*.md"` to catch all stale references across the codebase, not just known locations

---

## Section 2: FAQ Section

**New Blade partial** `resources/views/home/partials/faq.blade.php`, included in `home/index.blade.php` between `community` and `start-building`:

```blade
@include('home.partials.community')
@include('home.partials.faq')        {{-- NEW --}}
@include('home.partials.start-building')
```

5-6 Q&As that serve triple duty:
1. **Objection handling** -- address "is it production-ready?", "can I use my own AI provider?"
2. **AI discoverability** -- FAQPage JSON-LD schema is the highest-value schema type for AI citation
3. **SEO** -- FAQ rich results in Google

### Suggested Q&As (to be refined during copy polish)

1. **Is Relaticle production-ready?** -- 1,100+ tests, 5-layer authorization, 56+ MCP tests, used in production
2. **What AI agents can I connect?** -- Any agent that speaks MCP -- Claude, GPT, open-source, custom. Bring your own AI provider.
3. **What's MCP?** -- Model Context Protocol. An open standard for AI agents to interact with tools and data. Relaticle's MCP server gives agents 20 tools to work with your CRM data.
4. **How is this different from HubSpot or Salesforce?** -- Self-hosted (own your data), open-source (AGPL-3.0), 20 MCP tools (vs HubSpot's 9), no per-seat pricing.
5. **How do I deploy it?** -- Docker Compose, Laravel Forge, or any PHP hosting. Self-hosted means your data never leaves your server.
6. **Can I customize the data model?** -- 22 custom field types including entity relationships, conditional visibility, and per-field encryption. No migrations needed.

---

## Section 3: Schema Markup & AI Discoverability

### 3.1 spatie/laravel-markdown-response

**New dependency:** `spatie/laravel-markdown-response`

Apply `ProvideMarkdownResponse` middleware to the **public web routes only** (homepage, docs, legal pages). Do NOT apply to:
- API routes (`routes/api.php`) -- already return JSON
- Filament panel routes -- authenticated admin UI
- MCP routes (`routes/ai.php`) -- MCP protocol

Register in `bootstrap/app.php` as a route-group middleware on the public web group, or apply directly in `routes/web.php` on the relevant route group.

When AI bots (GPTBot, ClaudeBot, PerplexityBot) crawl the landing page, they get clean markdown instead of raw HTML.

Detection methods:
- `Accept: text/markdown` headers
- `.md` URL suffix
- Known AI bot user agents

This makes the website itself agent-native -- strong narrative for launch: "Even our landing page speaks markdown to AI bots."

### 3.2 JSON-LD Structured Data

The homepage already uses `spatie/schema-org` with the fluent `Graph` builder in `home/index.blade.php`. Existing schemas: `SoftwareApplication`, `Organization`, `Website`.

**Review and update existing schemas:**
- `SoftwareApplication` -- verify description and featureList are current (test count, tool count)
- `Organization` -- add Discord to `sameAs` array (currently only GitHub)

**Add new schema using the same `Graph` builder pattern:**

| Schema | Purpose |
|--------|---------|
| `FAQPage` | Links to FAQ section Q&As (see Section 2). Add to the existing `$schema` Graph in `index.blade.php`. |

### 3.3 BreadcrumbList on Documentation Pages

Add `BreadcrumbList` JSON-LD to the existing documentation module pages. Low effort since the docs infrastructure already exists with clear hierarchy (Getting Started, Import, Developer, API, MCP).

### 3.4 robots.txt Verification

Verify that GPTBot, PerplexityBot, ClaudeBot are allowed in `robots.txt`. The current `robots.txt` already exists -- verify and update if needed.

---

## Section 4: README Update

Align README with agent-native positioning:

- **Headline:** "The Open-Source CRM Built for AI Agents" (match landing page)
- **Description:** Lead with MCP server, REST API, custom fields -- what makes it agent-native
- **Badges:** MCP tools count, test count (1,100+), PHP 8.4, Laravel 12
- **Feature list:** Reorder to lead with agent infrastructure
- **Comparison table:** Update vs HubSpot (9 MCP tools vs 20), add Attio, update Salesforce
- **Screenshots:** Ensure they match current UI

---

## Section 5: GitHub Repo Update

- **Description:** "The Open-Source CRM Built for AI Agents -- MCP-native, self-hosted, 22 custom field types"
- **Topics:** Add `mcp`, `ai-agents`, `agent-native`, `mcp-server`. Keep `crm`, `open-source`, `laravel`, `filament`.
- **Social preview image:** New image reflecting agent-native positioning. Could use the MCP flow diagram from the features section or a custom designed preview.

---

## Section 6: Launch Content Polish

### Approach

Polish existing drafts in `docs/launch/`, not rewrite from scratch. The 5 drafts cover the right channels and angles.

### Updates across all drafts

- Test count: 900+ -> 1,100+
- Sharpen "calm agent-native" narrative
- Add `spatie/laravel-markdown-response` as talking point ("even our website speaks markdown to AI bots")
- Ensure consistent messaging with finalized landing page copy

### Per-channel focus

| Channel | File | Key Polish |
|---------|------|------------|
| Product Hunt | `docs/launch/product-hunt.md` | Update tagline, add MCP comparison to HubSpot, refresh screenshots list |
| Twitter/X | `docs/launch/twitter-thread.md` | Tighten hook, add concrete numbers, add "website is agent-native too" angle |
| Reddit (3 posts) | `docs/launch/reddit-posts.md` | r/selfhosted: Docker angle; r/opensource: AGPL story; r/laravel: technical stack |
| Hacker News | `docs/launch/show-hn.md` | Keep technical, lead with MCP server capability, avoid marketing language |
| GitHub Release | `docs/launch/github-release.md` | Full changelog, update repo description/topics/social preview |

### Launch day coordination

All channels launch same day (polish-first approach means everything is ready):
- Morning: Product Hunt launch
- Mid-day: Twitter/X thread
- Afternoon: Show HN
- Spread across day: Reddit (r/selfhosted, r/opensource, r/laravel)
- GitHub release published

Respond to every comment within 30 minutes on launch day.

### Metrics to track post-launch

- Signups (app.relaticle.com/register)
- GitHub stars delta
- Discord joins delta
- Product Hunt ranking
- Traffic sources (UTM tags per channel: `?utm_source=producthunt`, `?utm_source=hackernews`, etc.)

---

## What's NOT In Scope

| Item | Reason |
|------|--------|
| Landing page visual redesign | Copy pass only, visuals are solid |
| Full CRO A/B testing | Defer until post-launch with real traffic data |
| Wave 2 (Calm Agent Protocol) | Separate spec, Layer 2 design |
| Wave 2 (Conversation Interface) | Separate spec, Layer 3 design |
| Branch merging & deploy | Manual steps, not part of this spec |
| Dev.to / Hashnode article | Lower priority, can be post-launch follow-up |
| New landing page sections beyond FAQ | Keep scope tight |

---

## What Already Exists

| Item | Status | Reuse? |
|------|--------|--------|
| Agent-native hero positioning | Implemented on this branch | Yes -- sharpen copy, keep design |
| Bento grid features section | Implemented with MCP flow diagram | Yes -- update copy only |
| Community section | Implemented | Yes -- update copy for agent-native voice |
| Start-building CTA | Implemented | Yes -- evaluate copy strength |
| Launch content drafts (5 files) | Written 2026-02-23 | Yes -- polish, don't rewrite |
| Agent-native positioning design doc | Written 2026-02-23 | Superseded by this spec |
| CRO launch design doc | Written 2026-02-23 | Superseded by this spec |
| robots.txt | Exists with some AI bot rules | Verify and update |
| Schema.org markup on homepage | `SoftwareApplication`, `Organization`, `Website` via `spatie/schema-org` Graph builder | Review/update existing, add `FAQPage` |

---

## Success Criteria

- Landing page copy passes copy-editing 7-sweep framework
- Schema markup validates in Google Rich Results Test
- `spatie/laravel-markdown-response` middleware active on public routes
- FAQ section live with FAQPage JSON-LD
- README aligned with landing page messaging
- GitHub repo description, topics, and social preview updated
- All 5 launch content drafts polished and ready to post
- Consistent messaging across all touchpoints

---

## Skills Used During Implementation

| Task | Skill |
|------|-------|
| Landing page copy | `marketing-skills:page-cro`, `marketing-skills:copywriting`, `marketing-skills:copy-editing` |
| FAQ content | `marketing-skills:copywriting` |
| Schema markup | `marketing-skills:schema-markup` |
| AI discoverability | `marketing-skills:ai-seo` |
| Launch content | `marketing-skills:social-content`, `marketing-skills:launch-strategy` |
| README | `marketing-skills:copywriting` |
