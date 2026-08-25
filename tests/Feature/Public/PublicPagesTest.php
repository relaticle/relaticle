<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PrivacyPolicyController;
use App\Http\Controllers\TermsOfServiceController;
use App\Models\User;
use App\Support\DetectsPublicMarkdownRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\Ink\Models\Tag;

mutates(HomeController::class, TermsOfServiceController::class, PrivacyPolicyController::class, DetectsPublicMarkdownRequest::class);

beforeEach(function () {
    Http::fake([
        'api.github.com/*' => Http::response(['stargazers_count' => 42], 200),
    ]);
});

describe('Home page', function () {
    it('returns a successful response', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Relaticle');
    });

    it('displays the GitHub stars count', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('42');
    });

    it('has descriptive alt text on every hero product screenshot', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('alt="Relaticle opportunities board with deals grouped into pipeline stages, showing deal value and close date"', false);
        $response->assertSee('alt="Relaticle companies list showing account owner, ICP status, and website domain for each company"', false);
        $response->assertSee('alt="Relaticle custom fields settings showing field name, type, constraints, and properties for Opportunities"', false);
    });

    it('uses an existing raster logo in the organization json-ld', function () {
        $html = (string) $this->get('/')->assertStatus(200)->getContent();

        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $matches);

        $graph = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $organization = collect($graph['@graph'])->firstWhere('@type', 'Organization');

        expect($organization['logo'])->toEndWith('/web-app-manifest-512x512.png')
            ->and(file_exists(public_path('web-app-manifest-512x512.png')))->toBeTrue();
    });
});

describe('Legal pages', function () {
    it('displays the terms of service page with product-specific content', function () {
        $response = $this->get('/terms-of-service');

        $response->assertStatus(200);
        $response->assertSee('Terms of Service');
        $response->assertSee('Relaticle');
        $response->assertDontSee('word usage');
        $response->assertDontSee('Basic" plan');
    });

    it('displays the privacy policy page with product-specific content', function () {
        $response = $this->get('/privacy-policy');

        $response->assertStatus(200);
        $response->assertSee('Privacy Policy');
        $response->assertSee('Relaticle');
        $response->assertDontSee('registered mail');
    });
});

describe('Documentation pages', function () {
    // Shiki highlights code by spawning a node subprocess per fenced block, and
    // these pages carry ~59 between them — over half this file's runtime. None
    // of the assertions here read highlighted output, so it is switched off and
    // covered once, explicitly, at the end of this block.
    beforeEach(function () {
        config()->set('markdown.code_highlighting.enabled', false);
    });

    it('displays the developers index', function () {
        $response = $this->get('/developers');

        $response->assertStatus(200);
        $response->assertSee('Documentation');
    });

    it('displays the contributing guide', function () {
        $response = $this->get('/developers/contributing');

        $response->assertStatus(200);
        $response->assertSee('Contributing Guide');
    });

    it('displays the self-hosting guide', function () {
        $response = $this->get('/developers/self-hosting');

        $response->assertStatus(200);
        $response->assertSee('Self-Hosting Guide');
    });

    it('displays the MCP guide', function () {
        $response = $this->get('/developers/mcp');

        $response->assertStatus(200);
        $response->assertSee('MCP Server');
    });

    it('shows edit on GitHub link on documentation pages', function () {
        $response = $this->get('/developers/self-hosting');

        $response->assertStatus(200);
        $response->assertSee('Edit this page on GitHub');
    });

    it('returns 404 for non-existent documentation page', function () {
        $response = $this->get('/developers/non-existent-page');

        $response->assertStatus(404);
    });

});

describe('Pricing page', function () {
    it('displays the pricing page', function () {
        $response = $this->get('/pricing');

        $response->assertStatus(200);
        $response->assertSee('No per-seat pricing');
    });
});

describe('Authentication redirects', function () {
    it('redirects login to app panel', function () {
        $response = $this->get('/login');

        $response->assertRedirect(url()->getAppUrl('login'));
    });

    it('redirects register to app panel', function () {
        $response = $this->get('/register');

        $response->assertRedirect(url()->getAppUrl('register'));
    });

    it('redirects forgot password to app panel', function () {
        $response = $this->get('/forgot-password');

        $response->assertRedirect(url()->getAppUrl('forgot-password'));
    });

    it('redirects dashboard to app panel', function () {
        $response = $this->get('/dashboard');

        $response->assertRedirect(url()->getAppUrl());
    });
});

