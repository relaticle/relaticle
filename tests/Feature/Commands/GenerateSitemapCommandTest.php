<?php

declare(strict_types=1);

use App\Console\Commands\GenerateSitemapCommand;
use App\Features\Blog;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\File;
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
