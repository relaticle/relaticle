# Help Centre (W2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Ship `relaticle.com/help` — a markdown-driven, in-repo help centre where nav, breadcrumbs, search index, `.md` variants, JSON-LD and sitemap entries all derive from the content files — and migrate the existing five `/docs` guides onto the same engine.

**Architecture:** Evolve `packages/Documentation` (`Relaticle\Documentation`) into a front-matter-driven engine rather than standing up a second parallel markdown system. Content lives at `packages/Documentation/resources/content/{area}/{category}/{slug}.md` with a category `_index.md`. A repository scans it into a cached manifest; readonly value objects carry pages and categories; controllers render hub / category / article. The existing config-listed `/docs` guides migrate onto the same path, retiring `DocumentationService`'s config-array approach with no dual path.

**Tech Stack:** Laravel 12 / PHP 8.4, `spatie/laravel-markdown` (installed), `spatie/schema-org` (installed), `spatie/laravel-sitemap` (installed), `symfony/yaml` (transitive, verify), Pest 5.

**Reference implementation to port** (different project, same pattern — read before writing, do not copy namespaces): `/Users/manuk/.polyscope/clones/bb47ac3e/noble-hedgehog/packages/docs/`. Core files: `src/Support/{DocsRepository,DocPage,DocCategory,RenderDocMarkdown,BuildSearchIndex,DocsJsonLd}.php` (~370 lines total), `src/Http/Controllers/DocsController.php` (249), `routes/web.php` (40), `resources/views/{hub,category,article,404}.blade.php`. Its content conventions are in `packages/docs/README.md` — adopt them.

## Global Constraints

- Pre-commit gates in order: `vendor/bin/pint --dirty --format agent` → `vendor/bin/rector --dry-run` (apply if suggested) → `vendor/bin/phpstan analyse` → `composer test:type-coverage` (100%) → focused `php artisan test --compact --filter=...`. Plus `php -d memory_limit=2G vendor/bin/pest tests/Arch` (OOMs at 128M).
- **No new composer/npm dependencies.** Verify `symfony/yaml` is already available before using it; if not, parse front matter with `league/commonmark`'s `FrontMatterExtension`, which ships with `spatie/laravel-markdown`.
- No new PHPStan ignores. Level 7, no baseline. `config()` returns `mixed` — narrow before use.
- `declare(strict_types=1);` everywhere; explicit types on every parameter, return, and closure.
- Package service layers must be `final readonly` and extend nothing (`tests/Arch/ArchTest.php`); new package namespaces need conscious arch coverage (`tests/Arch/ConventionsTest.php`).
- All user-facing strings in `__()`. `packages/Documentation` already follows this (unlike `packages/SystemAdmin`).
- Tests live only in `tests/{Arch,PHPStan,Smoke,Feature,Browser}`. Use `tests/Feature/Documentation/`.
- Blade: 4-space indent, no spaces after control structures. Use existing design tokens; match `x-guest-layout` and the current docs views.
- Titles `<Base Title> - Relaticle` (base ≤60 chars, unique); descriptions 140-160 chars, unique, no invented claims.
- Never document flag-gated or unreleased behaviour. Check `app/Features` before writing about anything.
- Any Blade change gets an agent-browser pass (light + dark + mobile) against **https://dalat.test** with a unique `--session`.
- No AI/Claude attribution in commits. Do not push or open a PR — this work lands on the existing branch for PR #456.

---

### Task 1: Content model — repository, value objects, renderer

**Files:**
- Create: `packages/Documentation/src/Support/{DocPage,DocCategory,DocsRepository,RenderDocMarkdown}.php`
- Create: `packages/Documentation/resources/content/help/getting-started/_index.md` and one real article (used as the test fixture)
- Modify: `packages/Documentation/config/documentation.php` (add `content_path`)
- Test: `tests/Feature/Documentation/DocsRepositoryTest.php`

