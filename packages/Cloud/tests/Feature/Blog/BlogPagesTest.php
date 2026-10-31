<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Relaticle\Cloud\CloudServiceProvider;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\Ink\Models\Tag;

mutates(CloudServiceProvider::class);

beforeEach(function () {
    Http::fake([
        'api.github.com/*' => Http::response(['stargazers_count' => 42], 200),
    ]);
});

describe('Blog pages', function () {
    it('displays the blog index', function () {
        $this->get('/blog')
            ->assertStatus(200)
            ->assertSee('Engineering Blog');
    });

    it('displays published posts on the index', function () {
        $post = Post::factory()->published()->create();

        $this->get('/blog')
            ->assertStatus(200)
            ->assertSee($post->title);
    });

    it('renders the post cover uncropped on the index', function () {
        Post::factory()->published()->create(['featured_image' => 'ink/cover.png']);

        $this->get('/blog')
            ->assertStatus(200)
            ->assertSee('storage/ink/cover.png')
            ->assertSee('aspect-video');
    });

    it('renders related posts through the shared card on a post page', function () {
        $category = Category::factory()->create();
        [$post, $related] = Post::factory()->published()->count(2)->create(['category_id' => $category->id]);

        $this->get("/blog/{$post->slug}")
            ->assertStatus(200)
            ->assertSee($related->title);
    });

    it('canonicalises a paginated listing to its own page', function () {
        Post::factory()->published()->count(config('ink.per_page') + 1)->create();

        $this->get('/blog?page=2')
            ->assertStatus(200)
            ->assertSee('<link rel="canonical" href="'.url('/blog').'?page=2" />', false);
    });

    it('drops junk and non-page query params from the canonical', function () {
        $this->get('/blog?page=abc&q=laravel')
            ->assertStatus(200)
            ->assertSee('<link rel="canonical" href="'.url('/blog').'" />', false);
    });

    it('marks blog search results noindex but leaves the plain listing indexable', function () {
        $this->get('/blog?q=laravel')
            ->assertStatus(200)
            ->assertSee('<meta name="robots" content="noindex,follow">', false);

        $this->get('/blog')
            ->assertStatus(200)
            ->assertDontSee('name="robots"', false);
    });

    it('emits exactly one canonical and one og:type on a post page', function () {
        $post = Post::factory()->published()->create();

        $html = $this->get("/blog/{$post->slug}")->assertStatus(200)->getContent();

        expect(substr_count($html, '<link rel="canonical"'))->toBe(1)
            ->and(substr_count($html, 'property="og:type"'))->toBe(1)
            ->and(substr_count($html, 'property="og:title"'))->toBe(1)
            ->and(substr_count($html, 'name="twitter:card"'))->toBe(1)
            ->and($html)->toContain('<meta property="og:type" content="article" />');
    });

    it('lets the panel SEO fields override the post title and excerpt', function () {
        $post = Post::factory()->published()->create([
            'title' => 'Original Title',
            'excerpt' => 'Original excerpt.',
        ]);
        $post->seo->update(['title' => 'Search Title', 'description' => 'Search description.']);

        $this->get("/blog/{$post->slug}")
            ->assertStatus(200)
            ->assertSee('<meta property="og:title" content="Search Title"/>', false)
            ->assertSee('<meta name="description" content="Search description.">', false);
    });

    it('falls back to the post body when a post has no excerpt or SEO description', function () {
        $post = Post::factory()->published()->create([
            'excerpt' => null,
            'content' => 'A distinctive sentence that should end up in the meta description.',
        ]);

        $this->get("/blog/{$post->slug}")
            ->assertStatus(200)
            ->assertSee('<meta property="og:description" content="A distinctive sentence that should end up in the meta description."/>', false)
            ->assertDontSee('<meta property="og:description" content=""/>', false);
    });

    it('says nothing matched, not "no posts yet", when a filter comes up empty', function () {
        Post::factory()->published()->count(3)->create();

        $this->get('/blog?q=zzzznomatchzzzz')
            ->assertStatus(200)
            ->assertSee('Nothing matched')
            ->assertDontSee('No posts yet');

        $emptyCategory = Category::factory()->create();

        $this->get("/blog/category/{$emptyCategory->slug}")
            ->assertStatus(200)
            ->assertSee('Nothing matched')
            ->assertDontSee('No posts yet');
    });

    it('does not display draft posts on the index', function () {
        $post = Post::factory()->draft()->create();

        $this->get('/blog')
            ->assertStatus(200)
            ->assertDontSee($post->title);
    });

    it('displays a single blog post', function () {
        $post = Post::factory()->published()->create();

        $this->get("/blog/{$post->slug}")
            ->assertStatus(200)
            ->assertSee($post->title);
    });

    it('returns 404 for non-existent blog post', function () {
        $this->get('/blog/non-existent-post')
            ->assertStatus(404);
    });

    it('displays posts filtered by category', function () {
        $category = Category::factory()->create();
        $post = Post::factory()->published()->create(['category_id' => $category->id]);

        $this->get("/blog/category/{$category->slug}")
            ->assertStatus(200)
            ->assertSee($post->title)
            ->assertSee($category->name);
    });

    it('displays posts filtered by tag', function () {
        $tag = Tag::factory()->create();
        $taggedPost = Post::factory()->published()->create();
        $otherPost = Post::factory()->published()->create();

        $taggedPost->tags()->attach($tag);

        $this->get("/blog/tag/{$tag->slug}")
            ->assertStatus(200)
            ->assertSee($taggedPost->title)
            ->assertSee('#'.$tag->name)
            ->assertDontSee($otherPost->title);
    });

    it('returns 404 for non-existent tag', function () {
        $this->get('/blog/tag/non-existent-tag')
            ->assertStatus(404);
    });

    it('renders tag pills on a post show page', function () {
        $tag = Tag::factory()->create();
        $post = Post::factory()->published()->create();
        $post->tags()->attach($tag);

        $this->get("/blog/{$post->slug}")
            ->assertStatus(200)
            ->assertSee('#'.$tag->name)
            ->assertSee(route('blog.tag', $tag->slug));
    });

    it('returns RSS feed', function () {
        Post::factory()->published()->create();

        $response = $this->get('/blog/feed')->assertStatus(200);

        // Match the media type, not the full header: ink appends `; charset=UTF-8`,
        // which the app's old feed controller omitted. Both are valid RSS.
        expect($response->headers->get('Content-Type'))
            ->toStartWith('application/rss+xml');
    });

    it('includes blog link in navigation', function () {
        $this->get('/')
            ->assertStatus(200)
            ->assertSee(route('blog.index'));
    });

    it('escapes raw HTML embedded in post markdown instead of executing it', function () {
        $post = Post::factory()->published()->create([
            'content' => "## Intro\nSafe copy.\n\n<script>window.pwned = true</script>\n<img src=x onerror=\"window.pwned = true\">",
        ]);

        $html = $this->get("/blog/{$post->slug}")
            ->assertStatus(200)
            ->getContent();

        $article = mb_substr($html, (int) mb_strpos($html, '<article'), (int) mb_strpos($html, '</article>') - (int) mb_strpos($html, '<article'));

        expect($article)->not->toContain('<script>window.pwned')
            ->and($article)->not->toContain('<img src=x onerror')
            ->and($article)->toContain('&lt;script&gt;')
            ->and($article)->toContain('&lt;img src=x onerror');
    });

    it('keeps the table of contents label intact when a heading contains inline markup', function () {
        $post = Post::factory()->published()->create([
            'content' => "## Why **we** built it\n\nBody.\n\n## Using `artisan` commands\n\nBody.\n\n## Ampersands & more\n\nBody.",
        ]);

        $this->get("/blog/{$post->slug}")
            ->assertStatus(200)
            ->assertSee('Why we built it')
            ->assertSee('Using artisan commands')
            // Rendered HTML carries the single-escaped entity, which the reader sees
            // as "Ampersands & more"; the double-escaped form is the bug this guards.
            ->assertSee('Ampersands &amp; more', escape: false)
            ->assertDontSee('&amp;amp;', escape: false);
    });

    it('keeps the JSON-LD block intact when a post title closes the script tag', function () {
        $title = 'Breakout </script><script>window.pwned=true</script>';

        $post = Post::factory()->published()->create([
            'title' => $title,
            'content' => 'Body copy.',
        ]);

        $html = $this->get("/blog/{$post->slug}")->assertStatus(200)->getContent();

        $opening = '<script type="application/ld+json">';
        $start = (int) mb_strpos($html, $opening) + mb_strlen($opening);

        // A browser ends the block at the first literal </script>, so a clean parse
        // here is the proof that the title could not terminate it and spill the rest
        // of the schema into the visible page.
        $block = trim(mb_substr($html, $start, (int) mb_strpos($html, '</script>', $start) - $start));
        $decoded = json_decode($block, true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE, "JSON-LD block was truncated: {$block}")
            ->and($decoded['headline'])->toBe($title);
    });

    it('renders the signed preview for a signed-in app user without an edit link', function () {
        // The blog admin lives in the sysadmin panel; building an app-panel URL here
        // used to throw RouteNotFoundException and 500 the page for any logged-in user.
        $post = Post::factory()->create();

        $this->actingAs(User::factory()->withPersonalWorkspace()->create())
            ->get(URL::temporarySignedRoute('blog.preview', now()->addHour(), ['post' => $post]))
            ->assertStatus(200)
            ->assertSee($post->title);
    });

    it('stops honouring a preview link once its signature expires, even as markdown', function () {
        // ProvideMarkdownResponse serves a cached body without calling $next(), so a
        // markdown hit used to skip ValidateSignature entirely and keep serving the
        // draft for the rest of the 1h cache TTL. AI crawler user agents are detected
        // as markdown requests, so ordinary crawl traffic warmed that cache.
        $post = Post::factory()->draft()->create(['content' => 'Unpublished body copy.']);

        $url = URL::temporarySignedRoute('blog.preview', now()->addHour(), ['post' => $post]);

        // Warm the cache late in the signature window: the 1h cache TTL starts here,
        // so it outlives the signature by 59 minutes.
        $this->travel(59)->minutes();
        $this->get($url, ['Accept' => 'text/markdown'])->assertStatus(200);

        $this->travel(2)->minutes();

        $this->get($url, ['Accept' => 'text/markdown'])->assertStatus(403);
        $this->get($url, ['User-Agent' => 'ClaudeBot/1.0'])->assertStatus(403);
    });

    it('still serves markdown for published posts, which are ungated', function () {
        $post = Post::factory()->published()->create();

        $response = $this->get("/blog/{$post->slug}", ['Accept' => 'text/markdown'])
            ->assertStatus(200);

        expect($response->headers->get('Content-Type'))->toStartWith('text/markdown');
    });

    it('404s a preview request whose post segment is not numeric', function () {
        $this->get('/blog/preview/not-a-post-id')
            ->assertStatus(404);
    });

    it('offers a way back when a visitor requests a page beyond the last one', function () {
        Post::factory()->published()->count(3)->create();

        $this->get('/blog?page=99')
            ->assertStatus(200)
            ->assertDontSee('No posts yet')
            ->assertSee('archive only goes up to page 1', escape: false)
            ->assertSee(route('blog.index'));
    });
});

