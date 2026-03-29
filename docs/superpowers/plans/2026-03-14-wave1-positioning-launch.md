# Wave 1: Positioning & Launch Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Polish landing page copy, add AI discoverability infrastructure, update README and GitHub presence, and prepare all launch content for a coordinated multi-channel launch of "The Calm Agent-Native CRM."

**Architecture:** Sequential pipeline -- infrastructure first (package install, middleware, schema), then content polish (landing page, FAQ, README), then launch prep (content drafts, GitHub repo). Each task produces independently testable output.

**Tech Stack:** Laravel 12, Blade templates, spatie/schema-org, spatie/laravel-markdown-response, Tailwind CSS v4, gh CLI

**Spec:** `docs/superpowers/specs/2026-03-14-wave1-positioning-launch-design.md`

---

## File Structure

### New Files
| File | Responsibility |
|------|---------------|
| `resources/views/home/partials/faq.blade.php` | FAQ section Blade partial with 6 Q&As |

### Modified Files
| File | Changes |
|------|---------|
| `composer.json` | Add `spatie/laravel-markdown-response` dependency |
| `resources/views/home/index.blade.php` | Add `@include('home.partials.faq')`, update `FAQPage` schema in Graph builder |
| `resources/views/home/partials/hero.blade.php` | Sharpen subheading copy |
| `resources/views/home/partials/features.blade.php` | Update "900+" to "1,100+", sharpen feature descriptions |
| `resources/views/home/partials/community.blade.php` | Update "900+" to "1,100+" in stats bar |
| `resources/views/home/partials/start-building.blade.php` | Evaluate and sharpen CTA copy |
| `app-modules/Documentation/resources/views/components/layout.blade.php` | Add BreadcrumbList JSON-LD schema |
| `public/robots.txt` | Verify AI bot rules (already correct) |
| `.claude/product-marketing-context.md` | Update "900+" to "1,100+" |
| `README.md` | Full rewrite for agent-native positioning |
| `docs/launch/product-hunt.md` | Polish existing draft |
| `docs/launch/twitter-thread.md` | Polish existing draft |
| `docs/launch/reddit-posts.md` | Polish existing draft |
| `docs/launch/show-hn.md` | Polish existing draft |
| `docs/launch/github-release.md` | Polish existing draft |

---

## Chunk 1: AI Discoverability Infrastructure

### Task 1: Install spatie/laravel-markdown-response

**Files:**
- Modify: `composer.json`
- Modify: `routes/web.php`
- Modify: `app-modules/Documentation/routes/web.php` (if docs routes need middleware)

- [ ] **Step 1: Install the package**

Run:
```bash
composer require spatie/laravel-markdown-response
```

Expected: Package installs successfully. Check `composer.json` for the new dependency.

- [ ] **Step 2: Read the package docs for middleware registration**

Use `@marketing-skills:ai-seo` skill and check `spatie/laravel-markdown-response` docs for the exact middleware class name and configuration options.

Run:
```bash
php artisan vendor:publish --provider="Spatie\MarkdownResponse\MarkdownResponseServiceProvider" --tag="config"
```

Expected: Config file published (if one exists). Check the default configuration.

- [ ] **Step 3: Register middleware on public web routes only**

Modify `routes/web.php` to wrap public routes with the middleware. Do NOT apply to auth routes, API routes, Filament routes, or MCP routes.

```php
// routes/web.php
use Spatie\MarkdownResponse\Middleware\ProvideMarkdownResponse;

// Public marketing pages -- serve markdown to AI bots
Route::middleware(ProvideMarkdownResponse::class)->group(function () {
    Route::get('/', HomeController::class);
    Route::get('/terms-of-service', TermsOfServiceController::class)->name('terms.show');
    Route::get('/privacy-policy', PrivacyPolicyController::class)->name('policy.show');
});
```

Keep these routes OUTSIDE the markdown middleware group -- they don't need markdown responses:
- Auth routes (`/login`, `/register`, `/forgot-password`)
- `/dashboard` redirect
- `/team-invitations/{invitation}` route
- `/discord` redirect
- Any other non-marketing routes

