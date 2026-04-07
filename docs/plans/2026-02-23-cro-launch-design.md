# CRO-First Agent-Native Launch Design

**Goal:** Polish the Relaticle landing page for conversion, add schema markup for AI discoverability, then execute a coordinated multi-channel launch announcing the agent-native CRM positioning.

**Approach:** CRO-First Launch -- optimize the conversion destination before driving traffic to it.

**Timeline:** 10 days across 3 phases.

---

## Phase A: Page CRO & Copy Polish (Days 1-3)

### A1. Page CRO Analysis

Run page-cro analysis against 7 dimensions:

1. **Value Proposition Clarity** -- Can a visitor understand "agent-native open-source CRM" in 5 seconds?
2. **Headline Effectiveness** -- Does "Built for AI Agents" communicate core value?
3. **CTA Placement & Copy** -- Is "Start for free" strong enough? Visible without scrolling?
4. **Visual Hierarchy** -- Can someone scanning get the main message?
5. **Trust Signals** -- Where are proof points (900+ tests, 20 MCP tools)?
6. **Objection Handling** -- Does the page address "is it production-ready?" and "will it scale?"
7. **Friction Points** -- Is the path from landing to signup clear?

**Output:** Quick wins, high-impact changes, test ideas, copy alternatives.

### A2. Copy Rewrites

Using copywriting skill with product-marketing-context.md:

**Features section header:**
- Current: "Everything you need to manage relationships" / "A comprehensive suite of tools designed to streamline your client management workflow"
- Rewrite to: agent-native positioning, benefit-focused, specific

**Feature card descriptions:**
- Apply "so what" test to each of the 10 features
- Connect features to outcomes, not just capabilities
- Use customer language from product-marketing-context.md

**Bottom CTA (start-building section):**
- Current: "Your CRM, Your Rules" / "Self-hosted. Agent-native. Full control over your data and your AI."
- Evaluate if this is strong enough or needs strengthening

**Community section:**
- Current: "Collaborate and Grow Together"
- Evaluate for consistency with agent-native voice

**Trust signals near CTAs:**
- Add concrete proof points: "20 MCP tools", "22 custom field types", "900+ tests"
- Position near primary CTA buttons

### A3. Copy-Editing Sweeps

Run 7-sweep copy-editing pass on all landing page copy:

1. **Clarity** -- No jargon, no sentences doing too much
2. **Voice & Tone** -- Consistent calm, direct, developer-friendly voice
3. **So What** -- Every feature connects to a benefit
4. **Prove It** -- Claims substantiated with numbers or evidence
5. **Specificity** -- "20 MCP tools" not "comprehensive API"
6. **Heightened Emotion** -- Pain of vendor lock-in, relief of data ownership
7. **Zero Risk** -- "No credit card", "Deploy in 5 minutes" near CTAs

---

## Phase B: Schema Markup & AI Discoverability (Day 3)

### B1. Structured Data Implementation

Add JSON-LD schema to the homepage:

- **SoftwareApplication** -- name, applicationCategory (BusinessApplication), operatingSystem, offers (Free), description
- **Organization** -- name, url, logo, sameAs (GitHub, Discord)
- **FAQPage** -- if FAQ section added (high AI-citation value)
- **BreadcrumbList** -- on documentation pages

### B2. AI SEO Quick Checks

- Verify robots.txt allows GPTBot, PerplexityBot, ClaudeBot
- Ensure first paragraph of homepage contains clear product definition
- Check heading structure matches query patterns ("open-source CRM", "CRM for AI agents")

**Rationale:** Schema markup gives 30-40% higher AI visibility. Critical for appearing in ChatGPT, Perplexity, and Google AI Overviews.

---

## Phase C: Launch Content & Execution (Days 4-10)

### C1. Merge & Deploy (Day 4)

- Merge `feat/agent-native-positioning` into `feat/rest-api-mcp-server`
- Merge `feat/rest-api-mcp-server` into `main`
- Deploy to production (app.relaticle.com)
- Verify landing page live with all changes

### C2. Launch Content Creation (Days 5-7)

#### Product Hunt Listing
- **Tagline:** ~60 chars, clear value prop
- **Description:** What it is, who it's for, what makes it different
- **Screenshots:** 4-5 showing MCP tools, custom fields, dashboard, API docs
- **Maker story:** First comment explaining why we built this
- **Gallery:** Demo video or GIF if available

#### Twitter/X Thread
- 8-10 tweet thread format
- Hook: The agent-native CRM pivot announcement
- Body: What's new (MCP server, REST API, custom fields), why it matters
- Proof: Numbers, screenshots, comparisons
- CTA: Try it free / Star on GitHub

#### Reddit Posts (3 different angles)
- **r/selfhosted:** Self-hosting angle, Docker deployment, data ownership
- **r/opensource:** AGPL-3.0, community, contributions welcome, vs proprietary CRMs
- **r/laravel:** Technical stack (Laravel 12, Filament 5, PHP 8.4), architecture, MCP integration

#### Hacker News
- **Show HN:** Technical, concise, what's novel (MCP server for CRM, 20 tools, agent-native)
- Focus on the technical achievement, not marketing

#### GitHub Release
- Release notes with full changelog
- Update repo description and topics
- Pin release discussion

### C3. Launch Execution (Days 8-10)

| Day | Actions |
|-----|---------|
| 8 (Tuesday) | Product Hunt launch, Twitter thread, respond to all PH comments |
| 9 | Show HN, Reddit r/selfhosted, continue PH engagement |
| 10 | Reddit r/opensource + r/laravel, follow-up Twitter posts |

**Launch day protocol:**
- Respond to every Product Hunt comment within 30 minutes
- Cross-post maker story to Twitter
- Monitor HN and Reddit threads, respond authentically
- Share PH link in Discord community for support

**Metrics to track:**
- Signups (app.relaticle.com/register)
- GitHub stars delta
- Discord joins delta
- Product Hunt ranking
- Traffic sources (UTM tags per channel)

---

## Skills Used

| Phase | Skills |
|-------|--------|
| A1: CRO Analysis | `page-cro` |
| A2: Copy Rewrites | `copywriting` |
| A3: Copy Editing | `copy-editing` |
| B1: Schema | `schema-markup` |
| B2: AI SEO | `ai-seo` |
| C2: Content | `social-content`, `launch-strategy`, `copywriting` |

---

## Success Criteria

- Landing page copy passes the copy-editing 7-sweep framework
- Schema markup validates in Google Rich Results Test
- All launch content written, reviewed, and scheduled
- Coordinated launch across 4+ channels within 3 days
- Post-launch: track signups, stars, and traffic to measure impact
