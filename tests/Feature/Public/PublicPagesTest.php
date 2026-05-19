<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PrivacyPolicyController;
use App\Http\Controllers\TermsOfServiceController;
use Illuminate\Support\Facades\Http;

mutates(HomeController::class, TermsOfServiceController::class, PrivacyPolicyController::class);

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

    it('marks the active chat conversation in the Chats group', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('hero-shell-chat-active', false);
        $response->assertSee('Chats');
    });

    it('renders the conversation title with the chat name', function () {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Overdue tasks this week');
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

    it('declares a single hold of ~2s after the final exchange before restart', function () {
        $response = $this->get('/');
        $response->assertSuccessful();

        // After exchange 3 ends near t=10.4s, the cycle should restart within
        // ~3s, not sit idle for 8s+. cycleMs is the post-ex3 active+hold budget.
        $body = $response->getContent();

        // 12000 = 12s total cycle (10.4s active + 1.6s rest) — preferred value.
        expect($body)->toContain('cycleMs: 12000');
        expect($body)->toContain('holdMs: 1500');
    });

    it('uses a unified Y-slide for assistant content', function () {
        $response = $this->get('/');
        $response->assertSuccessful();
        $body = $response->getContent();

        // After unification: every assistant child uses translateY for its slide.
        // The legacy mixed translateX(-6) on tool indicators is replaced.
        expect($body)->not->toContain("translateX('-6px')");
        expect($body)->not->toContain('translateX(-6px)');
    });

    it('schedules scroll-into-view 350ms before the following user message', function () {
        $response = $this->get('/');
        $response->assertSuccessful();
        $body = $response->getContent();

        // 4650 = exchange-2 user delay (5000) - 350ms lead
        // 8150 = exchange-3 user delay (8500) - 350ms lead
        expect($body)->toContain('4650');
        expect($body)->toContain('8150');
    });

    it('animates the 3 task rows as a single staggered group with 120ms spacing', function () {
        $response = $this->get('/');
        $response->assertSuccessful();
        $body = $response->getContent();

        // Reduce stagger: 2.0 / 2.12 / 2.24 (was 2.0 / 2.3 / 2.6)
        expect($body)->toContain('delay: 2.0');
        expect($body)->toContain('delay: 2.12');
        expect($body)->toContain('delay: 2.24');
    });

    it('delays the assistant bubble so it appears together with its first child', function () {
        $response = $this->get('/');
        $response->assertSuccessful();
        $body = $response->getContent();

        // After fix: bubble + first child share a delay so the bubble never
        // appears empty. Exchange 1 first child is mcp-tool-1 at delay 1.3.
        // Exchange 2 first child is mcp-text-2 at delay 5.8.
        // Exchange 3 first child is mcp-tool-3 at delay 9.3.
        // The bubble's avatar animation now matches its first child's delay.
        expect(substr_count($body, 'delay: 1.3,'))->toBeGreaterThanOrEqual(2);
        expect(substr_count($body, 'delay: 5.8,'))->toBeGreaterThanOrEqual(2);
        expect(substr_count($body, 'delay: 9.3,'))->toBeGreaterThanOrEqual(2);
    });
});