- [ ] **Step 4: Verify middleware works**

Run:
```bash
curl -H "Accept: text/markdown" http://relaticle-pr-148.test/ | head -50
```

Expected: Returns markdown version of homepage instead of HTML.

Also test that normal browser requests still return HTML:
```bash
curl http://relaticle-pr-148.test/ | head -20
```

Expected: Returns normal HTML.

- [ ] **Step 5: Add middleware to Documentation module routes**

Documentation routes live in `app-modules/Documentation/routes/web.php`, registered via `DocumentationServiceProvider`. Wrap the documentation routes with the same middleware:

```php
// app-modules/Documentation/routes/web.php
use Spatie\MarkdownResponse\Middleware\ProvideMarkdownResponse;

Route::middleware(ProvideMarkdownResponse::class)->group(function () {
    // existing documentation routes here
});
```

Read the file first to understand the current route structure before wrapping.

Verify:
```bash
curl -H "Accept: text/markdown" http://relaticle-pr-148.test/documentation | head -30
```

Expected: Returns markdown version of documentation index.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock routes/web.php app-modules/Documentation/routes/web.php
git commit -m "feat: add spatie/laravel-markdown-response for AI bot discoverability"
```

---

### Task 2: Verify and update robots.txt

**Files:**
- Modify: `public/robots.txt` (if needed)

- [ ] **Step 1: Check current robots.txt**

Read `public/robots.txt`. Current content allows GPTBot, ClaudeBot, PerplexityBot, Google-Extended. Verify this is sufficient.

Expected: Already correct. The current file explicitly allows all major AI bots:
```
User-agent: GPTBot
Allow: /

User-agent: ClaudeBot
Allow: /

User-agent: PerplexityBot
Allow: /

