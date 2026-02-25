# CRO-First Agent-Native Launch — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Polish landing page copy for conversion, add schema markup for AI discoverability, then create coordinated multi-channel launch content.

**Architecture:** Edit 4 Blade partials for copy changes, add JSON-LD to the guest layout, update robots.txt, and create launch content as markdown files in `docs/launch/`.

**Tech Stack:** Blade templates, JSON-LD schema.org markup, marketing copy (markdown)

---

## Phase A: Page CRO & Copy Polish

### Task 1: Rewrite features section header and bottom CTA

**Files:**
- Modify: `resources/views/home/partials/features.blade.php:8-18` (section header)
- Modify: `resources/views/home/partials/features.blade.php:98-108` (bottom CTA)

**Step 1: Rewrite the section header (lines 8-18)**

Replace the current header block:

```blade
<!-- Section Header -->
<div class="max-w-3xl mx-auto text-center mb-16 md:mb-20">
    <span class="inline-block px-3 py-1 bg-white dark:bg-gray-900 rounded-full text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">
        Features
    </span>
    <h2 class="font-display mt-4 text-3xl sm:text-4xl font-bold text-black dark:text-white">
        Everything you need to manage relationships
    </h2>
    <p class="mt-5 text-base md:text-lg text-gray-600 dark:text-gray-300 max-w-2xl mx-auto leading-relaxed">
        A comprehensive suite of tools designed to streamline your client management workflow
    </p>
</div>
```

With:

```blade
<!-- Section Header -->
<div class="max-w-3xl mx-auto text-center mb-16 md:mb-20">
    <span class="inline-block px-3 py-1 bg-white dark:bg-gray-900 rounded-full text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">
        Features
    </span>
    <h2 class="font-display mt-4 text-3xl sm:text-4xl font-bold text-black dark:text-white">
        Built for humans. Accessible to AI.
    </h2>
    <p class="mt-5 text-base md:text-lg text-gray-600 dark:text-gray-300 max-w-2xl mx-auto leading-relaxed">
        20 MCP tools, a REST API, and 22 custom field types. Your team and your AI agents work from the same source of truth.
    </p>
</div>
```

**Step 2: Rewrite the bottom CTA (lines 98-108)**

Replace the current CTA block:

```blade
<!-- Call-to-action - Simplified -->
<div class="mt-20 text-center">
    <div class="inline-block pt-10 px-4 md:px-8 border-t border-gray-200 dark:border-gray-800 max-w-2xl mx-auto">
        <h3 class="font-display text-xl font-semibold text-black dark:text-white mb-4">Ready to transform your customer relationships?</h3>
        <p class="text-gray-600 dark:text-gray-300 mb-6 text-base">Experience the power of Relaticle CRM today with a free account.</p>
        <a href="{{ route('register') }}" class="group inline-flex items-center justify-center gap-2 bg-primary hover:bg-primary-600 text-white px-8 py-3.5 rounded-md font-medium text-base transition-all duration-300">
            <span>Start for free</span>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition-transform duration-300 group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
            </svg>
        </a>
    </div>
</div>
```

With:

```blade
<!-- Call-to-action -->
<div class="mt-20 text-center">
    <div class="inline-block pt-10 px-4 md:px-8 border-t border-gray-200 dark:border-gray-800 max-w-2xl mx-auto">
        <h3 class="font-display text-xl font-semibold text-black dark:text-white mb-4">Give your AI agents a CRM they can actually use</h3>
        <p class="text-gray-600 dark:text-gray-300 mb-6 text-base">20 MCP tools. Full REST API. Connect any AI agent and start automating in minutes.</p>
        <a href="{{ route('register') }}" class="group inline-flex items-center justify-center gap-2 bg-primary hover:bg-primary-600 text-white px-8 py-3.5 rounded-md font-medium text-base transition-all duration-300">
            <span>Start for free</span>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition-transform duration-300 group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
            </svg>
        </a>
        <div class="mt-4 flex items-center justify-center gap-4 text-xs text-gray-500 dark:text-gray-400">
            <span>900+ tests</span>
            <span class="text-gray-300 dark:text-gray-600">&middot;</span>
            <span>No credit card</span>
            <span class="text-gray-300 dark:text-gray-600">&middot;</span>
            <span>AGPL-3.0 open source</span>
        </div>
    </div>
</div>
```