describe('Blog metadata', function () {
    it('bounds the blog index metadata and names the taxonomy on a category and tag page', function () {
        $category = Category::factory()->create(['name' => 'Guides']);
        $tag = Tag::factory()->create(['name' => 'mcp']);
        Post::factory()->published()->create(['category_id' => $category->id]);

        $listings = [
            '/blog' => null,
            "/blog/category/{$category->slug}" => $category->name,
            "/blog/tag/{$tag->slug}" => $tag->name,
        ];

        foreach ($listings as $path => $taxonomy) {
            $html = $this->get($path)->assertOk()->getContent();

            preg_match('/<title>(.*?)<\/title>/s', $html, $title);
            preg_match('/<meta name="description" content="([^"]*)"/', $html, $description);

            $titleText = html_entity_decode(trim($title[1] ?? ''));
            $descriptionText = html_entity_decode($description[1] ?? '');

            expect($titleText)->toEndWith(' - '.config('app.name'), $path)
                ->and(mb_strlen($titleText))->toBeLessThanOrEqual(60, "{$path} title: {$titleText}")
                ->and(mb_strlen($descriptionText))->toBeLessThanOrEqual(160, "{$path} description: {$descriptionText}")
                ->and(mb_strlen($descriptionText))->toBeGreaterThanOrEqual(70, "{$path} description: {$descriptionText}");

            if ($taxonomy !== null) {
                expect($titleText)->toContain($taxonomy)
                    ->and($descriptionText)->toContain($taxonomy);

                continue;
            }

            expect(mb_strlen($titleText))->toBeGreaterThanOrEqual(30, "{$path} title: {$titleText}");
        }
    });
});
