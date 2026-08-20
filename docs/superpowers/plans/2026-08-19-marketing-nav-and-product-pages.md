# Marketing Nav Restructure + Product Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Single-source marketing navigation (footer restructure now, mega-menu header after), plus two new product pages: `/ai` (Rela) and `/self-hosted`.

**Architecture:** A `MarketingNavigation` support class returns typed nav trees consumed by the desktop header, mobile menu, and footer. PR-1 ships the class, the four-column footer, a11y, and the drift fix with the current flat header. PR-2 ships `/ai`, `/self-hosted`, the click-activated mega-menu header, and the GitHub star badge.

**Tech Stack:** Laravel 13, Blade, Alpine.js, Tailwind v4, Pest 5, spatie/schema-org, Pennant feature flags.

**Spec:** `docs/superpowers/specs/2026-08-19-marketing-nav-and-product-pages-design.md`

## Global Constraints

- Never use an em-dash anywhere: prose, code, comments, copy. Use a comma, colon, or two sentences.
- No AI attribution in commits or PRs.
- Conventional commits, subject under 72 chars, lowercase.
- All user-facing strings wrapped in `__()` (PHPStan i18n rules; no new ignores).
- All parameters and return types explicitly typed (100% type coverage gate).
- Tests live in `tests/Feature/` or `tests/Smoke/` only; no new suites; Pest style matching `tests/Feature/ComparisonPagesTest.php`.
- Pre-commit gates, in order: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run` (apply if it suggests), `vendor/bin/phpstan analyse`, `composer test:type-coverage`, `php artisan test --compact --filter=<relevant>`.
- Every visual change verified with agent-browser (light + dark + mobile) before "done".
- Marketing claims must be true in this codebase (verified claim inventory in the spec).
- Icons: brand icons `fill` variant, UI icons `line` variant.
- Route names camelCase; URLs kebab-case.
- Design tokens from `resources/css/theme.css`; no ad-hoc values.
- PR-1 branch: `ManukMinasyan/header-footer-nav-options` (current). PR-2 branch: `ManukMinasyan/marketing-product-pages`, created from the PR-1 branch, PR based on the PR-1 branch until it merges, then retargeted to `main`.
- PR-2 additionally requires PR #506 merged (provides `config('chat.assistant_name')` = Rela).

## Existing infrastructure the plan reuses (verified 2026-08-19)

- `app/Services/GitHubService.php`: `getStarsCount()` / `getFormattedStarsCount()` with 15-min cache. A View composer in `AppServiceProvider::configureGitHubStars()` (line ~536) already passes `githubStars` + `formattedGithubStars` into `components.layout.header`. The badge only needs rendering.
- `resources/views/home/partials/hero-agent-preview.blade.php`: self-contained app-window mockup (`x-data="heroChat()"`, animates on `hero-chat-animate` window event, includes shell/conversation/composer/entry, respects reduced motion). `/ai` includes it and dispatches the event from an IntersectionObserver.
- `resources/views/layouts/guest.blade.php`: `<x-guest-layout title description ogTitle ...>` with skip link, header, footer.
- `resources/views/components/marketing/faq-accordion.blade.php` and `button.blade.php`.
- JSON-LD pattern: inline `@php` + `\Spatie\SchemaOrg\Schema` in `resources/views/compare/show.blade.php:180-201`.
- llms.txt: `packages/Documentation/src/Http/Controllers/HelpController.php` `llmsTxtBody()` (line ~111).
- Smoke coverage: `tests/Smoke/RouteTest.php` auto-enumerates GET routes; new pages are covered automatically.

---

# PR-1: Navigation foundation

### Task 1: Nav source of truth + four-column footer

**Files:**
- Create: `app/Support/NavItem.php`
- Create: `app/Support/MarketingNavigation.php`
- Modify: `resources/views/components/layout/footer.blade.php` (full rewrite of the link area)
- Test: `tests/Feature/MarketingNavigationTest.php`

**Interfaces:**
- Produces: `NavItem` (readonly: `label`, `?url`, `external`, `children`), `MarketingNavigation::header(): array`, `::footer(): array`, `::mobile(): array`. Task 2 and PR-2 Task 8 consume these exact names.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Support\MarketingNavigation;

mutates(MarketingNavigation::class);

it('links every declared comparison and alternatives page from the footer', function (): void {
    $html = $this->get('/pricing')->assertOk()->getContent();

    foreach (config('comparisons.compare') as $slug) {
        expect($html)->toContain(route('compare.show', ['competitor' => $slug]));
    }

    foreach (config('comparisons.alternatives') as $slug) {
        expect($html)->toContain(route('alternatives.show', ['competitor' => $slug]));
    }
});

it('renders the four footer columns with labelled navigation', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain(__('Product'))
        ->and($html)->toContain(__('Resources'))
        ->and($html)->toContain(__('Compare'))
        ->and($html)->toContain(__('Company'))
        ->and($html)->toContain('aria-label="'.__('Footer').'"');
});

it('links llms.txt and the GitHub repository from the footer', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain(route('llms-txt'))
        ->and($html)->toContain('https://github.com/relaticle/relaticle');
});
```