**Step 3: Verify the changes render**

Run: `npm run build`
Expected: Build completes successfully

**Step 4: Commit**

```bash
git add resources/views/home/partials/features.blade.php
git commit -m "feat: rewrite features section header and CTA for agent-native positioning"
```

---

### Task 2: Rewrite feature card descriptions

**Files:**
- Modify: `resources/views/home/partials/features.blade.php:23-75` (features array)

**Step 1: Replace the entire `$features` array**

Replace the `$features` array (lines 23-75) with the following. Changes apply the "so what" test to every description — each now connects to an outcome, not just a capability. The first 3 features (agent-native differentiators) stay at the top.

```php
$features = [
    [
        'title' => 'Agent-Native Infrastructure',
        'description' => 'Connect any AI agent through the MCP server with 20 tools, or build custom integrations with the REST API. Full CRUD, custom field support, and schema discovery built in.',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5" />'
    ],
    [
        'title' => 'AI-Powered Insights',
        'description' => 'One-click summaries of contacts and deals. AI analyzes notes, tasks, and interactions so you always know what happened and what to do next.',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" />'
    ],
    [
        'title' => 'Customizable Data Model',
        'description' => '22 field types including entity relationships, conditional visibility, and per-field encryption. Tailor your CRM to your business without code or migrations.',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />'
    ],
    [
        'title' => 'Company Management',
        'description' => 'Track companies with detailed profiles, linked contacts, and opportunity history. See the full picture of every business relationship at a glance.',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />'
    ],
    [
        'title' => 'People Management',
        'description' => 'Rich contact profiles with interaction history, notes, and linked companies. Find anyone with advanced search and filters.',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />'
    ],
    [
        'title' => 'Sales Opportunities',
        'description' => 'Manage your pipeline with custom stages, lifecycle tracking, and win/loss analysis. Know where every deal stands and what to do next.',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />'
    ],
    [
        'title' => 'Task Management',
        'description' => 'Create, assign, and track tasks linked to contacts, companies, and deals. Your AI agent can create follow-ups automatically.',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />'
    ],
    [
        'title' => 'Team Collaboration',
        'description' => 'Multi-workspace support with role-based permissions and 5-layer authorization. Every team member sees exactly what they should.',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />'
    ],
    [
        'title' => 'Import & Export',
        'description' => 'Migrate from any CRM with CSV imports. Column mapping, validation, and error handling included. Export anytime — your data is yours.',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />'
    ],
    [
        'title' => 'Notes & Activity Log',
        'description' => 'Capture notes linked to any record. Your AI agent can log meeting notes automatically. Search and retrieve context instantly.',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />'
    ],
];
```

Key changes from original:
- **AI-Powered Insights**: Shortened, added "what to do next" outcome
- **Customizable Data Model**: Tightened, clearer benefit
- **Company Management**: Removed generic "seamless business operations", added "full picture" outcome
- **People Management**: Removed "effortlessly", tightened, cut "personalize your outreach" fluff
- **Sales Opportunities**: Added "what to do next" action orientation
- **Task Management**: Added AI agent angle ("create follow-ups automatically")
- **Team Collaboration**: Replaced generic "real-time notifications" with concrete "5-layer authorization"
- **Import & Export**: Added "your data is yours" ownership angle
- **Notes & Organization**: Renamed to "Notes & Activity Log", added AI agent angle

**Step 2: Verify the changes render**

Run: `npm run build`
Expected: Build completes successfully

**Step 3: Commit**

```bash
git add resources/views/home/partials/features.blade.php
git commit -m "feat: rewrite feature descriptions with benefit-focused agent-native copy"
```

---

### Task 3: Update community section copy

**Files:**
- Modify: `resources/views/home/partials/community.blade.php:13-19` (header)
- Modify: `resources/views/home/partials/community.blade.php:100-121` (highlights)