User-agent: Google-Extended
Allow: /
```

- [ ] **Step 2: Add any missing AI bots**

Check if these bots should also be explicitly allowed:
- `Bytespider` (ByteDance/TikTok)
- `CCBot` (Common Crawl, used by many AI training sets)
- `anthropic-ai` (Anthropic's web crawler)

If any are missing and worth adding, add them. Otherwise, no changes needed.

- [ ] **Step 3: Commit (only if changes were made)**

```bash
git add public/robots.txt
git commit -m "chore: update robots.txt with additional AI bot allowances"
```

---

### Task 3: Update existing JSON-LD schema and add FAQPage

**Files:**
- Modify: `resources/views/home/index.blade.php`

- [ ] **Step 1: Review current schema in index.blade.php**

Read `resources/views/home/index.blade.php` lines 12-45. The existing `$schema` Graph builder has:
- `SoftwareApplication` -- check description and featureList are current
- `Organization` -- check sameAs has Discord
- `Website` -- check url is correct

- [ ] **Step 2: Update SoftwareApplication description**

In `resources/views/home/index.blade.php`, update the `description` and `featureList` in the existing `softwareApplication()` call:

```php
->softwareApplication(fn ($app) => $app
    ->name('Relaticle')
    ->applicationCategory('BusinessApplication')
    ->applicationSubCategory('CRM')
    ->operatingSystem('Linux, macOS, Windows')
    ->description('The open-source CRM built for AI agents. Self-hosted with a production-grade MCP server (20 tools), REST API, and 22 custom field types. Connect any AI agent -- Claude, GPT, or open-source models.')
    ->url(url('/'))
    ->offers(\Spatie\SchemaOrg\Schema::offer()->price('0')->priceCurrency('USD'))
    ->setProperty('featureList', [
        'MCP server with 20 tools for AI agents',
        'REST API with full CRUD operations',
        '22 custom field types with conditional visibility and encryption',
        'Self-hosted with full data ownership',
        'Multi-team isolation with 5-layer authorization',
        '1,100+ automated tests',
        'CSV import and export',
        'AI-powered record summaries',
    ])
    ->license('https://www.gnu.org/licenses/agpl-3.0.html')
)
```

- [ ] **Step 3: Add Discord to Organization sameAs**

```php
->organization(fn ($org) => $org
    ->name('Relaticle')
    ->url(url('/'))
    ->logo(asset('favicon.svg'))
    ->sameAs(array_filter([
        'https://github.com/relaticle/relaticle',
        config('services.discord.invite_url'),
    ]))
)
```

- [ ] **Step 4: Add FAQPage schema**

Add after the existing `->website()` call, using the same Graph builder pattern. The FAQ Q&A content is added here in the schema now (the visible FAQ section is built in Task 7):

```php
->fAQPage(fn ($faq) => $faq
    ->mainEntity([
        \Spatie\SchemaOrg\Schema::question()
            ->name('Is Relaticle production-ready?')
            ->acceptedAnswer(
                \Spatie\SchemaOrg\Schema::answer()
                    ->text('Yes. Relaticle has 1,100+ automated tests, 5-layer authorization, 56+ MCP-specific tests, and is used in production. The codebase is continuously tested with PHPStan static analysis and Pest mutation testing.')
            ),
        \Spatie\SchemaOrg\Schema::question()
            ->name('What AI agents can I connect?')
            ->acceptedAnswer(
                \Spatie\SchemaOrg\Schema::answer()
                    ->text('Any agent that speaks MCP (Model Context Protocol). Claude, ChatGPT, Gemini, open-source models, or your own custom agents. Relaticle\'s MCP server provides 20 tools for AI agents to read, create, update, and delete CRM data.')
            ),
        \Spatie\SchemaOrg\Schema::question()
            ->name('What is MCP?')
            ->acceptedAnswer(
                \Spatie\SchemaOrg\Schema::answer()
                    ->text('MCP (Model Context Protocol) is an open standard that lets AI agents interact with tools and data sources. Relaticle\'s MCP server gives agents 20 tools to work with your CRM data -- listing companies, creating contacts, updating opportunities, and more.')
            ),
        \Spatie\SchemaOrg\Schema::question()
            ->name('How is Relaticle different from HubSpot or Salesforce?')
            ->acceptedAnswer(
                \Spatie\SchemaOrg\Schema::answer()
                    ->text('Relaticle is self-hosted (you own your data), open-source (AGPL-3.0), has 20 MCP tools (vs HubSpot\'s 9), and has no per-seat pricing. It\'s designed for teams who want AI agent integration without vendor lock-in.')
            ),
        \Spatie\SchemaOrg\Schema::question()
            ->name('How do I deploy Relaticle?')
            ->acceptedAnswer(
                \Spatie\SchemaOrg\Schema::answer()
                    ->text('Deploy with Docker Compose, Laravel Forge, or any PHP 8.4+ hosting with PostgreSQL. Self-hosted means your data never leaves your server. A managed hosting option is also available at app.relaticle.com.')
            ),
        \Spatie\SchemaOrg\Schema::question()
            ->name('Can I customize the data model?')
            ->acceptedAnswer(
                \Spatie\SchemaOrg\Schema::answer()
                    ->text('Yes. Relaticle offers 22 custom field types including text, email, phone, currency, date, select, multiselect, entity relationships, conditional visibility, and per-field encryption. No migrations or code changes needed.')
            ),
    ])
)
```

- [ ] **Step 5: Verify schema output**

Run:
```bash
curl -s http://relaticle-pr-148.test/ | grep -o '<script type="application/ld+json">.*</script>' | php -r 'echo json_encode(json_decode(file_get_contents("php://stdin")), JSON_PRETTY_PRINT);'
```

Expected: Valid JSON-LD with SoftwareApplication, Organization, Website, and FAQPage schemas.

- [ ] **Step 6: Commit**

```bash
git add resources/views/home/index.blade.php
git commit -m "feat: update JSON-LD schema with FAQPage and current product details"
```

---

### Task 4: Add BreadcrumbList to documentation pages

**Files:**
- Modify: `app-modules/Documentation/resources/views/components/layout.blade.php`

- [ ] **Step 1: Read the documentation layout**

Read `app-modules/Documentation/resources/views/components/layout.blade.php`. It uses `<x-guest-layout>` wrapper with `$documentTitle` variable.

- [ ] **Step 2: Add BreadcrumbList JSON-LD**

Add inline `@php` block just before the closing `</x-guest-layout>` tag (same pattern as homepage schema):

```php
@php
    $items = [
        \Spatie\SchemaOrg\Schema::listItem()
            ->position(1)
            ->name('Home')
            ->item(url('/')),
        \Spatie\SchemaOrg\Schema::listItem()
            ->position(2)
            ->name('Documentation')
            ->item(route('documentation.index')),
    ];

    if (! empty($documentTitle)) {
        $items[] = \Spatie\SchemaOrg\Schema::listItem()
            ->position(3)
            ->name($documentTitle)
            ->item(url()->current());
    }

    $breadcrumbs = \Spatie\SchemaOrg\Schema::breadcrumbList()
        ->itemListElement($items);