describe('Community redirects', function () {
    it('redirects to discord', function () {
        config(['services.discord.invite_url' => 'https://discord.gg/example']);

        $response = $this->get('/discord');

        $response->assertRedirect('https://discord.gg/example');
    });
});

describe('Social authentication routes', function () {
    it('throttles authentication redirect attempts', function () {
        // Make 10 requests (the limit)
        for ($i = 0; $i < 10; $i++) {
            $this->get('/auth/redirect/github');
        }

        // The 11th request should be throttled
        $response = $this->get('/auth/redirect/github');

        $response->assertStatus(429); // Too Many Requests
    });

    it('accepts github as a provider for redirect', function () {
        $response = $this->get('/auth/redirect/github');

        $response->assertStatus(302); // Redirect to GitHub
    });

    it('accepts google as a provider for redirect', function () {
        $response = $this->get('/auth/redirect/google');

        $response->assertStatus(302); // Redirect to Google
    });
});

describe('Hero AI tab — conversation', function () {
    it('renders the three exchanges in initial DOM', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee("What's overdue this week?", false);
        $response->assertSee('Searching tasks');
        $response->assertSee('Call Sarah Chen');
        $response->assertSee('Send proposal to Trellis Labs');
        $response->assertSee('Schedule demo with Kovra Systems');
        $response->assertSee('Mark the Kovra demo as done');
        // The proposal docks at the composer and resolves into the audit card.
        // The decided row names the record as a chip plus an operation label,
        // so assert the label, not a "Update task \"...\"" sentence that a
        // restyle of the row legitimately changes.
        $response->assertSee('Review before continuing');
        $response->assertSee('Save changes');
        $response->assertSee('Add Sarah Chen');
        $response->assertSee('VP of Engineering');
    });

    it('places all message content in the initial HTML so reduced-motion users see it', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        // Exchange 1
        $response->assertSee('You have 3 overdue tasks');
        // Exchange 2 climax. Approving resumes the turn, so the line under the
        // decided row is the agent's own reply, as in the shipped transcript.
        $response->assertSee('Review the proposal below to update the task');
        $response->assertSee('has been marked done');
        // Exchange 3 is a WRITE, so it is gated by review exactly like exchange 2
        // (CreatePersonTool returns a proposal for approval). It must never show
        // a create landing unattended.
        $response->assertSee('Review the proposal below to add her to Kovra Systems');
        $response->assertSee('has been created and linked to');
    });

    it('gates every write in the demo behind a review, like the real tools do', function () {
        $body = (string) $this->get('/')->assertSuccessful()->getContent();

        // Both writes the demo performs are proposals in the product
        // (BaseWriteCreateTool / BaseWriteUpdateTool return a PendingAction),
        // so each must reach the transcript carrying an Approved outcome, and
        // the dock must offer a decision for each before it lands. Advertising
        // an unattended write would contradict the review-before-write contract
        // the demo's own second exchange is built to show off.
        //
        // Asserted on the review CHROME rather than on a card heading: the row
        // renders the record as a chip plus an operation label, and pinning that
        // wording made this test fail on a pure restyle while the invariant it
        // guards -- no write without an approval -- still held.
        expect(substr_count($body, 'Approved'))->toBeGreaterThanOrEqual(2)
            ->and(substr_count($body, 'Review before continuing'))->toBeGreaterThanOrEqual(1)
            ->and($body)->toContain('Review the proposal below to update the task')
            ->and($body)->toContain('Review the proposal below to add her to Kovra Systems');
    });

    it('mirrors the shipped transcript surfaces rather than the components it replaced', function () {
        $body = (string) $this->get('/')->assertSuccessful()->getContent();

        // The user bubble is a soft tint, never solid primary-600: see the
        // comment on _transcript.blade.php's bubble.
        expect($body)->toContain('rounded-br-md bg-primary-50')
            ->and($body)->not->toContain('rounded-br-md bg-primary-600');

        // Read results render as a records_table with a chip-linked core column,
        // not the deleted chat/data-table component.
        expect($body)->toContain('chat-chip');
    });
});

