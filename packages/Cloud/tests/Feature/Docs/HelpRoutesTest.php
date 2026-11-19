<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Docs\Support\DocsNavigation;
use Relaticle\Documentation\Support\DocsRepository;
use Relaticle\Ink\Models\Post;

dataset('documentation shell pages', [
    '/help',
    '/help/getting-started',
    '/help/getting-started/create-your-first-company',
    '/developers',
    '/developers/mcp',
]);

mutates(DocsNavigation::class);

it('renders the help hub with category cards', function (): void {
    $this->get('/help')->assertOk()->assertSee('Getting started', false);
});

it('renders a category page', function (): void {
    $this->get('/help/getting-started')
        ->assertOk()
        ->assertSee('Getting started', false)
        ->assertSee('Create your first company', false);
});

it('renders an article with its rendered body', function (): void {
    $this->get('/help/getting-started/create-your-first-company')
        ->assertOk()
        ->assertSee('Create your first company', false);
});

it('tells the reader a paused trial workspace is deleted after 60 days with no sign-in', function (): void {
    $this->get('/help/workspace/billing-and-plans')
        ->assertOk()
        ->assertSeeText('We keep a paused trial workspace for 60 days after the last sign-in. If no one signs in or chooses a plan in that time, we delete it and its records. We email the owner twice first.');
});

it('says the personal workspace cannot be deleted from the delete page only', function (): void {
    $this->get('/help/workspace/rename-or-delete-your-workspace')
        ->assertOk()
        ->assertSeeText("You can't delete your original personal workspace from this page.");
});

it('offers a direct contact path from the hub and the article footer', function (): void {
    $this->get('/help')
        ->assertOk()
        ->assertSee(route('contact'), false);

    $this->get('/help/getting-started/create-your-first-company')
        ->assertOk()
        ->assertSee(route('contact'), false)
        ->assertSee('Contact support', false);
});

it('404s an unknown category and an unknown article', function (): void {
    $this->get('/help/no-such-category')->assertNotFound();
    $this->get('/help/getting-started/no-such-article')->assertNotFound();
});

it('lists the email category on the hub while email integration is on', function (): void {
    Feature::activate(EmailIntegration::class);
    app()->forgetInstance(DocsRepository::class);

    $this->get('/help')->assertOk()->assertSee('/help/email-and-calendar', false);
    $this->get('/help/email-and-calendar/connect-your-google-account')->assertOk();
    $this->get('/help/email-and-calendar/connect-your-microsoft-account')->assertOk();
});

it('hides the email category and its articles while email integration is off', function (): void {
    Feature::deactivate(EmailIntegration::class);
    app()->forgetInstance(DocsRepository::class);

    $this->get('/help')->assertOk()->assertDontSee('/help/email-and-calendar', false);
    $this->get('/help/email-and-calendar')->assertNotFound();
    $this->get('/help/email-and-calendar/connect-your-google-account')->assertNotFound();
    $this->get('/help/email-and-calendar/connect-your-microsoft-account')->assertNotFound();
    $this->get('/help/search-index.json')->assertOk()->assertDontSee('help\\/email-and-calendar', false);
    $this->get('/llms.txt')->assertOk()->assertDontSee('email-and-calendar', false);
});