@endphp

{!! $breadcrumbs->toScript() !!}
```

Note: `route('documentation.index')` exists in `app-modules/Documentation/routes/web.php`.

- [ ] **Step 3: Verify breadcrumb renders**

Run:
```bash
curl -s http://relaticle-pr-148.test/documentation | grep -o 'BreadcrumbList'
```

Expected: "BreadcrumbList" found in the page source.

- [ ] **Step 4: Commit**

```bash
git add app-modules/Documentation/resources/views/components/layout.blade.php
git commit -m "feat: add BreadcrumbList JSON-LD schema to documentation pages"
```

---

## Chunk 2: Landing Page Copy Polish & FAQ Section

### Task 5: Fix stale numbers across codebase

**Files:**
- Modify: `resources/views/home/partials/features.blade.php`
- Modify: `resources/views/home/partials/community.blade.php`
- Modify: `.claude/product-marketing-context.md`

- [ ] **Step 1: Find all stale "900" references**

Run:
```bash
grep -rn "900" --include="*.blade.php" --include="*.md" --include="*.php" | grep -i "test"
```

Expected: Find references in `features.blade.php` (CTA card), `community.blade.php` (stats bar), and `product-marketing-context.md`.

- [ ] **Step 2: Update features.blade.php CTA card**

In `resources/views/home/partials/features.blade.php` line 286, change:

```blade
<span>900+ tests</span>
```
to:
```blade
<span>1,100+ tests</span>
```

- [ ] **Step 3: Update community.blade.php stats bar**

In `resources/views/home/partials/community.blade.php` line 41, change:

```php
['900+', 'Automated Tests'],
```
to:
```php
['1,100+', 'Automated Tests'],
```

- [ ] **Step 4: Update product-marketing-context.md**

In `.claude/product-marketing-context.md`, update all "900+" references to "1,100+":
- Line 59: Objections table ("900+ tests" -> "1,100+ tests")
- Line 100: Proof Points metrics ("900+ automated tests" -> "1,100+ automated tests")

- [ ] **Step 5: Verify no remaining stale references**

Run:
```bash
grep -rn "900+" --include="*.blade.php" --include="*.md" | grep -iv "port\|width\|height\|pixel\|size\|max\|min"
```

Expected: No test-related "900+" references remain.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/home/partials/features.blade.php resources/views/home/partials/community.blade.php .claude/product-marketing-context.md
git commit -m "fix: update stale test count from 900+ to 1,100+ across landing page and docs"
```

---

### Task 6: Landing page copy polish

**Files:**
- Modify: `resources/views/home/partials/hero.blade.php`
- Modify: `resources/views/home/partials/features.blade.php`
- Modify: `resources/views/home/partials/community.blade.php`
- Modify: `resources/views/home/partials/start-building.blade.php`

This task uses the `@marketing-skills:copywriting` and `@marketing-skills:copy-editing` skills.

- [ ] **Step 1: Invoke copy-editing skill**

Use `@marketing-skills:copy-editing` to run the 7-sweep pass on all landing page copy. Read all four Blade partials and apply the framework:
1. Clarity
2. Voice & Tone (calm, direct, developer-friendly)
3. So What (features connect to benefits)
4. Prove It (claims substantiated with numbers)
5. Specificity ("20 MCP tools" not "comprehensive API")
6. Heightened Emotion (pain of vendor lock-in, relief of data ownership)
7. Zero Risk ("No credit card", "Deploy in 5 minutes")