describe('Hero AI tab — app shell', function () {
    it('renders the sidebar navigation items', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Home');
        $response->assertSee('People');
        $response->assertSee('Companies');
        $response->assertSee('Opportunities');
        $response->assertSee('Tasks');
        $response->assertSee('Notes');
    });

    it('marks Home as the active navigation item', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('hero-shell-nav-home', false);
        $response->assertSee('Chats');
    });

    it('renders recent conversation examples and the All chats trigger', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Overdue tasks this week');
        $response->assertSee('Follow up with Priya Nair');
        $response->assertSee('Renewal prep — Daniel Okafor', false);
        $response->assertSee('All chats');
    });

    it('renders the composer with model picker and send button affordance', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Ask anything');
        $response->assertSee('hero-composer-send', false);
        $response->assertSee('hero-composer-cursor', false);
    });

    it('renders the non-interactive overlay above panel content', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        // Overlay is an absolutely-positioned aria-hidden div with z-30
        $response->assertSee('z-30', false);
        $response->assertSee('user-select: none', false);
    });
});

describe('Hero AI tab — demo CTA', function () {
    it('does not show the Watch demo link when video file is missing', function () {
        $videoPath = public_path('videos/hero-demo.mp4');
        if (file_exists($videoPath)) {
            rename($videoPath, $videoPath.'.backup');
        }

        try {
            $response = $this->get('/');

            $response->assertStatus(200);
            $response->assertDontSee('Watch 30s demo');
        } finally {
            if (file_exists($videoPath.'.backup')) {
                rename($videoPath.'.backup', $videoPath);
            }
        }
    });

    it('shows the Watch demo link and modal when the video file exists', function () {
        $videoPath = public_path('videos/hero-demo.mp4');
        $created = false;
        if (! file_exists($videoPath)) {
            touch($videoPath);
            $created = true;
        }

        try {
            $response = $this->get('/');

            $response->assertStatus(200);
            $response->assertSee('Watch 30s demo');
            $response->assertSee('hero-demo-modal', false);
        } finally {
            if ($created && file_exists($videoPath)) {
                unlink($videoPath);
            }
        }
    });
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

    it('leaves the canonical on non-paginating marketing pages query-free', function (string $path) {
        // The page-aware canonical belongs to the blog listing, not to the shared
        // guest layout: a crawler-discovered ?page=N on pricing or the homepage
        // used to self-canonicalise into an indexable duplicate.
        $this->get($path.'?page=7')
            ->assertStatus(200)
            ->assertSee('<link rel="canonical" href="'.url($path).'" />', false);
    })->with(['/pricing', '/terms-of-service', '/privacy-policy']);

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

        $this->actingAs(User::factory()->withPersonalTeam()->create())
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

describe('Error handling', function () {
    it('returns 404 for non-existent routes', function () {
        $response = $this->get('/non-existent-page');

        $response->assertStatus(404);
    });
});

describe('Response meta', function () {
    it('returns proper content type', function () {
        $response = $this->get('/');

        $response->assertHeader('Content-Type');
        $response->assertSuccessful();
    });
});

describe('Hero AI tab — animation timeline', function () {
    it('hides the data-table outer container at cycle start', function () {
        $response = $this->get('/');
        $response->assertSuccessful();

        // The exchange 1 tool-result table container must be opacity-controlled
        // by the .mcp-el CSS rule. Without this class, the rounded border ghosts
        // through before any animation runs.
        $body = $response->getContent();
        expect($body)->toContain('mcp-el mcp-tasks-table');
    });

    it('keeps the post-exchange hold window at ~1.5s', function () {
        $response = $this->get('/');
        $response->assertSuccessful();
        $body = $response->getContent();

        // Hold is the read-time after exchange 3 settles before the loop
        // restarts from the entry phase. cycleMs no longer exists as a
        // single magic number — timing is composed from entryHold +
        // transition + exchange budgets.
        expect($body)->toContain('holdMs: 1500');
    });

    it('does not restart the AI demo on hover or focus changes', function () {
        $response = $this->get('/');
        $response->assertSuccessful();
        $body = $response->getContent();

        // Hovering between the preview and the Ask Rela tab must not
        // cancel timers and call animateChat(), because that restarts the demo.
        expect($body)
            ->not->toContain('@mouseenter="pause()"')
            ->not->toContain('@mouseleave="resume()"')
            ->not->toContain('@focusin="pause()"')
            ->not->toContain('@focusout="resume()"')
            ->not->toContain('pause() {')
            ->not->toContain('resume() {');
    });

    it('uses a unified Y-slide for assistant content', function () {
        $response = $this->get('/');
        $response->assertSuccessful();
        $body = $response->getContent();

        // Every assistant child uses translateY for its slide. The legacy
        // mixed translateX(-6) on tool indicators must stay out.
        expect($body)->not->toContain("translateX('-6px')");
        expect($body)->not->toContain('translateX(-6px)');
    });

    it('reveals new messages at the bottom so earlier ones stay visible', function () {
        $response = $this->get('/');
        $response->assertSuccessful();
        $body = $response->getContent();

        // Real-chat scroll: the view follows each newly revealed message/card
        // down to the bottom (scrollToShow only scrolls down), so the previous
        // exchange stays on screen instead of being yanked to the top before
        // the next message has even appeared.
        expect($body)
            ->toContain('scrollToShow')
            ->toContain("scrollToShow('.mcp-user-2')")
            ->toContain("scrollToShow('.mcp-user-3')")
            ->toContain("scrollToShow('.mcp-audit-card')")
            ->not->toContain('scrollMessageIntoView')
            ->not->toContain('typeStart2 - 100')
            ->not->toContain('typeStart3 - 100');
    });

    it('animates the 3 task rows as a single staggered group with 120ms spacing', function () {
        $response = $this->get('/');
        $response->assertSuccessful();
        $body = $response->getContent();

        // Stagger spacing is 120ms after the table reveal: 1000 / 1120 / 1240
        // relative to conversationStart.
        expect($body)->toContain('conversationStart + 1000');
        expect($body)->toContain('conversationStart + 1120');
        expect($body)->toContain('conversationStart + 1240');
    });
});

describe('Hero AI tab — entry phase', function () {
    it('renders the dashboard greeting mirroring app /', function () {
        $response = $this->get('/');
        $response->assertSuccessful();

        // Mirrors packages/Chat/resources/views/filament/pages/dashboard.blade.php
        // greeting: large semibold heading + recent-chat link beneath.
        // assertSee defaults to escape=true; apostrophes are not HTML-escaped
        // in plain text bodies, so we pass false to compare literally.
        $response->assertSee('Good morning, Marcus.');
        $response->assertSee("This week's pipeline review", false);
    });

    it('does not offer canned starter prompts, matching the real dashboard', function () {
        $response = $this->get('/');
        $response->assertSuccessful();

        // The starter chips were removed from the product: an empty composer
        // makes no suggestions. The mock must not reintroduce them.
        $response->assertDontSee('CRM overview');
        $response->assertDontSee('Recent companies');
        $response->assertDontSee('Pipeline summary');
    });

    it('renders a populated My Tasks section mirroring the dashboard', function () {
        $response = $this->get('/');
        $response->assertSuccessful();

        // Populated, not the zero-state: a frame selling an AI CRM must not
        // depict an empty CRM, and the real dashboard shows the list with
        // overdue dates called out.
        $response->assertSee(__('filament/pages/dashboard.tasks.view_all'));
        $response->assertSee('Call Sarah Chen');
        $response->assertSee('Renewal prep for Daniel Okafor');
        $response->assertDontSee(__('filament/pages/dashboard.tasks.empty.title'));
    });

    it('renders a second composer scoped with entry IDs', function () {
        $response = $this->get('/');
        $response->assertSuccessful();
        $body = $response->getContent();

        // Entry composer is a twin of hero-composer-* with entry-scoped IDs
        // so the heroChat factory can target it independently.
        expect($body)->toContain('hero-entry-typed');
        expect($body)->toContain('hero-entry-placeholder');
        expect($body)->toContain('hero-entry-send');
    });

    it('wires the entry → conversation transition in the heroChat factory', function () {
        $response = $this->get('/');
        $response->assertSuccessful();
        $body = $response->getContent();

        // Phase machine helpers must be in the factory or the loop has no
        // way to fade the entry overlay out and the conversation pane in.
        expect($body)->toContain('transitionToConversation');
        expect($body)->toContain('entryHoldMs');
        expect($body)->toContain('entryTransitionMs');
    });
});