- [ ] **Step 2: Run it, confirm failure**

Run: `php artisan test --compact --filter=MarketingNavigationTest`
Expected: FAIL (columns and llms.txt link absent; class not found by mutates).

- [ ] **Step 3: Create `NavItem`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

final readonly class NavItem
{
    /** @param list<NavItem> $children */
    public function __construct(
        public string $label,
        public ?string $url = null,
        public bool $external = false,
        public array $children = [],
    ) {}
}
```

- [ ] **Step 4: Create `MarketingNavigation`**

PR-1 shape. Feature gates evaluate inside; comparison links derive from config so new slugs appear automatically. Header/mobile stay flat in PR-1 (current items, drift fixed: both get Discord, neither gets Contact).

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Features\Blog;
use App\Features\Documentation;
use App\Support\CompetitorFacts;
use Laravel\Pennant\Feature;

final readonly class MarketingNavigation
{
    /** @return list<NavItem> */
    public function header(): array
    {
        return array_values(array_filter([
            new NavItem(__('Features'), url('/#features')),
            new NavItem(__('Pricing'), route('pricing')),
            Feature::active(Documentation::class) ? new NavItem(__('Help'), route('help.index')) : null,
            Feature::active(Documentation::class) ? new NavItem(__('Developers'), route('documentation.index')) : null,
            Feature::active(Blog::class) ? new NavItem(__('Blog'), route('blog.index')) : null,
            new NavItem(__('Discord'), route('discord'), external: true),
        ]));
    }

    /** @return list<NavItem> */
    public function mobile(): array
    {
        return $this->header();
    }

    /** @return list<NavItem> Columns: label + children links. */
    public function footer(): array
    {
        $facts = CompetitorFacts::all();

        $compare = array_map(
            fn (string $slug): NavItem => new NavItem(
                __('Relaticle vs :name', ['name' => $facts[$slug]['name']]),
                route('compare.show', ['competitor' => $slug]),
            ),
            config('comparisons.compare', []),
        );

        $alternatives = array_map(
            fn (string $slug): NavItem => new NavItem(
                __(':name alternative', ['name' => $facts[$slug]['name']]),
                route('alternatives.show', ['competitor' => $slug]),
            ),
            config('comparisons.alternatives', []),
        );

        return [
            new NavItem(__('Product'), children: [
                new NavItem(__('Features'), url('/#features')),
                new NavItem(__('Pricing'), route('pricing')),
            ]),
            new NavItem(__('Resources'), children: array_values(array_filter([
                Feature::active(Documentation::class) ? new NavItem(__('Help center'), route('help.index')) : null,
                Feature::active(Documentation::class) ? new NavItem(__('Developers'), route('documentation.index')) : null,
                Feature::active(Blog::class) ? new NavItem(__('Blog'), route('blog.index')) : null,
                new NavItem(__('Press kit'), route('press')),
            ]))),
            new NavItem(__('Compare'), children: [...$compare, ...$alternatives]),
            new NavItem(__('Company'), children: [
                new NavItem(__('Contact'), route('contact')),
                new NavItem(__('GitHub'), 'https://github.com/relaticle/relaticle', external: true),
                new NavItem(__('Privacy Policy'), url('privacy-policy')),
                new NavItem(__('Terms of Service'), url('terms-of-service')),
            ]),
        ];
    }
}
```

Check `CompetitorFacts::all()` returns `array<string, array{name: string, ...}>` (it backs the compare pages; read `app/Support/CompetitorFacts.php` first and adjust the two `array_map` calls to its actual shape).

