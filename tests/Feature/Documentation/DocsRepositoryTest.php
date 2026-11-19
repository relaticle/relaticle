<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Documentation\Support\BuildSearchIndex;
use Relaticle\Documentation\Support\DocsRepository;

mutates(DocsRepository::class, BuildSearchIndex::class);

beforeEach(function (): void {
    $this->fixturePath = storage_path('framework/testing/docs-repository-'.Str::random(8));
});

afterEach(function (): void {
    File::deleteDirectory($this->fixturePath);
});

function writeFeatureGatedFixture(string $root, string $feature): void
{
    File::ensureDirectoryExists("{$root}/help/gated");
    File::ensureDirectoryExists("{$root}/help/open");
    File::put("{$root}/help/gated/_index.md", "---\ntitle: Gated\ndescription: A gated category.\norder: 1\nfeature: {$feature}\n---\n\nBody.\n");
    File::put("{$root}/help/gated/inside.md", "---\ntitle: Inside\ndescription: A page in the gated category.\norder: 1\n---\n\nBody.\n");
    File::put("{$root}/help/open/_index.md", "---\ntitle: Open\ndescription: An open category.\norder: 2\n---\n\nBody.\n");
    File::put("{$root}/help/open/outside.md", "---\ntitle: Outside\ndescription: A page in the open category.\norder: 1\n---\n\nBody.\n");

    Config::set('documentation.content_path', $root);
}

function writeLocalizedFixture(string $root): void
{
    File::ensureDirectoryExists("{$root}/help/open");
    File::ensureDirectoryExists("{$root}/de/help/open");
    File::put("{$root}/help/open/_index.md", "---\ntitle: Open\ndescription: An open category.\norder: 1\n---\n\nBody.\n");
    File::put("{$root}/help/open/outside.md", "---\ntitle: Outside\ndescription: An English page.\norder: 1\n---\n\n## Visible\n\nEnglish text.\n");
    File::put("{$root}/help/open/untranslated.md", "---\ntitle: Untranslated\ndescription: Only in English.\norder: 2\n---\n\nBody.\n");
    File::put("{$root}/de/help/open/_index.md", "---\ntitle: Offen\ndescription: Eine offene Kategorie.\norder: 1\n---\n\nInhalt.\n");
    File::put("{$root}/de/help/open/outside.md", "---\ntitle: Draussen\ndescription: Eine deutsche Seite.\norder: 1\n---\n\n## Sichtbar\n\nDeutscher Text.\n");

    Config::set('documentation.content_path', $root);
    Config::set('documentation.cache.enabled', false);
}

it('loads a German page from content/de', function (): void {
    writeLocalizedFixture($this->fixturePath);

    $repository = new DocsRepository;

    expect($repository->find('help/open/outside', 'de')->title)->toBe('Draussen')
        ->and($repository->findCategory('help/open', 'de')->title)->toBe('Offen')
        ->and($repository->pagesIn('help/open', 'de')->keys()->all())->toBe(['help/open/outside'])
        ->and($repository->find('help/open/untranslated', 'de'))->toBeNull();
});

it('reads the current app locale when no locale is passed', function (): void {
    writeLocalizedFixture($this->fixturePath);
    app()->setLocale('de');

    $repository = new DocsRepository;

    expect($repository->find('help/open/outside')->title)->toBe('Draussen')
        ->and($repository->pages()->keys()->all())->toBe(['help/open/outside']);
});

it('keeps the English tree unchanged', function (): void {
    writeLocalizedFixture($this->fixturePath);

    $repository = new DocsRepository;

    expect($repository->pages('en')->keys()->sort()->values()->all())->toBe(['help/open/outside', 'help/open/untranslated'])
        ->and($repository->find('help/open/outside', 'en')->title)->toBe('Outside')
        ->and($repository->categories('en')->keys()->all())->toBe(['help/open']);
});

it('has no pages for a language without a tree', function (): void {
    writeLocalizedFixture($this->fixturePath);

    $repository = new DocsRepository;

    expect($repository->pages('fr'))->toBeEmpty()
        ->and($repository->categories('fr'))->toBeEmpty();
});