**Step 1: Rewrite the section header (lines 13-19)**

Replace:

```blade
<h2 class="font-display mt-4 text-3xl sm:text-4xl font-bold text-black dark:text-white">
    Collaborate and Grow Together
</h2>
<p class="mt-5 text-base md:text-lg text-gray-600 dark:text-gray-300 max-w-2xl mx-auto leading-relaxed">
    As an open-source platform, Relaticle thrives on community collaboration. Join our growing community to
    get help, share ideas, and contribute.
</p>
```

With:

```blade
<h2 class="font-display mt-4 text-3xl sm:text-4xl font-bold text-black dark:text-white">
    Built in the Open
</h2>
<p class="mt-5 text-base md:text-lg text-gray-600 dark:text-gray-300 max-w-2xl mx-auto leading-relaxed">
    Relaticle is AGPL-3.0 open source. Star the repo, join Discord, and help shape the future of agent-native CRM.
</p>
```

**Step 2: Replace community highlights with concrete numbers (lines 100-121)**

Replace the entire highlights grid:

```blade
<!-- Community highlights -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div
        class="py-3 px-4 text-center border border-gray-100 dark:border-gray-800 rounded-lg bg-white dark:bg-black">
        <div class="text-lg font-semibold text-primary dark:text-primary-400">AGPL-3.0</div>
        <div class="text-xs text-gray-500 dark:text-gray-400">Open Source</div>
    </div>
    <div
        class="py-3 px-4 text-center border border-gray-100 dark:border-gray-800 rounded-lg bg-white dark:bg-black">
        <div class="text-lg font-semibold text-primary dark:text-primary-400">900+</div>
        <div class="text-xs text-gray-500 dark:text-gray-400">Automated Tests</div>
    </div>
    <div
        class="py-3 px-4 text-center border border-gray-100 dark:border-gray-800 rounded-lg bg-white dark:bg-black">
        <div class="text-lg font-semibold text-primary dark:text-primary-400">20</div>
        <div class="text-xs text-gray-500 dark:text-gray-400">MCP Tools</div>
    </div>
    <div
        class="py-3 px-4 text-center border border-gray-100 dark:border-gray-800 rounded-lg bg-white dark:bg-black">
        <div class="text-lg font-semibold text-primary dark:text-primary-400">Free</div>
        <div class="text-xs text-gray-500 dark:text-gray-400">Forever</div>
    </div>
</div>
```

**Step 3: Verify the changes render**

Run: `npm run build`
Expected: Build completes successfully

**Step 4: Commit**

```bash
git add resources/views/home/partials/community.blade.php
git commit -m "feat: update community section with agent-native voice and concrete proof points"
```

---

## Phase B: Schema Markup & AI Discoverability

### Task 4: Add JSON-LD schema markup to homepage

**Files:**
- Modify: `resources/views/home/index.blade.php` (add @push('header') block)
- Modify: `resources/views/layouts/guest.blade.php` (verify `@stack('header')` exists — it does at line 38)

**Step 1: Add JSON-LD schema to the homepage**

Add the following `@push('header')` block at the end of `resources/views/home/index.blade.php`, before the closing `</x-guest-layout>` tag:

```blade
@push('header')
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@graph": [
        {
            "@type": "SoftwareApplication",
            "name": "Relaticle",
            "applicationCategory": "BusinessApplication",
            "applicationSubCategory": "CRM",
            "operatingSystem": "Linux, macOS, Windows",
            "description": "Open-source, self-hosted CRM with a production-grade MCP server. Connect any AI agent with 20 tools. 22 custom field types, REST API, and multi-team isolation.",
            "url": "{{ url('/') }}",
            "offers": {
                "@type": "Offer",
                "price": "0",
                "priceCurrency": "USD"
            },
            "featureList": [
                "MCP server with 20 tools for AI agents",
                "REST API with full CRUD operations",
                "22 custom field types with conditional visibility",
                "Self-hosted with full data ownership",
                "Multi-team isolation with 5-layer authorization",
                "CSV import and export",
                "AI-powered record summaries"
            ],
            "license": "https://www.gnu.org/licenses/agpl-3.0.html"
        },
        {
            "@type": "Organization",
            "name": "Relaticle",
            "url": "{{ url('/') }}",
            "logo": "{{ asset('favicon.svg') }}",
            "sameAs": [
                "https://github.com/relaticle/relaticle"
            ]
        },
        {
            "@type": "WebSite",
            "name": "Relaticle",
            "url": "{{ url('/') }}"
        }
    ]
}
</script>
@endpush
```

