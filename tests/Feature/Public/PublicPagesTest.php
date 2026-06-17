<?php

declare(strict_types=1);

use App\Http\Controllers\Blog\BlogCategoryController;
use App\Http\Controllers\Blog\BlogFeedController;
use App\Http\Controllers\Blog\BlogPreviewController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PrivacyPolicyController;
use App\Http\Controllers\TermsOfServiceController;
use Illuminate\Support\Facades\Http;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\Ink\Models\Tag;

mutates(HomeController::class, TermsOfServiceController::class, PrivacyPolicyController::class, BlogController::class, BlogCategoryController::class, BlogFeedController::class, BlogPreviewController::class);

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
    it('displays the documentation index', function () {
        $response = $this->get('/docs');

        $response->assertStatus(200);
        $response->assertSee('Documentation');
    });

    it('displays the getting started guide', function () {
        $response = $this->get('/docs/getting-started');

        $response->assertStatus(200);
        $response->assertSee('Getting Started');
    });

    it('displays the import guide', function () {
        $response = $this->get('/docs/import');

        $response->assertStatus(200);
        $response->assertSee('Import Guide');
    });

    it('displays the developer guide', function () {
        $response = $this->get('/docs/developer');

        $response->assertStatus(200);
        $response->assertSee('Developer Guide');
    });

    it('displays the self-hosting guide', function () {
        $response = $this->get('/docs/self-hosting');

        $response->assertStatus(200);
        $response->assertSee('Self-Hosting Guide');
    });

    it('displays the MCP guide', function () {
        $response = $this->get('/docs/mcp');

        $response->assertStatus(200);
        $response->assertSee('MCP Server');
    });

    it('shows edit on GitHub link on documentation pages', function () {
        $response = $this->get('/docs/getting-started');

        $response->assertStatus(200);
        $response->assertSee('Edit this page on GitHub');
    });

    it('returns 404 for non-existent documentation page', function () {
        $response = $this->get('/docs/non-existent-page');

        $response->assertStatus(404);
    });

    it('can search documentation and returns results', function () {
        $response = $this->get('/docs/search?query=import');

        $response->assertStatus(200);
        $response->assertSee('Import');
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

        $this->get('/blog/feed')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/rss+xml');
    });

    it('includes blog link in navigation', function () {
        $this->get('/')
            ->assertStatus(200)
            ->assertSee(route('blog.index'));
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
        $response->assertSee('Mark them all as done');
        // Approval action card shows the operation badge ("Update") + summary.
        $response->assertSee('Update');
        $response->assertSee('Add Sarah Chen');
        $response->assertSee('VP of Engineering');
    });

    it('places all message content in the initial HTML so reduced-motion users see it', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        // Exchange 1
        $response->assertSee('You have 3 overdue tasks');
        // Exchange 2 climax
        $response->assertSee('Mark 3 tasks complete');
        $response->assertSee('Call Sarah Chen · Send proposal · Schedule demo');
        // Exchange 3
        $response->assertSee('Added Sarah and linked her to Kovra Systems');
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

        // Hovering between the preview and the Ask Relaticle tab must not
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
            ->toContain("scrollToShow('.mcp-action-card')")
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

    it('shows three example prompt chips to anchor the demo', function () {
        $response = $this->get('/');
        $response->assertSuccessful();

        $response->assertSee("What's overdue this week?", false);
        $response->assertSee("Show this week's pipeline", false);
        $response->assertSee('Add a new contact');
    });

    it('renders the empty My Tasks section mirroring the dashboard', function () {
        $response = $this->get('/');
        $response->assertSuccessful();

        $response->assertSee(__('filament/pages/dashboard.tasks.empty.title'));
        $response->assertSee(__('filament/pages/dashboard.tasks.empty.description'));
        $response->assertSee(__('filament/pages/dashboard.tasks.view_all'));
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