it('caches one manifest per language', function (): void {
    writeLocalizedFixture($this->fixturePath);
    Config::set('documentation.cache.enabled', true);
    Cache::forget('documentation.manifest.en');
    Cache::forget('documentation.manifest.de');

    (new DocsRepository)->pages('de');

    expect(Cache::has('documentation.manifest.de'))->toBeTrue()
        ->and(Cache::get('documentation.manifest.de')['pages']->keys()->all())->toBe(['help/open/outside'])
        ->and(Cache::get('documentation.manifest.en')['pages']->keys()->sort()->values()->all())->toBe(['help/open/outside', 'help/open/untranslated']);
});

it('builds one search index per language', function (): void {
    writeLocalizedFixture($this->fixturePath);
    Config::set('documentation.cache.enabled', true);
    Cache::forget('documentation.search-index.en');
    Cache::forget('documentation.search-index.de');

    $german = (new BuildSearchIndex(new DocsRepository))('de')['records'];
    $english = (new BuildSearchIndex(new DocsRepository))('en')['records'];

    expect(array_column($german, 'section'))->toBe(['Sichtbar'])
        ->and(array_column($german, 'crumb'))->each->toBe('Offen')
        ->and(array_column($german, 'url'))->each->toContain('/de/help/open/outside')
        ->and(array_column($english, 'url'))->each->not->toContain('/de/')
        ->and(Cache::has('documentation.search-index.de'))->toBeTrue()
        ->and(Cache::has('documentation.search-index.en'))->toBeTrue();
})->skip(fn (): bool => ! class_exists('Relaticle\Cloud\CloudServiceProvider'), 'Localized help routes come from the cloud package.');

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

it('throws and names the offending file when a page is missing its title', function (): void {
    File::ensureDirectoryExists("{$this->fixturePath}/help/getting-started");
    File::put(
        "{$this->fixturePath}/help/getting-started/broken.md",
        "---\ndescription: A page with no title.\norder: 1\n---\n\nBody.\n",
    );

    Config::set('documentation.content_path', $this->fixturePath);

    expect(fn () => new DocsRepository)
        ->toThrow(RuntimeException::class, 'help/getting-started/broken.md');
});

it('throws and names the offending file when a page is missing its order', function (): void {
    File::ensureDirectoryExists("{$this->fixturePath}/help/getting-started");
    File::put(
        "{$this->fixturePath}/help/getting-started/broken.md",
        "---\ntitle: Broken page\ndescription: A page with no order.\n---\n\nBody.\n",
    );

    Config::set('documentation.content_path', $this->fixturePath);

    expect(fn () => new DocsRepository)
        ->toThrow(RuntimeException::class, 'help/getting-started/broken.md');
});

it('hides a feature-gated category and its pages while the feature is off', function (): void {
    writeFeatureGatedFixture($this->fixturePath, 'EmailIntegration');
    Feature::deactivate(EmailIntegration::class);

    $repository = new DocsRepository;

    expect($repository->categories()->keys()->all())->toBe(['help/open'])
        ->and($repository->pages()->keys()->all())->toBe(['help/open/outside'])
        ->and($repository->find('help/gated/inside'))->toBeNull()
        ->and($repository->findCategory('help/gated'))->toBeNull()
        ->and($repository->pagesIn('help/gated'))->toBeEmpty();
});

it('shows a feature-gated category and its pages while the feature is on', function (): void {
    writeFeatureGatedFixture($this->fixturePath, 'EmailIntegration');
    Feature::activate(EmailIntegration::class);

    $repository = new DocsRepository;

    expect($repository->categories()->keys()->all())->toBe(['help/gated', 'help/open'])
        ->and($repository->find('help/gated/inside'))->not->toBeNull();
});

it('throws and names the offending file when a category gates on an unknown feature', function (): void {
    writeFeatureGatedFixture($this->fixturePath, 'NoSuchFeature');

    expect(fn () => new DocsRepository)
        ->toThrow(RuntimeException::class, 'help/gated/_index.md');
});