it('serves an article as markdown to an agent', function (): void {
    $markdown = $this->get('/help/getting-started/create-your-first-company', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('Create your first company')
        ->and($markdown)->not->toContain('Start for free');
});

it('strips the related-articles nav from the markdown response but keeps the body', function (): void {
    $markdown = $this->get('/help/getting-started/create-your-first-company', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('A company record tracks a business')
        ->and($markdown)->not->toContain('Add your first person');
});

it('renders documentation pages in their own shell, not the marketing chrome', function (string $path): void {
    $html = $this->get($path)->assertOk()->getContent();

    // The marketing header component is the thing the docs surface must not
    // mount -- its id is the only stable marker of that specific chrome.
    expect($html)->not->toContain('id="main-header"')
        ->and($html)->toContain('Search the docs');
})->with('documentation shell pages');

it('offers both areas in the sidebar of every documentation page', function (string $path): void {
    $html = $this->get($path)->assertOk()->getContent();

    expect($html)->toContain(route('help.show', ['category' => 'getting-started', 'slug' => 'create-your-first-company']))
        ->and($html)->toContain(route('documentation.show', ['type' => 'mcp']));
})->with('documentation shell pages');

it('serves the .md variant the article copy-page action fetches', function (): void {
    $this->get('/help/getting-started/create-your-first-company.md')
        ->assertOk()
        ->assertHeader('content-type', 'text/markdown; charset=UTF-8');
});

it('keeps the shell chrome out of the markdown variant', function (): void {
    $markdown = $this->get('/help/getting-started/create-your-first-company', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    // Search dialog, off-canvas nav and skip link are browser-only affordances:
    // an agent asking for markdown has no use for any of them.
    expect($markdown)->not->toContain('Searching help and developer docs')
        ->and($markdown)->not->toContain('Browse the docs')
        ->and($markdown)->not->toContain('Skip to content')
        ->and($markdown)->toContain('A company record tracks a business');
});

it('links to help and developers from the marketing header, mobile nav, and footer', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    // A future nav refactor that silently drops one of these anchors still
    // passes an "assertSee" style check -- count the occurrences instead.
    expect(substr_count($html, route('help.index')))->toBeGreaterThanOrEqual(3)
        ->and(substr_count($html, route('documentation.index')))->toBeGreaterThanOrEqual(3);
});

function writeGermanHelp(string $root): void
{
    File::ensureDirectoryExists("{$root}/help/guides");
    File::ensureDirectoryExists("{$root}/de/help/guides");
    File::put("{$root}/help/guides/_index.md", "---\ntitle: Guides\ndescription: English guides.\norder: 1\n---\n\nBody.\n");
    File::put("{$root}/help/guides/widgets.md", "---\ntitle: Widgets\ndescription: English widgets.\norder: 1\n---\n\nEnglish text.\n");
    File::put("{$root}/help/guides/gadgets.md", "---\ntitle: Gadgets\ndescription: English gadgets.\norder: 2\n---\n\nEnglish text.\n");
    File::put("{$root}/de/help/guides/_index.md", "---\ntitle: Anleitungen\ndescription: Deutsche Anleitungen.\norder: 1\n---\n\nInhalt.\n");
    File::put("{$root}/de/help/guides/widgets.md", "---\ntitle: Widgets deutsch\ndescription: Deutsche Widgets.\norder: 1\n---\n\nSiehe [Gadgets](/de/help/guides/gadgets).\n");

    Config::set('documentation.content_path', $root);
    Config::set('documentation.cache.enabled', false);
    app()->forgetInstance(DocsRepository::class);
}

it('serves a German help page from content/de and 404s one with no German file', function (): void {
    $root = storage_path('framework/testing/help-routes-'.Str::random(8));
    writeGermanHelp($root);

    $this->get('/de/help/guides/widgets')->assertOk()->assertSee('Widgets deutsch', false);
    $this->get('/de/help/guides/gadgets')->assertNotFound();
    $this->get('/help/guides/gadgets')->assertOk()->assertSee('Gadgets', false);

    File::deleteDirectory($root);
});

it('lists only the German pages on the German help hub and category', function (): void {
    $root = storage_path('framework/testing/help-routes-'.Str::random(8));
    writeGermanHelp($root);

    $this->get('/de/help')->assertOk()->assertSee('Anleitungen', false)->assertDontSee('Gadgets', false);
    $this->get('/de/help/guides')->assertOk()->assertSee('Widgets deutsch', false)->assertDontSee('Gadgets', false);

    File::deleteDirectory($root);
});

it('serves the German search index with prefixed urls', function (): void {
    $root = storage_path('framework/testing/help-routes-'.Str::random(8));
    writeGermanHelp($root);

    $records = $this->get('/de/help/search-index.json')->assertOk()->json('records');
    $english = $this->get('/help/search-index.json')->assertOk()->json('records');

    File::deleteDirectory($root);

    expect(array_column($records, 'title'))->each->toBe('Widgets deutsch')
        ->and(array_column($records, 'url'))->each->toContain('/de/help/guides/widgets')
        ->and(array_column($english, 'title'))->toContain('Gadgets');
});

it('keeps the language prefix written in a German article body', function (): void {
    $root = storage_path('framework/testing/help-routes-'.Str::random(8));
    writeGermanHelp($root);

    $html = $this->get('/de/help/guides/widgets')->assertOk()->getContent();

    File::deleteDirectory($root);

    expect($html)->toContain('href="/de/help/guides/gadgets"')
        ->and($html)->not->toContain('/de/de/');
});

it('keeps llms.txt in English', function (): void {
    $root = storage_path('framework/testing/help-routes-'.Str::random(8));
    writeGermanHelp($root);

    $body = $this->get('/llms.txt')->assertOk()->getContent();

    File::deleteDirectory($root);

    expect($body)->toContain('[Widgets]')
        ->and($body)->not->toContain('Widgets deutsch');
});

it('lists only English posts in llms.txt', function (): void {
    Post::factory()->published()->create(['locale' => 'en', 'slug' => 'only-english']);
    Post::factory()->published()->create(['locale' => 'de', 'slug' => 'nur-deutsch']);

    $this->get('/llms.txt')
        ->assertOk()
        ->assertSee('/blog/only-english')
        ->assertDontSee('/blog/nur-deutsch');
});

it('writes the updated date of a help page in its language', function (): void {
    $updated = app(DocsRepository::class)->find('help/import/prepare-your-csv', 'es')->updated;

    $this->get('/es/help/import/prepare-your-csv')
        ->assertOk()
        ->assertSee($updated->locale('es')->isoFormat('LL'))
        ->assertDontSee($updated->locale('es')->isoFormat('MMMM D, YYYY'));
});