**Interfaces produced** (later tasks depend on these exact shapes):
- `DocPage` — `final readonly`, constructor-promoted: `string $path` (`{area}/{category}/{slug}`), `string $area`, `string $category`, `string $slug`, `string $title`, `string $description`, `int $order`, `list<string> $related`, `string $body` (raw markdown), `?CarbonImmutable $updated`.
- `DocCategory` — `final readonly`: `string $path` (`{area}/{category}`), `string $area`, `string $title`, `string $description`, `int $order`, `string $body`.
- `DocsRepository` — `pages(): Collection<string, DocPage>`, `categories(): Collection<string, DocCategory>`, `find(string $path): ?DocPage`, `findCategory(string $path): ?DocCategory`, `pagesIn(string $categoryPath): Collection<string, DocPage>` (ordered by `order`), `clearCache(): void`. Registered as a singleton in `DocumentationServiceProvider`.
- `RenderDocMarkdown` — `__invoke(DocPage $page): string` returning HTML via the app's `MarkdownRenderer`.

Front matter (required: `title`, `description`, `order`; optional: `related`, `updated`):

```yaml
---
title: Create your first company
description: Add a company record and fill the fields your team actually uses.
order: 1
updated: "2026-08-12"
related: [help/records/people, help/getting-started/invite-your-team]
---
```

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Relaticle\Documentation\Support\DocsRepository;

it('parses a content file into a page keyed by its path', function (): void {
    $page = app(DocsRepository::class)->find('help/getting-started/create-your-first-company');

    expect($page)->not->toBeNull()
        ->and($page->title)->toBe('Create your first company')
        ->and($page->area)->toBe('help')
        ->and($page->category)->toBe('getting-started')
        ->and($page->order)->toBe(1)
        ->and($page->body)->not->toContain('---')
        ->and($page->updated?->format('Y-m-d'))->toBe('2026-08-12');
});

it('parses a category _index file', function (): void {
    $category = app(DocsRepository::class)->findCategory('help/getting-started');

    expect($category)->not->toBeNull()
        ->and($category->title)->not->toBeEmpty()
        ->and($category->description)->not->toBeEmpty();
});

it('orders pages within a category by their order key', function (): void {
    $orders = app(DocsRepository::class)->pagesIn('help/getting-started')->map(fn ($p): int => $p->order)->values()->all();

    expect($orders)->toBe(array_values(array_unique($orders)))
        ->and($orders)->toBe(collect($orders)->sort()->values()->all());
});

it('does not expose _index files as pages', function (): void {
    expect(app(DocsRepository::class)->pages()->keys()->filter(fn (string $k): bool => str_contains($k, '_index')))->toBeEmpty();
});
```

- [ ] **Step 2: Run it, confirm it fails**

Run: `php artisan test --compact --filter=DocsRepositoryTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement**

Port the reference's `DocsRepository::load()` shape: glob `{content_path}/**/*.md`, split front matter, derive `area`/`category`/`slug` from the path (never duplicate them in front matter), build two keyed collections, treat `_index.md` as the category record. Cache the manifest keyed by a hash of the content directory's file list + mtimes so an edit busts it; skip caching when `config('app.debug')` is true. Fail loudly on a missing required front-matter key — a silent default produces a page with an empty `<title>`.

- [ ] **Step 4: Run tests, then the whole Documentation suite**

Run: `php artisan test --compact --filter=DocsRepositoryTest` then `--filter=Documentation`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/Documentation tests/Feature/Documentation/DocsRepositoryTest.php
git commit -m "feat(help): front-matter content model for the help centre"
```

### Task 2: Routes, controller, and views

**Files:**
- Create: `packages/Documentation/src/Http/Controllers/HelpController.php`
- Create: `packages/Documentation/resources/views/help/{hub,category,article}.blade.php`
- Modify: `packages/Documentation/routes/web.php`
- Test: `tests/Feature/Documentation/HelpRoutesTest.php`

**Interfaces consumed:** `DocsRepository`, `RenderDocMarkdown` from Task 1.
**Interfaces produced:** named routes `help.index`, `help.category`, `help.show`; view namespace `documentation::help.*`.

Routes, registered inside the existing `ProvideMarkdownResponse` middleware group so `.md` variants and `Accept: text/markdown` work for free:

```php
Route::middleware([ProvideMarkdownResponse::class])->prefix('help')->name('help.')->group(function (): void {
    Route::get('/', [HelpController::class, 'index'])->name('index');
    Route::get('/{category}', [HelpController::class, 'category'])->name('category')->where('category', '[a-z0-9-]+');
    Route::get('/{category}/{slug}', [HelpController::class, 'show'])->name('show')->where(['category' => '[a-z0-9-]+', 'slug' => '[a-z0-9-]+']);
});
```

Controller: `index()` renders category cards ordered by `order`; `category()` 404s on an unknown category and lists its pages; `show()` 404s on an unknown page and passes the rendered HTML, breadcrumbs, prev/next within the category, and resolved `related` pages.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

it('renders the help hub with category cards', function (): void {
    $this->get('/help')->assertOk()->assertSee('Getting started', false);
});

it('renders a category page', function (): void {
    $this->get('/help/getting-started')->assertOk();
});

it('renders an article with its rendered body', function (): void {
    $this->get('/help/getting-started/create-your-first-company')
        ->assertOk()
        ->assertSee('Create your first company', false);
});

it('404s an unknown category and an unknown article', function (): void {
    $this->get('/help/no-such-category')->assertNotFound();
    $this->get('/help/getting-started/no-such-article')->assertNotFound();
});

it('serves an article as markdown to an agent', function (): void {
    $markdown = $this->get('/help/getting-started/create-your-first-company', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('Create your first company')
        ->and($markdown)->not->toContain('Start for free');
});
```