- [ ] **Step 5: Rewrite the footer link area**

Keep the existing brand block (logo, tagline, social icons) and bottom bar structure. Replace the two hardcoded columns with four generated ones inside a labelled `<nav>`; add llms.txt to the bottom bar. Grid: the outer grid stays `md:grid-cols-12`; change the brand block from `md:col-span-5` to `md:col-span-4` and give the nav the remaining `md:col-span-8`.

```blade
@php($columns = app(\App\Support\MarketingNavigation::class)->footer())

<nav aria-label="{{ __('Footer') }}" class="md:col-span-8 grid grid-cols-2 md:grid-cols-4 gap-8">
    @foreach($columns as $column)
        <div>
            <h3 class="font-medium text-xs text-black dark:text-white uppercase tracking-wider mb-4">
                {{ $column->label }}
            </h3>
            <ul class="space-y-3">
                @foreach($column->children as $item)
                    <li>
                        <a href="{{ $item->url }}"
                           @if($item->external) target="_blank" rel="noopener noreferrer" @endif
                           @if(url()->current() === $item->url) aria-current="page" @endif
                           class="text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 text-sm transition-colors">
                            {{ $item->label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
```

Bottom bar addition next to the copyright:

```blade
<a href="{{ route('llms-txt') }}" class="text-gray-500 dark:text-gray-400 text-xs hover:text-primary dark:hover:text-primary-400 transition-colors">llms.txt</a>
```

Dropped from the footer: Home (logo covers it). Everything else from the old footer has a new column home.

- [ ] **Step 6: Run the test, confirm pass**

Run: `php artisan test --compact --filter=MarketingNavigationTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Run the neighbouring suites**

Run: `php artisan test --compact --filter=ComparisonPagesTest && php artisan test --compact tests/Smoke`
Expected: PASS. The smoke suite catches route/name typos.

- [ ] **Step 8: Commit**

```bash
git add app/Support/NavItem.php app/Support/MarketingNavigation.php resources/views/components/layout/footer.blade.php tests/Feature/MarketingNavigationTest.php
git commit -m "feat(marketing): single-source navigation and four-column footer"
```

### Task 2: Header + mobile menu consume the source (drift fix)

**Files:**
- Modify: `resources/views/components/layout/header.blade.php:14-45`
- Modify: `resources/views/components/layout/mobile-menu.blade.php:22-51`
- Test: `tests/Feature/MarketingNavigationTest.php` (append)

**Interfaces:**
- Consumes: `MarketingNavigation::header()`, `::mobile()` from Task 1.

- [ ] **Step 1: Write the failing tests (append to MarketingNavigationTest)**

```php
it('renders the same items in desktop and mobile navigation', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('aria-label="'.__('Main').'"')
        ->and($html)->toContain('aria-label="'.__('Mobile menu').'"')
        ->and(substr_count($html, route('discord')))->toBeGreaterThanOrEqual(3);
});

it('marks external header links with rel noopener', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('rel="noopener noreferrer"');
});

it('marks the current page in the navigation', function (): void {
    $html = $this->get('/pricing')->assertOk()->getContent();

    expect($html)->toContain('aria-current="page"');
});
```

- [ ] **Step 2: Run, confirm the new tests fail**

Run: `php artisan test --compact --filter=MarketingNavigationTest`
Expected: the three new tests FAIL (`aria-label="Main"` absent; Discord appears only twice: header + footer icon; no `rel` on the header Discord link).

- [ ] **Step 3: Rewrite the header nav loop**

Replace lines 14-45 of `header.blade.php` with a loop over `app(\App\Support\MarketingNavigation::class)->header()`, keeping the exact current link classes. `<nav>` gains `aria-label="{{ __('Main') }}"`. External items render the Discord icon pair exactly as today (icon before label, arrow after) when `$item->external` is true. Current-page: same `aria-current` conditional as the footer.

- [ ] **Step 4: Rewrite the mobile menu loop**

Replace the hardcoded `@foreach([...])` + feature blocks in `mobile-menu.blade.php` with one loop over `->mobile()`, keeping the existing typography classes. Result: Discord appears in mobile, Contact disappears from mobile (it lives in the footer Company column). Root `<nav>` keeps `aria-label="{{ __('Mobile menu') }}"`.

- [ ] **Step 5: Run tests + smoke, confirm pass**

Run: `php artisan test --compact --filter=MarketingNavigationTest && php artisan test --compact tests/Smoke`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/views/components/layout/header.blade.php resources/views/components/layout/mobile-menu.blade.php tests/Feature/MarketingNavigationTest.php
git commit -m "feat(marketing): drive header and mobile menu from the nav source"
```