- [ ] **Step 2: Sharpen hero subheading**

Current: "MCP-native. Self-hosted. 20 tools for any AI to operate your CRM."

The word "operate" is vague. Replace with something more concrete. Examples to consider:
- "MCP-native. Self-hosted. 20 tools for any AI to search, create, and update your CRM data."
- "MCP-native. Self-hosted. Any AI agent can search contacts, update deals, and log notes."

Use the `@marketing-skills:copywriting` skill with `product-marketing-context.md` context to determine the best version.

Edit `resources/views/home/partials/hero.blade.php` line 32.

- [ ] **Step 3: Review features section header**

Current: "Built for humans. Accessible to AI."

Decision criteria: Keep if it (a) differentiates from competitors, (b) reinforces agent-native positioning, and (c) reads well for developer audience. This heading balances the human/AI duality well -- likely keep as-is. Only change if the copy-editing sweep identified a concrete weakness.

- [ ] **Step 4: Review community section heading**

Current: "Built in the Open"

This serves open-source credibility, not AI positioning -- that's intentional. Keep as-is. No change expected.

- [ ] **Step 5: Review start-building CTA**

Current heading: "Your CRM, Your Rules"
Current subtext: "Self-hosted. Agent-native. Full control over your data and your AI."

This is strong. Keep as-is unless copy-editing sweep found a specific issue. No change expected.

- [ ] **Step 6: Run page-cro skill**

Use `@marketing-skills:page-cro` to run a conversion analysis on the full landing page. Focus on:
- CTA button copy and placement
- Trust signals near CTAs (test count, AGPL badge)
- Hero-to-CTA flow

Apply only changes that don't require new sections or visual redesign (copy-only quick wins). If the skill is unavailable, manually verify: (a) every section has a clear CTA, (b) CTAs use action verbs, (c) trust signals appear near decision points.

- [ ] **Step 7: Build and verify changes visually**

Run:
```bash
npm run build
```

Open `http://relaticle-pr-148.test/` and verify the copy changes look correct visually.

- [ ] **Step 8: Commit**

```bash
git add resources/views/home/partials/hero.blade.php resources/views/home/partials/features.blade.php resources/views/home/partials/community.blade.php resources/views/home/partials/start-building.blade.php
git commit -m "feat: sharpen landing page copy for agent-native positioning"
```

---

### Task 7: Create FAQ section

**Files:**
- Create: `resources/views/home/partials/faq.blade.php`
- Modify: `resources/views/home/index.blade.php`

- [ ] **Step 1: Create the FAQ Blade partial**

Create `resources/views/home/partials/faq.blade.php`. Design it to match the existing landing page visual style (same card borders, typography, spacing as features and community sections).

Use `@marketing-skills:copywriting` skill with `product-marketing-context.md` to write the 6 FAQ answers. Follow the Tailwind CSS v4 patterns from sibling partials.

Use `@landing-page-design` skill for the visual implementation. Study `hero.blade.php` and `features.blade.php` for styling patterns (typography, spacing, dark mode classes).

Structure with Alpine.js accordion:
```blade
<section class="py-24 md:py-32 bg-white dark:bg-black relative">
    <div class="max-w-3xl mx-auto px-6 lg:px-8">
        <div class="max-w-2xl mx-auto text-center mb-16">
            <h2 class="font-display text-3xl sm:text-4xl font-bold text-gray-950 dark:text-white tracking-tight">
                Frequently Asked Questions
            </h2>
        </div>

        <div x-data="{ open: null }" class="divide-y divide-gray-200 dark:divide-white/10">
            <!-- Repeat this pattern for each Q&A -->
            <div class="py-5">
                <button @click="open = open === 1 ? null : 1"
                        class="flex w-full items-center justify-between text-left">
                    <span class="text-base font-semibold text-gray-900 dark:text-white">
                        Is Relaticle production-ready?
                    </span>
                    <x-ri-arrow-down-s-line class="h-5 w-5 text-gray-400 transition-transform duration-200"
                                            ::class="open === 1 ? 'rotate-180' : ''" />
                </button>
                <div x-show="open === 1" x-collapse class="mt-3 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                    <!-- Answer text here -->
                </div>
            </div>
            <!-- Increment the number (1, 2, 3...) for each subsequent Q&A -->
        </div>
    </div>
</section>
```