- [ ] **Step 2: Run it, confirm it fails** — `--filter=HelpRoutesTest`, expect 404s.

- [ ] **Step 3: Implement** the controller, routes, and three views. Views extend the existing marketing layout (`x-guest-layout`) and reuse the current docs views' sidebar/TOC/prose styling — read `packages/Documentation/resources/views/show.blade.php` first and match it. Sidebar and TOC must be inside `<nav>` elements so the markdown preprocessors strip them (this is why the last PR made the docs sidebar semantic — same rule).

- [ ] **Step 4: Run `--filter=HelpRoutesTest`, `--filter=Documentation`, `--filter=Smoke`.**

- [ ] **Step 5: agent-browser** `/help`, `/help/getting-started`, and one article in light, dark, and mobile. Confirm the layout matches the existing docs pages.

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(help): hub, category and article routes for the help centre"
```

### Task 3: SEO and GEO plumbing

**Files:**
- Create: `packages/Documentation/src/Support/{DocsJsonLd,BuildSearchIndex}.php`
- Modify: `HelpController` (search index + llms.txt actions), `packages/Documentation/routes/web.php`
- Modify: `app/Console/Commands/GenerateSitemapCommand.php`
- Test: `tests/Feature/Documentation/HelpSeoTest.php`

**Interfaces produced:** `GET /help/search-index.json` (shape `{v: 2, records: [...]}` with section-level records), `GET /llms.txt`, `Article` + `BreadcrumbList` JSON-LD on every article.

This is where the deferred `<lastmod>` finally lands — sourced from front-matter `updated:`, which is a real content date, unlike the file mtime the last PR rejected.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

it('emits article and breadcrumb json-ld on a help article', function (): void {
    $html = $this->get('/help/getting-started/create-your-first-company')->assertOk()->getContent();

    expect($html)->toContain('"@type":"Article"')
        ->and($html)->toContain('"@type":"BreadcrumbList"');
});

it('serves a section-level search index', function (): void {
    $payload = $this->get('/help/search-index.json')->assertOk()->json();

    expect($payload['v'])->toBe(2)
        ->and($payload['records'])->not->toBeEmpty()
        ->and($payload['records'][0])->toHaveKeys(['path', 'title', 'section', 'content']);
});

it('serves an llms.txt indexing help and docs', function (): void {
    $this->get('/llms.txt')->assertOk()->assertHeader('content-type', 'text/plain; charset=UTF-8')
        ->assertSee('/help/getting-started/create-your-first-company');
});

it('puts help urls in the sitemap with a lastmod from front matter', function (): void {
    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = File::get(public_path('sitemap.xml'));

    expect($xml)->toContain('/help/getting-started/create-your-first-company')
        ->and($xml)->toMatch('/getting-started\/create-your-first-company<\/loc>\s*<lastmod>2026-08-12/');
});
```

