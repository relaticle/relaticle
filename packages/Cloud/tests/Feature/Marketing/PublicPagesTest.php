<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\DetectsPublicMarkdownRequest;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Relaticle\Cloud\Marketing\Http\Controllers\HomeController;
use Relaticle\Cloud\Marketing\Http\Controllers\PrivacyPolicyController;
use Relaticle\Cloud\Marketing\Http\Controllers\TermsOfServiceController;

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
        $response->assertSee('alt="Relaticle opportunities board grouped into pipeline stages, showing amount and close date"', false);
        $response->assertSee('alt="Relaticle companies list showing account owner, ICP status, and website domain for each company"', false);
        $response->assertSee('alt="Relaticle custom fields settings showing field name, type, constraints, and properties for Opportunities"', false);
    });

    it('fills the mockup frame with every hero screenshot rather than letting it letterbox', function () {
        $html = (string) $this->get('/')->assertStatus(200)->getContent();

        // The frame is a constant 826x640 from `lg` up and the screenshots are
        // captured at that ratio, but the frame turns tall on a phone. Without
        // object-cover the image sits at its own aspect inside a taller box and
        // leaves a dead band under it (measured: 124px at desktop, over half the
        // frame on a phone). object-left-top keeps the crop off the right, so the
        // sidebar the alt text describes is never the part that goes.
        expect(substr_count($html, 'object-cover object-left-top'))->toBe(3)
            ->and($html)->not->toContain('hero-preview-image w-full h-auto');
    });

    it('uses an existing raster logo in the organization json-ld', function () {
        $html = (string) $this->get('/')->assertStatus(200)->getContent();

        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $matches);

        $graph = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $organization = collect($graph['@graph'])->firstWhere('@type', 'Organization');

        expect($organization['logo'])->toEndWith('/web-app-manifest-512x512.png')
            ->and(file_exists(public_path('web-app-manifest-512x512.png')))->toBeTrue();
    });

    it('declares the social card size the image file actually has on :path', function (string $path) {
        $html = (string) $this->get($path)->assertStatus(200)->getContent();

        preg_match('#<meta property="og:image" content="[^"]+/images/([^"?]+)\?v=\d+"/>#', $html, $image);
        preg_match('#<meta property="og:image:width" content="(\d+)"/>#', $html, $width);
        preg_match('#<meta property="og:image:height" content="(\d+)"/>#', $html, $height);

        [$fileWidth, $fileHeight] = getimagesize(public_path("images/{$image[1]}"));

        expect((int) $width[1])->toBe($fileWidth)
            ->and((int) $height[1])->toBe($fileHeight);
    })->with(['/', '/ai', '/self-hosted']);
});