The 6 Q&As from the spec (use answers from the FAQPage schema in Task 3 for consistency):
1. Is Relaticle production-ready?
2. What AI agents can I connect?
3. What's MCP?
4. How is this different from HubSpot or Salesforce?
5. How do I deploy it?
6. Can I customize the data model?

- [ ] **Step 2: Include FAQ in homepage**

Modify `resources/views/home/index.blade.php` to include the FAQ partial between community and start-building:

```blade
@include('home.partials.community')
@include('home.partials.faq')
@include('home.partials.start-building')
```

- [ ] **Step 3: Verify FAQ renders correctly**

Run:
```bash
npm run build
```

Open `http://relaticle-pr-148.test/` and scroll to the FAQ section. Verify:
- Section appears between community and start-building
- Accordion interactions work (click to expand/collapse)
- Dark mode works
- Mobile responsive

- [ ] **Step 4: Verify FAQPage schema includes the FAQ content**

Run:
```bash
curl -s http://relaticle-pr-148.test/ | grep -c "FAQPage"
```

Expected: At least 1 match (from the JSON-LD added in Task 3). If not found, go back and complete Task 3 first.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/home/partials/faq.blade.php resources/views/home/index.blade.php
git commit -m "feat: add FAQ section with Alpine.js accordion UI"
```

---

## Chunk 3: README, GitHub Repo & Launch Content

### Task 8: Rewrite README for agent-native positioning

**Files:**
- Modify: `README.md`

Use `@marketing-skills:copywriting` skill with `product-marketing-context.md`.

- [ ] **Step 1: Read current README**

Read `README.md` fully. Current headline: "Next-Generation Open-Source CRM". Core strengths list doesn't mention MCP, REST API, or agent-native.

- [ ] **Step 2: Rewrite README**

Use `@marketing-skills:copywriting` skill with `product-marketing-context.md` context. Keep the same sections (badges, about, requirements, installation, development, Docker, docs, community, license, star history) but update content.

Key changes:
- **Headline:** Change "Next-Generation Open-Source CRM" to "The Open-Source CRM Built for AI Agents"
- **Description paragraph:** Lead with: "Relaticle is a self-hosted CRM with a production-grade MCP server. Connect any AI agent -- Claude, GPT, or open-source models -- with 20 tools for full CRM operations. 22 custom field types, REST API, and multi-team isolation."
- **Badges:** Keep existing, add MCP tools count badge:
  ```markdown
  <a href="https://relaticle.com/documentation/mcp"><img src="https://img.shields.io/badge/MCP_Tools-20-8A2BE2?style=for-the-badge" alt="20 MCP Tools"></a>
  ```
- **Core Strengths:** Reorder to lead with agent-native:
  1. Agent-Native Infrastructure (MCP server, 20 tools, REST API)
  2. Customizable Data Model (22 field types, conditional visibility, encryption)
  3. Multi-Team Isolation (5-layer auth, team-scoped data)
  4. Modern Tech Stack (Laravel 12, Filament 5, PHP 8.4, 1,100+ tests)
  5. Privacy-First (self-hosted, AGPL-3.0, your data stays on your server)
- **Comparison table:** Add these columns: Self-Hosted, Open Source, MCP Tools, Custom Fields, Per-Seat Pricing
  - Relaticle: Yes, AGPL-3.0, 20, 22 types, No
  - HubSpot: No, No, 9, Limited, Yes
  - Salesforce: No, No, 0, Yes (complex), Yes
  - Attio: No, No, 0, Yes, Yes
- **Test count:** Use "1,100+" everywhere

- [ ] **Step 3: Verify screenshots match current UI**

Check that any screenshot paths referenced in the README point to files that exist. If screenshots reference old UI, note them for update but don't block on this (screenshot refresh can be a follow-up).

```bash
grep -n "screenshot\|\.png\|\.jpg\|\.gif" README.md
```

- [ ] **Step 4: Verify README formatting**

Check that badge URLs are well-formed and markdown links have valid syntax:
```bash
grep -n '\[.*\](.*' README.md | head -20
grep -n 'img.shields.io' README.md
```

Verify no broken markdown by reviewing the raw output visually.

- [ ] **Step 5: Commit**

```bash
git add README.md
git commit -m "feat: rewrite README for agent-native CRM positioning"
```

---

### Task 9: Update GitHub repo description and topics

**Files:** None (GitHub API changes only)

- [ ] **Step 1: Update repo description**

Run:
```bash
gh repo edit relaticle/relaticle --description "The Open-Source CRM Built for AI Agents -- MCP-native, self-hosted, 22 custom field types"
```

- [ ] **Step 2: Update repo topics**

Run:
```bash
gh repo edit relaticle/relaticle --add-topic mcp --add-topic ai-agents --add-topic agent-native --add-topic mcp-server
```

Verify current topics are preserved:
```bash
gh repo view relaticle/relaticle --json repositoryTopics --jq '[.repositoryTopics[].name] | join(", ")'
```

Expected: Should include both old topics (crm, filament, laravel, livewire, etc.) and new ones (mcp, ai-agents, agent-native, mcp-server).

- [ ] **Step 3: Social preview image (MANUAL STEP)**

The social preview image needs a new design reflecting agent-native positioning (1280x640px recommended). This must be uploaded via the GitHub web interface at `https://github.com/relaticle/relaticle/settings` -- the API does not support social preview uploads.