### Task 3: Gates, browser verification, PR-1

- [ ] **Step 1: Quality gates, in order**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run   # apply with vendor/bin/rector if it suggests changes
vendor/bin/phpstan analyse
composer test:type-coverage
php artisan test --compact --filter="MarketingNavigationTest|ComparisonPagesTest"
php artisan test --compact tests/Smoke
```

Expected: all green, no new PHPStan ignores.

- [ ] **Step 2: Browser verification (evidence, not opinion)**

With `agent-browser` (unique `--session`), on the local site: homepage light + dark, footer columns render and links resolve (click one comparison link), mobile viewport (390x844) menu shows Discord and not Contact, `aria-current` present on /pricing (via `agent-browser eval`). Screenshot each state.

- [ ] **Step 3: Push and open PR-1**

```bash
git push -u origin ManukMinasyan/header-footer-nav-options
gh pr create --base main --title "feat(marketing): single-source nav and four-column footer" --body "<what/why + test plan + screenshots>"
```

PR body: what changed, why (drift + sitemap crawl constraint), test plan, screenshots. No AI attribution.

---

# PR-2: Product pages + mega-menu header

Prerequisites: PR-1 merged (or branch created from it), PR #506 merged to main (provides `config('chat.assistant_name')`).

```bash
git checkout -b ManukMinasyan/marketing-product-pages   # from the PR-1 branch
```

### Task 4: `/ai` page (Rela)

**Files:**
- Modify: `routes/web.php` (marketing middleware group, after `/press`)
- Create: `resources/views/ai.blade.php`
- Test: `tests/Feature/ProductPagesTest.php`

**Interfaces:**
- Produces: route name `ai`, URL `/ai`. Task 6 links it from `Product ▾` and the footer.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

it('renders the Rela page with verified capability claims', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(config('chat.assistant_name'))
        ->and($html)->toContain(__('Nothing writes without your approval'))
        ->and($html)->toContain(__('MCP'))
        ->and($html)->toContain('"FAQPage"')
        ->and($html)->toContain('"BreadcrumbList"');
});

it('does not claim hosted models ship with self-hosted installs', function (): void {
    $html = $this->get('/ai')->assertOk()->getContent();

    expect($html)->toContain(__('bring your own API key'));
});
```

- [ ] **Step 2: Run, confirm 404 failure**

Run: `php artisan test --compact --filter=ProductPagesTest`
Expected: FAIL (404).

- [ ] **Step 3: Add the route**

In the `ProvideMarkdownResponse` + `AddVaryAcceptHeader` group in `routes/web.php`:

```php
Route::get('/ai', fn () => view('ai'))->name('ai');
```

- [ ] **Step 4: Build the page**