describe('Legal pages', function () {
    it('displays the terms of service page with product-specific content as :format', function (array $headers, string $contentType) {
        $response = $this->get('/terms-of-service', $headers);

        $response->assertStatus(200);
        expect($response->headers->get('Content-Type'))->toStartWith($contentType);

        $response->assertSee('Terms of Service');
        $response->assertSeeText('To request account deletion, email privacy@relaticle.com or contact us.');
        $response->assertSee('Relaticle');
        $response->assertDontSee('word usage');
        $response->assertDontSee('Basic" plan');
    })->with([
        'HTML' => [[], 'text/html'],
        'Markdown' => [['Accept' => 'text/markdown'], 'text/markdown'],
    ]);

    it('displays the privacy policy page with current MCP disclosures as :format', function (array $headers, string $contentType) {
        $response = $this->get('/privacy-policy', $headers);

        $response->assertSeeText('To request account deletion, email privacy@relaticle.com or contact us.');

        $response->assertStatus(200);
        expect($response->headers->get('Content-Type'))->toStartWith($contentType);

        $response->assertSee('Privacy Policy');
        $response->assertSee('Relaticle');
        $response->assertSee('privacy@relaticle.com');
        $response->assertSee('October 6, 2026');
        $response->assertSee('Data from a self-hosted installation stays on your servers unless you configure an external integration.');
        $response->assertSee('That integration may send authorized data to its provider.');
        $response->assertSee('Relaticle does not sell CRM data.');
        $response->assertSee('Relaticle does not use CRM data for advertising.');
        $response->assertSee('Relaticle does not train AI models on CRM data.');
        $response->assertSee('You can authorize an MCP client or AI provider to access your CRM data.');
        $response->assertSee('The provider receives only data requested through authorized tools.');
        $response->assertSee('The provider processes that data under its own terms and privacy policy.');
        $response->assertSee('Disconnecting the provider or revoking its token stops future access.');
        $response->assertSee('Relaticle enforces workspace and token scope on every tool request.');
        $response->assertSee('MCP write tools can change CRM records.');
        $response->assertSee('Task assignment operations can send transactional notifications.');
        $response->assertSee('User names, email addresses, and identifiers.');
        $response->assertSee('Workspace names and identifiers.');
        $response->assertSee('Workspace-member names, emails, and identifiers.');
        $response->assertSee('Token ability names.');
        $response->assertSee('Companies, people, opportunities, tasks, and notes.');
        $response->assertSee('Record identifiers and canonical record URLs.');
        $response->assertSee('Contact details.');
        $response->assertSee('Custom-field definitions, options, and values.');
        $response->assertSee('Relationships between records.');
        $response->assertSee('Opportunity stages and amounts.');
        $response->assertSee('Activity actors, field changes, and timestamps.');
        $response->assertSee('Record creation and update timestamps.');
        $response->assertSee('Pagination and count metadata.');
        $response->assertSee('Tool responses can include record identifiers, timestamps, pagination metadata, and count metadata.');
        $response->assertSee('Access tokens.');
        $response->assertSee('Refresh tokens.');
        $response->assertSee('Passwords.');
        $response->assertSee('API keys.');
        $response->assertSee('Authentication secrets.');
        $response->assertDontSee('registered mail');
        $response->assertDontSee('We do not share your CRM data with any third party.');
        $response->assertDontSee('Your data stays entirely on your servers.');
        $response->assertDontSee('internal timestamps');
    })->with([
        'HTML' => [[], 'text/html'],
        'Markdown' => [['Accept' => 'text/markdown'], 'text/markdown'],
    ]);

    it('discloses how connected Google mailbox and calendar data is used as :format', function (array $headers): void {
        $response = $this->get('/privacy-policy', $headers);

        $response->assertOk();
        $response->assertSeeText("Relaticle's use and transfer of information received from Google APIs to any other app will adhere to the", false);
        $response->assertSee('including the Limited Use requirements.', false);
        $response->assertSee('https://developers.google.com/terms/api-services-user-data-policy');
        $response->assertSee('The thread content goes to our AI provider to produce that summary.');
        $response->assertSee('Emails and meetings already synced stay in the workspace.');
        $response->assertSee('We never change, label, or delete messages in your mailbox.');
        $response->assertSee('small files that arrive inside it are stored with that message.');
        $response->assertSee('The summary is saved with the thread in your workspace.');
        $response->assertSee('the AI provider that writes a summary does not train its models on the thread.');
        $response->assertSee("We don't build aggregated or anonymized data sets from it.", false);
        $response->assertSee('This covers the data as received and anything derived from it, such as a thread summary.');
    })->with([
        'HTML' => [[]],
        'Markdown' => [['Accept' => 'text/markdown']],
    ]);
});

describe('Pricing page', function () {
    it('displays the pricing page', function () {
        $response = $this->get('/pricing');

        $response->assertStatus(200);
        $response->assertSee('No per-seat pricing');
    });
});

describe('Feature counts', function () {
    it('quotes no tool or field type count on a marketing page', function (string $path): void {
        $text = preg_replace('/\s+/', ' ', strip_tags($this->get($path)->assertOk()->getContent()));

        expect($text)->not->toMatch('/\d+[ -](?:first-party )?(?:MCP )?tools?\b/i')
            ->not->toMatch('/\d+ (?:custom )?field types\b/i');
    })->with(['/', '/pricing', '/press', '/ai-native-crm', '/crm-for-claude', '/crm-for-chatgpt', '/alternatives/hubspot', '/compare/relaticle-vs-espocrm']);
});

describe('Community redirects', function () {
    it('redirects to discord', function () {
        config(['services.discord.invite_url' => 'https://discord.gg/example']);

        $response = $this->get('/discord');

        $response->assertRedirect('https://discord.gg/example');
    });
});

describe('Hero AI tab: conversation', function () {
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
        // The turn ends on the next-step strip (NextStepSuggester), which is
        // what the shipped transcript puts at its floor once nothing is docked.
        $response->assertSee('Create a task for Sarah');
    });

    it('gates every write in the demo behind a review, like the real tools do', function () {
        $body = (string) $this->get('/')->assertSuccessful()->getContent();

        // Both writes the demo performs are proposals in the product
        // (BaseWriteCreateTool / BaseWriteUpdateTool return a PendingAction), so
        // each must be announced as a proposal and the dock must offer a
        // decision before it lands. Advertising an unattended write would
        // contradict the review-before-write contract the demo's own second
        // exchange is built to show off.
        //
        // Asserted on the review CHROME rather than on a card heading or an
        // outcome badge: the decided row renders the record as a chip plus an
        // operation label, and pinning that wording made this test fail on a
        // pure restyle while the invariant it guards -- no write without an
        // approval -- still held.
        expect(substr_count($body, 'Review before continuing'))->toBeGreaterThanOrEqual(1)
            ->and($body)->toContain('Review the proposal below to update the task')
            ->and($body)->toContain('Review the proposal below to add her to Kovra Systems')
            ->and($body)->toContain('Discard')
            ->and($body)->toContain('Save changes');
    });

    it('mirrors the shipped transcript surfaces rather than the components it replaced', function () {
        $body = (string) $this->get('/')->assertSuccessful()->getContent();

        // The user bubble is neutral gray, never brand-tinted: see the comment
        // on _transcript.blade.php's bubble. In this panel the only brand color
        // belongs to the docked proposal, which is what the eye should find.
        expect($body)->toContain('rounded-br-md bg-gray-100')
            ->and($body)->not->toContain('rounded-br-md bg-primary-50')
            ->and($body)->not->toContain('rounded-br-md bg-primary-600');

        // The docked proposal names the operation it is asking to approve; the
        // decided row it collapses into carries only the operation-tinted entity
        // tile and the record label in bold, NOT a record pill (chips are
        // reserved for inline clickable references).
        expect($body)->toContain('Create Person')
            ->and($body)->toContain('Update Task')
            ->and($body)->not->toContain('uppercase tracking-wider text-amber-600');

        // Read results render as a records_table with a chip-linked core column,
        // not the deleted chat/data-table component.
        expect($body)->toContain('chat-chip');
    });
});