**Action for implementing agent:** Skip this step and flag it as requiring manual upload by the user. Add a comment in the commit or PR noting "Social preview image needs manual update via GitHub settings."

- [ ] **Step 4: Verify changes**

Run:
```bash
gh repo view relaticle/relaticle --json description,repositoryTopics
```

Expected: New description and topics visible.

---

### Task 10: Polish launch content drafts

**Files:**
- Modify: `docs/launch/product-hunt.md`
- Modify: `docs/launch/twitter-thread.md`
- Modify: `docs/launch/reddit-posts.md`
- Modify: `docs/launch/show-hn.md`
- Modify: `docs/launch/github-release.md`

Use `@marketing-skills:social-content` and `@marketing-skills:launch-strategy` skills.

- [ ] **Step 1: Read all 5 existing drafts**

Read all files in `docs/launch/` to understand current content.

- [ ] **Step 2: Update numbers across all drafts**

Replace all "900+" with "1,100+" across all 5 files.

Run:
```bash
grep -rn "900" docs/launch/
```

Fix every match.

- [ ] **Step 3: Add markdown-response talking point**

Add "Even our website speaks markdown to AI bots" angle to relevant drafts:
- Twitter thread: Add as a tweet about the website being agent-native
- Reddit r/selfhosted: Mention as a technical detail
- Show HN: Mention as a technical feature

- [ ] **Step 4: Polish Product Hunt draft**

Use `@marketing-skills:social-content` skill. Read `docs/launch/product-hunt.md`. Update:
- Tagline: Must be under 60 chars, include "agent-native" or "AI agents"
- Description: Align with finalized landing page copy (hero headline + subheading)
- Add MCP comparison: "20 MCP tools vs HubSpot's 9"
- Ensure consistent numbers: 20 MCP tools, 22 field types, 1,100+ tests

- [ ] **Step 5: Polish Twitter thread**

Use `@marketing-skills:social-content` skill. Read `docs/launch/twitter-thread.md`. Update:
- First tweet: Must have a hook with a concrete number or bold claim (e.g., "We built a CRM with 20 tools for AI agents to use directly")
- Add "website is agent-native too" angle: "Even our landing page serves markdown to AI bots"
- Every tweet with a claim must include a number
- Ensure consistent numbers: 20 MCP tools, 22 field types, 1,100+ tests

- [ ] **Step 6: Polish Reddit posts**

