<?php

declare(strict_types=1);

use App\Console\Commands\GenerateSitemapCommand;
use App\Features\Blog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Relaticle\Ink\Models\Post;

mutates(GenerateSitemapCommand::class);

beforeEach(function (): void {
    // The generator crawls app.url before adding the blog URLs; keep it off the network.
    Http::fake(['*' => Http::response('<html><body></body></html>', 200)]);

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