describe('Hero AI tab: app shell', function () {
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
        $response->assertSee('Renewal prep: Daniel Okafor', false);
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

describe('Hero AI tab: demo CTA', function () {
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

describe('Response meta', function () {
    it('returns proper content type', function () {
        $response = $this->get('/');

        $response->assertHeader('Content-Type');
        $response->assertSuccessful();
    });
});

describe('Hero AI tab: animation timeline', function () {
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
        // single magic number. Timing is composed from entryHold +
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

describe('Hero AI tab: entry phase', function () {
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

describe('Page metadata', function () {
    it('keeps the title, description and heading of :path within search-result bounds', function (string $path, bool $expectsHeading) {
        $html = $this->get($path)->assertOk()->getContent();

        preg_match('/<title>(.*?)<\/title>/s', $html, $title);
        preg_match('/<meta name="description" content="([^"]*)"/', $html, $description);

        $titleText = html_entity_decode(trim($title[1] ?? ''));
        $descriptionText = html_entity_decode($description[1] ?? '');

        expect(mb_strlen($titleText))->toBeLessThanOrEqual(60, "{$path} title: {$titleText}")
            ->and(mb_strlen($titleText))->toBeGreaterThanOrEqual(20, "{$path} title: {$titleText}")
            ->and(mb_strlen($descriptionText))->toBeLessThanOrEqual(160, "{$path} description: {$descriptionText}")
            ->and(mb_strlen($descriptionText))->toBeGreaterThanOrEqual(70, "{$path} description: {$descriptionText}")
            ->and($titleText.$descriptionText)->not->toContain("\u{2014}");

        if ($expectsHeading) {
            expect(substr_count($html, '<h1'))->toBe(1, "{$path} must render exactly one h1");
        }
    })->with([
        ['/', true],
        ['/pricing', true],
        ['/ai', true],
        ['/ai-native-crm', true],
        ['/crm-for-claude', true],
        ['/crm-for-chatgpt', true],
        ['/self-hosted', true],
        ['/security', true],
        ['/press', true],
        ['/contact', true],
        ['/developers/api', false],
        ['/blog', true],
        ['/compare/relaticle-vs-twenty', true],
        ['/compare/relaticle-vs-espocrm', true],
        ['/alternatives/attio', true],
        ['/alternatives/hubspot', true],
        ['/privacy-policy', true],
        ['/terms-of-service', true],
    ]);

    it('renders one heading on each legal page and keeps it in the markdown version', function (string $path, string $heading) {
        $html = $this->get($path)->assertOk()->getContent();
        $markdown = $this->get($path, ['Accept' => 'text/markdown'])->assertOk()->getContent();

        expect(substr_count($html, '<h1'))->toBe(1)
            ->and(preg_match_all('/^\s*(# )?'.preg_quote($heading, '/').'\s*$/m', $markdown))->toBe(1);
    })->with([
        ['/privacy-policy', 'Privacy Policy'],
        ['/terms-of-service', 'Terms of Service'],
    ]);
});

it('keeps marketing pages indexable', function (): void {
    $this->get('/pricing')->assertHeaderMissing('X-Robots-Tag');
});

describe('Canonical', function () {
    it('leaves the canonical on non-paginating marketing pages query-free', function (string $path) {
        // The page-aware canonical belongs to the blog listing, not to the shared
        // guest layout: a crawler-discovered ?page=N on pricing or the homepage
        // used to self-canonicalise into an indexable duplicate.
        $this->get($path.'?page=7')
            ->assertStatus(200)
            ->assertSee('<link rel="canonical" href="'.url($path).'" />', false);
    })->with(['/pricing', '/terms-of-service', '/privacy-policy']);
});