Use `@marketing-skills:social-content` skill. Read `docs/launch/reddit-posts.md`. Update:
- r/selfhosted: Lead with Docker Compose deployment, emphasize data ownership, mention no per-seat pricing
- r/opensource: Lead with AGPL-3.0, mention 1,100+ tests, community stats
- r/laravel: Technical stack details -- Laravel 12, Filament 5, PHP 8.4, MCP via `laravel/mcp`, Pest testing
- All three: Ensure consistent numbers

- [ ] **Step 7: Polish HN post**

Use `@marketing-skills:social-content` skill. Read `docs/launch/show-hn.md`. Update:
- NO marketing language -- technical tone only (HN will downvote marketing)
- Lead with: "Show HN: Open-source CRM with 20-tool MCP server for AI agents"
- Focus on technical decisions: why MCP, how the server works, test coverage
- Mention `spatie/laravel-markdown-response` as a technical detail

- [ ] **Step 8: Polish GitHub release notes**

Read `docs/launch/github-release.md`. Update:
- Align headline with README: "The Open-Source CRM Built for AI Agents"
- Include highlights: MCP server (20 tools), REST API, 22 field types, 1,100+ tests
- Reference new repo topics (mcp, ai-agents, agent-native, mcp-server)

- [ ] **Step 9: Ensure messaging consistency**

Read all 5 polished drafts back-to-back. Verify:
- Same numbers used everywhere (20 MCP tools, 22 field types, 1,100+ tests)
- Same positioning language (agent-native, self-hosted, open-source)
- No contradictions between channels

- [ ] **Step 10: Add UTM parameters to all links**

Ensure all links to relaticle.com in launch content include UTM tags:
- Product Hunt: `?utm_source=producthunt&utm_medium=social&utm_campaign=wave1`
- Twitter: `?utm_source=twitter&utm_medium=social&utm_campaign=wave1`
- Reddit: `?utm_source=reddit&utm_medium=social&utm_campaign=wave1`
- HN: `?utm_source=hackernews&utm_medium=social&utm_campaign=wave1`
- GitHub: `?utm_source=github&utm_medium=social&utm_campaign=wave1`

- [ ] **Step 11: Commit**

```bash
git add docs/launch/
git commit -m "feat: polish all launch content drafts for wave 1 coordinated launch"
```

---

### Task 11: Final verification

- [ ] **Step 1: Run full test suite**

```bash
php artisan test --compact
```

Expected: All tests pass. No regressions from copy/template changes.

- [ ] **Step 2: Run code quality checks**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
```

Expected: No errors. If rector suggests changes, apply with `vendor/bin/rector` then re-run pint.

- [ ] **Step 3: Build frontend assets**

```bash
npm run build
```

Expected: Build completes without errors.

- [ ] **Step 4: Visual smoke test**

Open `http://relaticle-pr-148.test/` and verify:
- Hero section renders with updated copy
- Features section shows "1,100+ tests"
- FAQ section renders between community and start-building
- Community stats show "1,100+"
- Dark mode works on all sections
- Mobile responsive on all sections
- Documentation pages render with BreadcrumbList

- [ ] **Step 5: Validate structured data**

Validate homepage schemas:
```bash
curl -s http://relaticle-pr-148.test/ | grep -c 'FAQPage'
curl -s http://relaticle-pr-148.test/ | grep -c 'SoftwareApplication'
```

Expected: Both return at least 1. Homepage has SoftwareApplication, Organization, Website, and FAQPage schemas.

Validate documentation BreadcrumbList (separate from homepage):
```bash
curl -s http://relaticle-pr-148.test/documentation | grep -c 'BreadcrumbList'
```

Expected: Returns at least 1. BreadcrumbList is on documentation pages, not the homepage.

- [ ] **Step 6: Test markdown response**

```bash
curl -H "Accept: text/markdown" http://relaticle-pr-148.test/ | head -30
```

Expected: Clean markdown version of the homepage.

- [ ] **Step 7: Verify README formatting**

Review `README.md` locally for valid markdown. README rendering on GitHub can only be verified after push (which happens outside this plan). Check:
- Badge image URLs are well-formed
- All markdown links have closing parentheses
- Comparison table renders correctly (pipe alignment)