**Step 2: Verify the markup renders in page source**

Run: `npm run build`
Then verify by checking the page source contains `application/ld+json`.

**Step 3: Commit**

```bash
git add resources/views/home/index.blade.php
git commit -m "feat: add JSON-LD schema markup for AI discoverability"
```

---

### Task 5: Update robots.txt for AI crawlers

**Files:**
- Modify: `public/robots.txt`

**Step 1: Update robots.txt**

The current `robots.txt` allows all bots via `Disallow:` (empty), but adding explicit AI bot allows improves discoverability signals. Also add sitemap reference.

Replace the entire file:

```
User-agent: *
Disallow:

User-agent: GPTBot
Allow: /

User-agent: ClaudeBot
Allow: /

User-agent: PerplexityBot
Allow: /

User-agent: Google-Extended
Allow: /

Sitemap: https://relaticle.com/sitemap.xml
```

**Step 2: Commit**

```bash
git add public/robots.txt
git commit -m "feat: add explicit AI crawler allows and sitemap to robots.txt"
```

---

## Phase C: Launch Content Creation

### Task 6: Create Product Hunt listing draft

**Files:**
- Create: `docs/launch/product-hunt.md`

**Step 1: Write the Product Hunt listing**

Create `docs/launch/product-hunt.md` with:

```markdown
# Product Hunt Listing — Relaticle

## Tagline (60 chars max)
Open-source CRM with 20 MCP tools for AI agents

## Description

### What is Relaticle?
Relaticle is a self-hosted, open-source CRM built from the ground up for AI agent integration. It ships with a production-grade MCP server (20 tools), a REST API, and 22 custom field types.

### Who is it for?
- Developer-led teams (2-50 people) who want AI agents to manage their CRM data
- Technical founders tired of per-seat CRM pricing and vendor lock-in
- Companies needing self-hosted CRM for data sovereignty (EU, healthcare, finance)

### What makes it different?
- **Agent-native**: MCP server with 20 tools — more than HubSpot's 9. AI agents get full CRUD, schema discovery, and custom field support out of the box.
- **Self-hosted**: Deploy on your infrastructure. Your data never leaves your servers.
- **Open source**: AGPL-3.0. No per-seat pricing. Free forever.
- **Modern stack**: Laravel 12, Filament 5, PHP 8.4, PostgreSQL 17.
- **22 custom field types**: Entity relationships, conditional visibility, per-field encryption — no code or migrations needed.

### Key numbers
- 20 MCP tools (vs HubSpot's 9)
- 22 custom field types
- 900+ automated tests
- 5 entity types with full CRUD

## Maker's First Comment

Hey Product Hunt! I'm Manuk, the maker of Relaticle.

I built Relaticle because I was frustrated with CRMs that couldn't talk to AI agents. HubSpot has 9 MCP tools. Salesforce is adding Agentforce. But if you want an open-source, self-hosted CRM that your AI agents can actually operate — nothing existed.

So I built one.

Relaticle ships with a production MCP server (20 tools), a REST API, and 22 custom field types. Your AI agent can create contacts, update deals, log notes, and query CRM data — all through the standard MCP protocol.

The entire thing is AGPL-3.0 open source. No per-seat pricing. Deploy it on your infrastructure or use our managed hosting at app.relaticle.com.

I'd love to hear what you think. What would you want an AI agent to do with your CRM?

## Screenshots Needed
1. Dashboard with companies list view
2. MCP tools in action (Claude Desktop connecting to Relaticle)
3. Custom fields configuration
4. REST API documentation (Scalar UI)
5. Access tokens management page

## Topics/Categories
- Artificial Intelligence
- Open Source
- CRM
- Developer Tools
- SaaS
```

