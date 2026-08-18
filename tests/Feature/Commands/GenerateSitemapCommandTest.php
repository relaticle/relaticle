<?php

declare(strict_types=1);

use App\Console\Commands\GenerateSitemapCommand;
use App\Features\Blog;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Ink\Models\Post;
use Spatie\Crawler\Faking\FakeHandler;

mutates(GenerateSitemapCommand::class);

/**
 * spatie/crawler drives its own Guzzle client, so Illuminate's Http::fake()
 * never reaches it. Route the crawl through the crawler's own fake handler
 * via the guzzle_options config the command already reads from.
 *
 * @param  array<string, string>  $responses
 */
function fakeSitemapCrawl(array $responses): void
{
    config(['sitemap.guzzle_options.handler' => HandlerStack::create(new FakeHandler($responses))]);
}

beforeEach(function (): void {
    // The generator crawls app.url before adding the blog URLs; keep it off the network.
    fakeSitemapCrawl([config('app.url') => '<html><body></body></html>']);

    $this->sitemap = public_path('sitemap.xml');
    $this->original = File::exists($this->sitemap) ? File::get($this->sitemap) : null;
});

afterEach(function (): void {
    $this->original === null
        ? File::delete($this->sitemap)
        : File::put($this->sitemap, $this->original);
});

it('writes published posts into the sitemap when the blog is live', function (): void {
    $post = Post::factory()->published()->create();
    $draft = Post::factory()->draft()->create();

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = File::get($this->sitemap);

    expect($xml)->toContain(route('blog.show', $post->slug))
        ->and($xml)->toContain(route('blog.index'))
        ->and($xml)->not->toContain(route('blog.show', $draft->slug));
});

it('leaves the blog out of the sitemap when the feature is off', function (): void {
    Feature::for(null)->deactivate(Blog::class);

    $post = Post::factory()->published()->create();

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    expect(File::get($this->sitemap))->not->toContain(route('blog.show', $post->slug));
});

it('adds help urls to the sitemap with lastmod from front matter', function (): void {
    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = File::get($this->sitemap);

    expect($xml)->toContain('<loc>'.route('help.index').'</loc>')
        ->and($xml)->toContain('<loc>'.route('help.category', ['category' => 'getting-started']).'</loc>')
        ->and($xml)->toContain('<loc>'.route('help.show', ['category' => 'getting-started', 'slug' => 'create-your-first-company']).'</loc>')
        ->and($xml)->toMatch('#getting-started/create-your-first-company</loc>\s*<lastmod>2026-08-12#');
});

it('stamps lastmod onto urls the crawler already found', function (): void {
    // The marketing nav links /help, so in production the crawler discovers
    // help URLs first -- and Sitemap::add() keeps the first tag per URL. A
    // naive re-add of the lastmod-carrying version is silently dropped,
    // which shipped a sitemap with zero <lastmod> entries. Reproduce the
    // crawl-found-first ordering and require the merge.
    $article = route('help.show', ['category' => 'getting-started', 'slug' => 'create-your-first-company']);

    fakeSitemapCrawl([
        config('app.url') => '<html><body><a href="'.$article.'">Help</a></body></html>',
        $article => '<html><body>article</body></html>',
    ]);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    expect(File::get($this->sitemap))
        ->toMatch('#getting-started/create-your-first-company</loc>\s*<lastmod>2026-08-12#');
});

it('adds developer guide urls with lastmod from front matter', function (): void {
    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = File::get($this->sitemap);

    expect($xml)->toContain('<loc>'.route('documentation.index').'</loc>')
        ->and($xml)->toMatch('#developers/self-hosting</loc>\s*<lastmod>2026-08-14#')
        ->and($xml)->toMatch('#developers/mcp</loc>\s*<lastmod>2026-08-12#');
});

it('omits lastmod for a help page with no updated front matter', function (): void {
    $fixturePath = storage_path('framework/testing/sitemap-help-'.Str::random(8));

    File::ensureDirectoryExists("{$fixturePath}/help/no-date");
    File::put(
        "{$fixturePath}/help/no-date/_index.md",
        "---\ntitle: No date\ndescription: A category with an undated page.\norder: 1\n---\n\nBody.\n",
    );
    File::put(
        "{$fixturePath}/help/no-date/undated-page.md",
        "---\ntitle: Undated page\ndescription: A page without an updated date.\norder: 1\n---\n\nBody.\n",
    );

    Config::set('documentation.content_path', $fixturePath);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    File::deleteDirectory($fixturePath);

    $xml = File::get($this->sitemap);

    expect($xml)->toContain('<loc>'.route('help.show', ['category' => 'no-date', 'slug' => 'undated-page']).'</loc>')
        ->and($xml)->not->toMatch('#no-date/undated-page</loc>\s*<lastmod>#');
});

it('excludes auth and utility redirect urls from the sitemap', function (): void {
    $realHomepageWithLinksToLoginRegisterAndDiscord = (string) $this->get('/')->getContent();

    fakeSitemapCrawl([
        config('app.url') => $realHomepageWithLinksToLoginRegisterAndDiscord,
        url('/login') => '<html><body>login</body></html>',
        url('/register') => '<html><body>register</body></html>',
        url('/discord') => '<html><body>discord</body></html>',
    ]);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = File::get($this->sitemap);

    expect($xml)->toContain('<loc>'.url('/').'/</loc>')
        ->and($xml)->not->toContain('<loc>'.url('/login').'</loc>')
        ->and($xml)->not->toContain('<loc>'.url('/register').'</loc>')
        ->and($xml)->not->toContain('<loc>'.url('/discord').'</loc>');
});