`<x-guest-layout title="Rela: AI Assistant for Relaticle CRM - Relaticle" description="..." >`. Follow `pricing.blade.php` section patterns and `theme.css` tokens. Sections and exact copy anchors (every claim maps to the spec's verified inventory):

1. **Hero.** Badge "Meet {{ config('chat.assistant_name') }}". H1: `__('The AI teammate inside your CRM')`. Sub: `__('Rela does real CRM work: it finds, creates, and updates your records. You approve every change before it happens.')` CTA pair: Start for free (register) / See it work (anchor to demo).
2. **Demo** (Task 5).
3. **Capabilities grid** (six cards, `line` icons): create and update any record (companies, people, opportunities, tasks, notes); batch operations in one approval; knows your custom fields automatically; answers product questions from the docs; finds and summarizes your data; guides you to the right page instead of refusing.
4. **Control section.** H2 `__('You stay in control')`. Three items: `__('Nothing writes without your approval')`, all-or-nothing batches, transparent credits with model choice (Claude and GPT models, plan-gated).
5. **MCP section.** H2 `__('Use Relaticle from Claude or ChatGPT')`. Copy: built-in MCP server, works with Claude, ChatGPT, and any MCP client. Link `/developers`.
6. **Self-hosted AI.** Copy: on your own server, bring your own API key or run local models with Ollama. Link `/self-hosted`. This is the sentence the honesty test asserts.
7. **FAQ** via `x-marketing.faq-accordion`, minimum four Q&As: What can Rela change in my CRM? (anything you can, with approval) / Does Rela invent data? (works on your records and docs) / Which models power Rela? (catalog; self-hosted brings its own) / Is Rela included in the free plan? (per current pricing page facts).
8. **CTA.**

JSON-LD in `@php` at the bottom (compare-page pattern): `Schema::fAQPage()->mainEntity([...Schema::question()->name(...)->acceptedAnswer(Schema::answer()->text(...))])` plus `Schema::breadcrumbList()` (Home > Rela).

- [ ] **Step 5: Run tests, confirm pass**

Run: `php artisan test --compact --filter=ProductPagesTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php resources/views/ai.blade.php tests/Feature/ProductPagesTest.php
git commit -m "feat(marketing): add rela product page at /ai"
```

### Task 5: `/ai` interactive demo

**Files:**
- Modify: `resources/views/ai.blade.php` (demo section)

- [ ] **Step 1: Embed the existing mockup**

Wrap `@include('home.partials.hero-agent-preview')` in the demo section container (same panel sizing as the homepage hero uses around the include, read `hero.blade.php:120-130` for the wrapper classes).

- [ ] **Step 2: Trigger on scroll-into-view**

The preview animates on the `hero-chat-animate` window event and self-guards reduced motion. Dispatch once when visible:

```blade
<div x-data x-init="
    const observer = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting) {
            window.dispatchEvent(new CustomEvent('hero-chat-animate'));
            observer.disconnect();
        }
    }, { threshold: 0.4 });
    observer.observe($el);
">
    @include('home.partials.hero-agent-preview')
</div>
```

- [ ] **Step 3: Verify in the browser**

agent-browser: scroll to the demo, confirm the conversation plays (composer types, proposal card appears, approve fires), light + dark, mobile. Confirm no console errors via `agent-browser errors`. If the partial assumes homepage-only context (any `$refs` outside itself), copy it to `resources/views/ai/partials/demo.blade.php` and trim instead; do not modify the homepage partial.

- [ ] **Step 4: Commit**

```bash
git add resources/views/ai.blade.php
git commit -m "feat(marketing): animated rela approval demo on /ai"
```

### Task 6: `/self-hosted` page

**Files:**
- Modify: `routes/web.php`
- Create: `resources/views/self-hosted.blade.php`
- Test: `tests/Feature/ProductPagesTest.php` (append)

- [ ] **Step 1: Write the failing tests (append)**

```php
it('renders the self-hosted page with the real quick start', function (): void {
    $html = $this->get('/self-hosted')->assertOk()->getContent();

    expect($html)->toContain('docker compose up -d')
        ->and($html)->toContain('AGPL')
        ->and($html)->toContain(__('Ollama'))
        ->and($html)->toContain('"FAQPage"');
});

it('links the full self-hosting guide', function (): void {
    $this->get('/self-hosted')->assertOk()->assertSee('/developers/self-hosting');
});
```

- [ ] **Step 2: Run, confirm 404 failure**

Run: `php artisan test --compact --filter=ProductPagesTest`
Expected: new tests FAIL.

- [ ] **Step 3: Route**

```php
Route::get('/self-hosted', fn () => view('self-hosted'))->name('selfHosted');
```

- [ ] **Step 4: Build the page**

`<x-guest-layout title="Self-Hosted CRM - Relaticle" ...>`. Sections:

1. **Hero.** H1 `__('Your CRM. Your server. Your data.')`. Sub: open source under AGPL-3.0, unlimited users, no per-seat pricing. Code block, verbatim from the guide (`packages/Documentation/resources/content/docs/guides/self-hosting.md:16-39`):

```bash
curl -o compose.yml https://raw.githubusercontent.com/Relaticle/relaticle/main/compose.yml
docker compose up -d
```

2. **Why self-host.** Three cards: data ownership, AGPL-3.0 license, flat cost on your hardware.
3. **Quick start** (3 steps distilled from the guide), closing link to `/developers/self-hosting`.
4. **AI on your server.** Rela works self-hosted: bring your own API key or run local models with Ollama (`OLLAMA_BASE_URL`). State plainly the hosted model catalog is Relaticle Cloud.
5. **Cloud vs self-hosted table.** Honest both ways: managed updates, backups, hosted AI catalog vs full control, own infrastructure, own keys.
6. **Community.** GitHub (`$formattedGithubStars` if available in this view; otherwise plain link), Discord, contributing link to `/developers/contributing`.
7. **FAQ** + FAQPage/BreadcrumbList schema: license, updating (pull the new image), what AI needs, where data lives.
8. **CTA pair:** Deploy self-hosted (GitHub repo) / Try Relaticle Cloud (register).

- [ ] **Step 5: Run tests, confirm pass**

Run: `php artisan test --compact --filter=ProductPagesTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php resources/views/self-hosted.blade.php tests/Feature/ProductPagesTest.php
git commit -m "feat(marketing): add self-hosted product page"
```

### Task 7: Mega-menu header + mobile accordion + star badge

**Files:**
- Modify: `app/Support/MarketingNavigation.php` (header/mobile trees + footer additions)
- Modify: `resources/views/components/layout/header.blade.php`
- Modify: `resources/views/components/layout/mobile-menu.blade.php`
- Test: `tests/Feature/MarketingNavigationTest.php` (append)

**Interfaces:**
- Consumes: `NavItem` children (a `NavItem` with `url === null` and non-empty `children` is a dropdown group; a child with `url === null` and children is a labelled sub-column).

- [ ] **Step 1: Write the failing tests (append)**

```php
it('renders product and resources dropdown groups with new page links', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('aria-expanded')
        ->and($html)->toContain(route('ai'))
        ->and($html)->toContain(route('selfHosted'))
        ->and($html)->toContain(__('Compare'));
});

it('shows the cached github star count in the header', function (): void {
    \Illuminate\Support\Facades\Cache::put('github_stars_Relaticle_relaticle', 1517, 60);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('1.5K');
});
```

Before asserting `1.5K`, check `Number::abbreviate(1517, 1)` output format with tinker (`php artisan tinker --execute 'echo Illuminate\Support\Number::abbreviate(1517, 1);'`) and match exactly.

- [ ] **Step 2: Run, confirm failure**

Run: `php artisan test --compact --filter=MarketingNavigationTest`
Expected: new tests FAIL.

- [ ] **Step 3: Update the trees**

`header()` becomes:

```php
return array_values(array_filter([
    new NavItem(__('Product'), children: array_values(array_filter([
        new NavItem(__('Rela'), route('ai')),
        new NavItem(__('Features'), url('/#features')),
        new NavItem(__('Self-hosted'), route('selfHosted')),
        Feature::active(Documentation::class) ? new NavItem(__('MCP & API'), route('documentation.index')) : null,
    ]))),
    new NavItem(__('Resources'), children: array_values(array_filter([
        new NavItem(__('Resources'), children: array_values(array_filter([
            Feature::active(Documentation::class) ? new NavItem(__('Help center'), route('help.index')) : null,
            Feature::active(Documentation::class) ? new NavItem(__('Developers'), route('documentation.index')) : null,
            Feature::active(Blog::class) ? new NavItem(__('Blog'), route('blog.index')) : null,
        ]))),
        new NavItem(__('Compare'), children: [...$compare, ...$alternatives]),
    ]))),
    new NavItem(__('Pricing'), route('pricing')),
    new NavItem(__('Discord'), route('discord'), external: true),
]));
```

Extract the compare/alternatives builders into private methods shared with `footer()`. Footer Product column gains `Rela` (route `ai`) and `Self-hosted` (route `selfHosted`).

- [ ] **Step 4: Header dropdown markup**

Per top-level group, click-activated Alpine dropdown (NN/g: click, not hover):

```blade
<div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" class="relative">
    <button @click="open = !open" :aria-expanded="open" aria-controls="menu-{{ Str::slug($item->label) }}"
            class="px-4 py-1.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white text-[13px] font-medium transition-colors flex items-center gap-1 cursor-pointer">
        {{ $item->label }}
        <x-ri-arrow-down-s-line class="w-3.5 h-3.5 transition-transform duration-150" ::class="open && 'rotate-180'"/>
    </button>
    <div x-show="open" x-transition.opacity.duration.150ms x-cloak id="menu-{{ Str::slug($item->label) }}"
         class="absolute left-0 top-full mt-2 min-w-56 rounded-xl border border-gray-200/60 dark:border-white/[0.06] bg-white dark:bg-gray-950 shadow-lg p-2">
        {{-- single column: children links; two columns when a child is itself a group --}}
    </div>
</div>
```

Two-column panel (Resources): grid `grid-cols-2 gap-6 min-w-[26rem] p-4`, each sub-group renders its label as a small uppercase heading above its links. Star badge after the Sign In / Start for free pair:

`GitHubService` returns `0` on API failure, so guard on the count, not on presence (a "0 stars" badge is worse than no badge; falls back to nothing, GitHub stays linked in the footer):

```blade
@if(($githubStars ?? 0) > 0)
    <a href="https://github.com/relaticle/relaticle" target="_blank" rel="noopener noreferrer"
       class="hidden md:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-200/80 dark:border-white/[0.08] text-[13px] font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition-colors min-w-[4.5rem] justify-center">
        <x-ri-github-fill class="w-4 h-4"/>{{ $formattedGithubStars }}
    </a>
@endif
```

`min-w` reserves space (no layout shift). The View composer already targets `components.layout.header`; no provider change needed.

- [ ] **Step 5: Mobile accordion**

In `mobile-menu.blade.php`, a group item renders as an Alpine expand-in-place block (chevron rotates, children indent); flat items unchanged. Same tree, no second source.

- [ ] **Step 6: Run tests, confirm pass**

Run: `php artisan test --compact --filter="MarketingNavigationTest|ProductPagesTest"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Support/MarketingNavigation.php resources/views/components/layout/header.blade.php resources/views/components/layout/mobile-menu.blade.php tests/Feature/MarketingNavigationTest.php
git commit -m "feat(marketing): mega-menu header, mobile accordion, github star badge"
```

### Task 8: llms.txt entries + final gates + PR-2

**Files:**
- Modify: `packages/Documentation/src/Http/Controllers/HelpController.php` (`llmsTxtBody()`)
- Test: existing llms.txt test file (find with `grep -rln "llms" tests/Feature/` and extend there per test-organization rules)

- [ ] **Step 1: Failing test (in the existing llms.txt test file)**

```php
it('lists the product pages in llms.txt', function (): void {
    $body = $this->get('/llms.txt')->assertOk()->getContent();

    expect($body)->toContain(route('ai'))
        ->and($body)->toContain(route('selfHosted'));
});
```

- [ ] **Step 2: Add a Product section to `llmsTxtBody()`**

After the comparisons block, before Company:

```php
$lines[] = '';
$lines[] = '## '.__('Product');
$lines[] = '';
$lines[] = sprintf('- [%s](%s): %s',
    __('Rela, the AI Assistant'), route('ai'),
    __('What the built-in AI assistant does, the approval flow, model choice, and MCP access.'));
$lines[] = sprintf('- [%s](%s): %s',
    __('Self-Hosted CRM'), route('selfHosted'),
    __('Run Relaticle on your own server with Docker Compose, AGPL-3.0, and local AI via Ollama.'));
```

- [ ] **Step 3: Run the llms + full marketing tests**

Run: `php artisan test --compact --filter="Llms|MarketingNavigationTest|ProductPagesTest|ComparisonPagesTest"` then `php artisan test --compact tests/Smoke`
Expected: PASS.

- [ ] **Step 4: Quality gates (same order as Task 3)**

All five gates green; no new ignores.

- [ ] **Step 5: Browser verification**

agent-browser, light + dark + mobile: dropdowns open on click, close on Escape and outside click, caret rotates; `/ai` demo plays on scroll; `/self-hosted` renders; star badge shows; mobile accordion expands; `agent-browser errors` clean. Screenshots for the PR.

- [ ] **Step 6: Full suite + push + PR**

```bash
composer test:pest
git push -u origin ManukMinasyan/marketing-product-pages
gh pr create --base ManukMinasyan/header-footer-nav-options --title "feat(marketing): rela and self-hosted pages with mega-menu header" --body "<what/why + test plan + screenshots>"
```

Retarget to `main` after PR-1 merges. Remind: `php artisan app:generate-sitemap` runs daily; the new pages enter the sitemap on the first run after deploy (24h lag is known and accepted).