**Step 2: Commit**

```bash
git add -f docs/launch/product-hunt.md
git commit -m "docs: draft Product Hunt listing for agent-native CRM launch"
```

---

### Task 7: Create Twitter/X launch thread

**Files:**
- Create: `docs/launch/twitter-thread.md`

**Step 1: Write the Twitter thread**

Create `docs/launch/twitter-thread.md`:

```markdown
# Twitter/X Launch Thread — Relaticle

## Thread (8 tweets)

### 1/8 — Hook
I built an open-source CRM with 20 MCP tools so AI agents can manage your customer data.

HubSpot has 9. Salesforce is still figuring it out. Relaticle ships 20 today.

Here's what it does and why it matters:

### 2/8 — The Problem
Every CRM was built for humans clicking buttons.

But AI agents don't click buttons. They need APIs. They need structured schemas. They need tools.

Most CRMs bolt on AI features as an afterthought. We built ours agent-native from day one.

### 3/8 — What's Agent-Native Mean?
Agent-native = MCP server and REST API are first-class citizens, not afterthoughts.

Your AI agent can:
- Create and update contacts, companies, deals
- Log notes and create tasks
- Query data with filters and sorting
- Discover your custom field schema

All through standard MCP protocol.

### 4/8 — The Numbers
- 20 MCP tools (full CRUD for 5 entity types)
- 22 custom field types (no code, no migrations)
- REST API with JSON:API format
- 900+ automated tests
- 5-layer authorization with multi-team isolation

### 5/8 — Self-Hosted & Open Source
AGPL-3.0. Deploy on your infrastructure.

No per-seat pricing that scales badly.
No data leaving your servers.
No vendor deciding your AI strategy.

Your CRM. Your data. Your agents.

### 6/8 — The Stack
Built with modern tools:
- Laravel 12 + PHP 8.4
- Filament 5 for the admin UI
- PostgreSQL 17
- Livewire 4 for reactivity

Not a weekend project. Production-grade with 900+ tests.

### 7/8 — Who's This For?
- Developer-led teams who want AI in their CRM
- Startups tired of paying per-seat for HubSpot
- Companies that need self-hosted for compliance
- Anyone building AI workflows that touch customer data

### 8/8 — Try It
Star on GitHub: github.com/relaticle/relaticle

Try the managed version free: app.relaticle.com

No credit card. Deploy in 5 minutes.

What would you want your AI agent to do with your CRM?
```

**Step 2: Commit**

```bash
git add -f docs/launch/twitter-thread.md
git commit -m "docs: draft Twitter/X launch thread"
```

---

### Task 8: Create Reddit posts (3 angles)

**Files:**
- Create: `docs/launch/reddit-posts.md`

**Step 1: Write the three Reddit posts**

Create `docs/launch/reddit-posts.md`:

```markdown
# Reddit Launch Posts — Relaticle

---

## Post 1: r/selfhosted

**Title:** Relaticle — open-source CRM with MCP server for AI agents (self-hosted, Docker)

**Body:**

Hey r/selfhosted,

I've been building an open-source CRM called Relaticle that I wanted to share. The main thing that makes it different: it ships with a production MCP server (20 tools) so AI agents can read and write your CRM data directly.

**What it is:**
- Self-hosted CRM for managing contacts, companies, opportunities, tasks, and notes
- MCP server with 20 tools — any MCP-compatible AI agent can operate it
- REST API with Sanctum auth, JSON:API format
- 22 custom field types (no code changes needed)
- Multi-team support with role-based permissions

**Tech stack:**
- Laravel 12, PHP 8.4, PostgreSQL 17
- Filament 5 admin panel
- Docker deployment

**Self-hosting details:**
- Standard Docker Compose setup
- PostgreSQL + Redis
- Works behind reverse proxy (Traefik, Caddy, nginx)
- 900+ automated tests

**License:** AGPL-3.0

**Links:**
- GitHub: github.com/relaticle/relaticle
- Managed hosting (free tier): app.relaticle.com
- API docs: /docs endpoint with Scalar UI

Happy to answer any questions about the architecture or deployment.

---

## Post 2: r/opensource

**Title:** Relaticle: open-source CRM built for AI agents (AGPL-3.0, Laravel 12)

**Body:**

Sharing an open-source project I've been working on. Relaticle is a CRM built from the ground up for AI agent integration.

**Why another CRM?**

The CRM space has a gap: HubSpot and Salesforce are closed-source with expensive per-seat pricing. SuiteCRM and EspoCRM are open source but have dated UIs and no AI/MCP integration. There's nothing open-source that treats AI agents as first-class users.

**What Relaticle does differently:**
- Ships with an MCP server (20 tools) — AI agents can create contacts, update deals, log notes
- REST API with JSON:API format, Sanctum auth, Spatie QueryBuilder
- 22 custom field types with conditional visibility and per-field encryption
- Modern UI built with Filament 5
- Self-hosted with full data ownership

**License:** AGPL-3.0

We chose AGPL because we believe CRM data is sensitive and the software managing it should be auditable. The copyleft license ensures improvements stay open.

**How to contribute:**
- Star and fork: github.com/relaticle/relaticle
- Report issues on GitHub
- Join the Discord for discussion
- PRs welcome — we have 900+ tests and CI

Looking for feedback, feature requests, and contributors.

---

## Post 3: r/laravel

**Title:** Built a CRM with Laravel 12, Filament 5, and a production MCP server — here's the architecture

**Body:**

Hey r/laravel,

I wanted to share the architecture of Relaticle, an open-source CRM I've been building with Laravel 12 and Filament 5. The interesting part is the MCP server integration — 20 tools that let AI agents operate the CRM.

**Architecture highlights:**

1. **Shared Actions layer** — Business logic lives in `app/Actions/` (CreateCompany, ListPeople, etc.). Both the REST API controllers and MCP tools call the same actions. No logic duplication.

2. **MCP server** — Uses `laravel/mcp` package. 20 tools registered on `RelaticleServer`. Per-entity schema resources expose custom field definitions dynamically.

3. **REST API** — Versioned under `/api/v1/`. Sanctum auth. JSON:API format using Laravel 12's native `JsonApiResource`. Spatie QueryBuilder for filtering/sorting.

4. **Custom fields** — 22 field types via a custom package (`relaticle/custom-fields`). Uses EAV pattern. No migrations needed when users add fields. Conditional visibility and per-field encryption supported.

5. **Team-scoped tokens** — API tokens are permanently scoped to a team at creation time (like GitHub PATs). The `SetApiTeamContext` middleware bridges Sanctum auth to the web guard so model observers and global scopes work unchanged.

6. **API docs** — Scribe + Scalar UI. Custom strategy auto-documents Spatie QueryBuilder parameters.

**Stack:** Laravel 12, Filament 5, Livewire 4, PHP 8.4, PostgreSQL 17, 900+ tests.

**Links:**
- GitHub: github.com/relaticle/relaticle
- AGPL-3.0 license

Happy to dive into any of these patterns if you're interested.
```

**Step 2: Commit**

```bash
git add -f docs/launch/reddit-posts.md
git commit -m "docs: draft Reddit posts for r/selfhosted, r/opensource, r/laravel"
```

---

### Task 9: Create Show HN post and GitHub release notes

**Files:**
- Create: `docs/launch/show-hn.md`
- Create: `docs/launch/github-release.md`

**Step 1: Write the Show HN post**

Create `docs/launch/show-hn.md`:

```markdown
# Show HN Post — Relaticle

**Title:** Show HN: Relaticle – Open-source CRM with 20 MCP tools for AI agents

**Body:**

Relaticle is an open-source CRM (AGPL-3.0) with a production MCP server that lets AI agents manage customer data. Self-hosted, built with Laravel 12 and PHP 8.4.

**The problem:** CRMs weren't built for AI agents. Adding an MCP server to existing CRMs is like bolting a API onto software designed for mouse clicks. Schema discovery, custom fields, authorization — all break down.

**The approach:** Build the MCP server and REST API as first-class citizens from the start. The same Actions layer powers both the web UI and the agent interface.

**What's in the box:**
- MCP server with 20 tools (CRUD for companies, people, opportunities, tasks, notes + schema discovery)
- REST API with JSON:API format, Spatie QueryBuilder, Sanctum auth
- 22 custom field types (entity relationships, conditional visibility, per-field encryption)
- Multi-team isolation with 5-layer authorization
- 900+ automated tests

**Technical details:**
- Shared Actions pattern — `CreateCompany`, `ListPeople`, etc. called by both API controllers and MCP tools
- Team-scoped API tokens (like GitHub PATs) — tokens are permanently bound to a team
- Custom field schema exposed as MCP resources so agents can discover what fields exist
- Built on Laravel 12, Filament 5, PostgreSQL 17

GitHub: https://github.com/relaticle/relaticle
Try free: https://app.relaticle.com
```

**Step 2: Write the GitHub release notes**

Create `docs/launch/github-release.md`:

```markdown
# GitHub Release Notes — v3.1.0

## Relaticle v3.1.0 — Agent-Native CRM

Relaticle is now agent-native. This release introduces a production MCP server, REST API, and positions Relaticle as the open-source CRM built for AI agents.

### MCP Server (20 Tools)
- Full CRUD for all 5 entity types: companies, people, opportunities, tasks, notes
- Custom field support in create/update operations
- Per-entity schema resources exposing custom field definitions
- CRM overview prompt for agent orientation
- Schema discovery so agents know what fields exist before writing data

### REST API
- Versioned at `/api/v1/`
- JSON:API format with Laravel 12's `JsonApiResource`
- Sanctum authentication with team-scoped tokens
- Spatie QueryBuilder integration for filtering, sorting, and pagination
- Custom fields metadata endpoint (`GET /api/v1/custom-fields`)
- API documentation with Scribe + Scalar UI at `/docs`

### Access Tokens
- Renamed from "API Tokens" to "Access Tokens" (used for both REST API and MCP)
- Team-scoped tokens — permanently bound to a specific team at creation time
- Token expiration support (30 days, 60 days, 90 days, 1 year, no expiration)
- Filament-based management UI with permissions

### Custom Fields
- Writable via REST API and MCP (previously read-only)
- Validation for field types, required fields, and option constraints
- Unknown custom fields silently ignored (forward-compatible)

### Other Changes
- `CreationSource::MCP` enum value for tracking AI-created records
- Shared Actions layer for business logic (no duplication between API and MCP)
- Explicit AI crawler allows in robots.txt
- JSON-LD schema markup on homepage
- Landing page copy updated for agent-native positioning

### Technical
- 900+ automated tests (58 new API + MCP tests)
- Laravel 12, Filament 5, PHP 8.4, PostgreSQL 17
- Spatie QueryBuilder v6 for declarative API filtering/sorting
```

**Step 3: Commit**

```bash
git add -f docs/launch/show-hn.md docs/launch/github-release.md
git commit -m "docs: draft Show HN post and GitHub release notes"
```

---

## Verification Checklist

After all tasks complete:

1. `npm run build` succeeds
2. All Blade files render without errors (visit homepage locally)
3. JSON-LD appears in page source (`<script type="application/ld+json">`)
4. All launch content files exist in `docs/launch/`
5. All commits are clean and pushed

---

## Execution Order

| Task | Phase | What | Files |
|------|-------|------|-------|
| 1 | A | Features header + CTA rewrite | `features.blade.php` |
| 2 | A | Feature descriptions rewrite | `features.blade.php` |
| 3 | A | Community section update | `community.blade.php` |
| 4 | B | JSON-LD schema markup | `index.blade.php` |
| 5 | B | AI crawler robots.txt | `public/robots.txt` |
| 6 | C | Product Hunt listing | `docs/launch/product-hunt.md` |
| 7 | C | Twitter/X thread | `docs/launch/twitter-thread.md` |
| 8 | C | Reddit posts (3) | `docs/launch/reddit-posts.md` |
| 9 | C | Show HN + GitHub release | `docs/launch/show-hn.md`, `github-release.md` |