(Restore `public/sitemap.xml` in the test's teardown — follow the existing `GenerateSitemapCommandTest` pattern, and note that file is untracked.)

- [ ] **Step 2: Run it, confirm each assertion fails for the right reason.**

- [ ] **Step 3: Implement.** JSON-LD via `spatie/schema-org`, matching the inline `Graph` pattern in `resources/views/home/index.blade.php`. Search index: one record per `##` section, `{path, title, section, anchor, content}`, cached. `llms.txt` generated from the manifest — a plain index of help + docs URLs with descriptions, nothing more (per the spec's anti-goals it is an agent-discovery aid, never sold as a ranking play). Sitemap: append help URLs from the repository with `<lastmod>` from `updated:`, only when set.

- [ ] **Step 4: Run `--filter=HelpSeoTest`, `--filter=GenerateSitemapCommandTest`, `--filter=Documentation`.**

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(help): json-ld, search index, llms.txt and sitemap entries"
```

### Task 4: Link integrity and content-quality guards

**Files:**
- Test: `tests/Feature/Documentation/HelpContentIntegrityTest.php`

These tests are the reason the docs-as-code approach is durable — they make a broken link or a missing image a failing build rather than a customer's dead end.

- [ ] **Step 1: Write the tests** (they must pass immediately against Task 1's content; they are guards, not TDD drivers)

```php
<?php

declare(strict_types=1);

use Relaticle\Documentation\Support\DocsRepository;

it('resolves every related entry to a real page', function (): void {
    $repo = app(DocsRepository::class);

    $broken = $repo->pages()->flatMap(fn ($page): array => collect($page->related)
        ->reject(fn (string $r): bool => $repo->find($r) instanceof \Relaticle\Documentation\Support\DocPage)
        ->map(fn (string $r): string => "{$page->path} -> {$r}")
        ->all());

    expect($broken)->toBeEmpty();
});

it('resolves every internal link to a real page', function (): void {
    $repo = app(DocsRepository::class);

    $broken = $repo->pages()->flatMap(function ($page) use ($repo): array {
        preg_match_all('/\]\((\/help\/[a-z0-9\-\/]+)\)/', $page->body, $m);

        return collect($m[1])
            ->reject(fn (string $url): bool => $repo->find(ltrim($url, '/')) instanceof \Relaticle\Documentation\Support\DocPage)
            ->map(fn (string $url): string => "{$page->path} -> {$url}")
            ->all();
    });

    expect($broken)->toBeEmpty();
});

it('resolves every referenced image to a file on disk', function (): void {
    $broken = app(DocsRepository::class)->pages()->flatMap(function ($page): array {
        preg_match_all('/!\[[^\]]*\]\((\/[^)]+)\)/', $page->body, $m);

        return collect($m[1])
            ->reject(fn (string $src): bool => file_exists(public_path(ltrim($src, '/'))))
            ->map(fn (string $src): string => "{$page->path} -> {$src}")
            ->all();
    });

    expect($broken)->toBeEmpty();
});

it('gives every page a unique, length-bounded title and description', function (): void {
    $pages = app(DocsRepository::class)->pages();

    expect($pages->pluck('title')->duplicates())->toBeEmpty()
        ->and($pages->pluck('description')->duplicates())->toBeEmpty()
        ->and($pages->filter(fn ($p): bool => mb_strlen($p->title) > 60)->keys())->toBeEmpty()
        ->and($pages->filter(fn ($p): bool => mb_strlen($p->description) > 160)->keys())->toBeEmpty();
});

it('gives every page exactly one h1-equivalent, which is its front-matter title', function (): void {
    $offenders = app(DocsRepository::class)->pages()
        ->filter(fn ($p): bool => (bool) preg_match('/^# /m', $p->body))
        ->keys();

    expect($offenders)->toBeEmpty();
});
```

- [ ] **Step 2: Run them.** If any fails, the *content* is wrong — fix the content, never weaken the guard.

- [ ] **Step 3: Commit**

```bash
git commit -m "test(help): link, asset, metadata and heading integrity guards"
```

### Task 5: Migrate the existing `/docs` guides onto the engine

**Files:**
- Move: `packages/Documentation/resources/markdown/*.md` → `packages/Documentation/resources/content/docs/{category}/{slug}.md` with front matter added
- Modify: `packages/Documentation/src/Http/Controllers/DocumentationController.php`, `routes/web.php`, views
- Delete: `packages/Documentation/src/Services/DocumentationService.php` and the `documents` config array
- Modify: `routes/web.php` (the Task 1 legacy redirect map from the last PR reads `config('documentation.documents')` — repoint it at the repository)
- Test: existing `tests/Feature/Documentation/*` must keep passing unchanged where they assert public behaviour

**No dual path.** The config-array approach is retired in this task, not left alongside.

**URLs must not change.** `/docs`, `/docs/getting-started`, `/docs/import`, `/docs/developer`, `/docs/self-hosting`, `/docs/mcp` are indexed. `/docs/api` is a URL entry pointing at the Scribe reference — keep it working.

- [ ] **Step 1: Write the guard test first**

```php
it('keeps every existing docs url working after the migration', function (string $path): void {
    $this->get($path)->assertOk();
})->with(['/docs', '/docs/getting-started', '/docs/import', '/docs/developer', '/docs/self-hosting', '/docs/mcp']);

it('keeps the legacy documentation redirect map working', function (): void {
    $this->get('/documentation/quickstart')->assertRedirect('/docs/getting-started');
    $this->get('/documentation/unknown')->assertRedirect('/docs');
});
```

- [ ] **Step 2: Run it — green now, and it must stay green through the migration.**

- [ ] **Step 3: Migrate.** Add front matter to each guide (title/description from the retiring config array — they are already written and accurate), place under `content/docs/`, repoint the controller and the legacy redirect at `DocsRepository`, delete `DocumentationService` and the config array.

- [ ] **Step 4: Run `--filter=Documentation`, `--filter=MarketingRedirectsTest`, `--filter=Smoke`, and the full suite.** Verify `/docs/mcp` still renders clean headings (the Task 4 fix from the last PR) and that its meta description is still the real per-type one.

- [ ] **Step 5: agent-browser** every migrated `/docs` page, light and dark.

- [ ] **Step 6: Commit**

```bash
git commit -m "refactor(docs): move the developer guides onto the help-centre engine"
```

### Task 6: First help content wave — Getting started

**Files:**
- Create: `packages/Documentation/resources/content/help/getting-started/*.md` (5 articles + `_index.md`)
- Create: screenshots under `public/help-assets/getting-started/`

Content conventions, adopted from the reference's `README.md`:

- **Answer first.** The opening paragraph answers the question the title asks, in at most three sentences. No "In this article we will…".
- Second person, outcome-first. Steps are numbered lists, one action per step.
- UI names exact and bolded, copied from the running app — if you have not seen the label in the app, do not write it.
- Troubleshooting headings use "If X, then Y" form.
- Internal links root-relative (`/help/records/companies`).
- Titles in the user's vocabulary — what they would type into a search box, not our feature name.
- Never document flag-gated behaviour (check `app/Features`).

Articles: create your first company · add people and link them to companies · track a deal through the pipeline · use custom fields · invite your team.

- [ ] **Step 1: Walk the real app** with agent-browser at https://dalat.test and capture what each flow actually looks like. Do not write from assumption — this codebase has already produced two false claims written from plausible-sounding memory.
- [ ] **Step 2: Capture screenshots** per the `screenshot-with-callout` skill, light mode, saved to `public/help-assets/getting-started/{slug}-{n}.png`, referenced root-relative with descriptive alt text.
- [ ] **Step 3: Write the five articles + `_index.md`.**
- [ ] **Step 4: Run `--filter=HelpContentIntegrityTest`** — it enforces unique bounded metadata, resolvable links, and present images.
- [ ] **Step 5: agent-browser** each article, light + dark + mobile; check the markdown variant of one with `curl -H 'Accept: text/markdown'`.
- [ ] **Step 6: Commit**

```bash
git commit -m "docs(help): getting-started articles"
```

### Task 7: Wire the help centre into navigation and finish

**Files:**
- Modify: `resources/views/components/layout/header.blade.php`, `mobile-menu.blade.php`, `footer.blade.php` (a Help link)
- Modify: `docs/superpowers/specs/2026-08-11-seo-geo-strategy-design.md` (mark W2 shipped, record any scope changes)
- Test: `tests/Feature/Documentation/HelpRoutesTest.php` (nav link present)

- [ ] **Step 1:** Add the Help link to the marketing nav and footer, wrapped in `__()`. A help centre nothing links to earns no internal-link equity — this is the step that makes it discoverable.
- [ ] **Step 2:** Run the full gate set and the whole suite.
- [ ] **Step 3:** agent-browser the nav in light, dark and mobile.
- [ ] **Step 4:** Update the spec's W2 section to describe what shipped.
- [ ] **Step 5:** Commit and push to the existing branch so it joins PR #456.

```bash
git commit -m "feat(help): link the help centre from the marketing nav"
git push origin HEAD:refs/heads/ManukMinasyan/docs-package-reference
```
