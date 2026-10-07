<?php

declare(strict_types=1);

use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Features\SetupConversation;
use App\Filament\Pages\CreateWorkspace;
use App\Filament\Pages\SetupWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceActivationFacts;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Symfony\Component\DomCrawler\Crawler;

mutates(CreateWorkspace::class, SetupWorkspace::class);

const ACTION_MODALS_READY = 'new Promise(resolve => { const ready = () => document.querySelector(\'[x-data^="filamentActionModals"]\')?._x_dataStack !== undefined; const poll = () => ready() ? resolve(true) : setTimeout(poll, 25); poll(); })';

function walkToMailboxStep(User $user, string $name, string $slug): AwaitableWebpage
{
    return loginViaBrowser($user)
        ->assertPathIs('/app/new')
        ->navigate('/app/new')
        ->type('[id="form.name"]', $name)
        ->type('[id="form.slug"]', $slug)
        ->press('button:visible:has-text("Continue")')
        ->waitForText('How did you hear about us?')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Start with the people you already email')
        ->assertPathIs("/app/{$slug}/setup");
}

it('records onboarding conversions after navigation without counting them again', function (string $event): void {
    $page = visit('/app/login');
    $page->script('window.fathomEvents = []; window.fathomPageviews = []; window.fathom = { trackEvent: event => window.fathomEvents.push(event), trackPageview: options => window.fathomPageviews.push(options.url) };');

    $environment = app()->environment();
    app()->instance('env', 'production');
    config()->set('services.fathom.site_id', 'TESTSITE');
    session()->put('fathom.track_'.$event, true);

    try {
        $html = view('filament.app.analytics')->render();
    } finally {
        app()->instance('env', $environment);
    }

    foreach (new Crawler($html)->filter('script:not([src])') as $script) {
        $source = json_encode($script->textContent, JSON_THROW_ON_ERROR);
        $page->script('(() => { const script = document.createElement("script"); script.textContent = '.$source.'; document.head.append(script); })()');
    }

    $page->script('Livewire.navigate("/app/login?tracking=first")');
    $page->assertScript('window.location.search', '?tracking=first')
        ->assertScript('window.fathomEvents', [$event])
        ->assertScript('window.fathomPageviews.map(url => new URL(url).pathname + new URL(url).search)', ['/login']);

    $page->script('Livewire.navigate("/app/login?tracking=second")');
    $page->assertScript('window.location.search', '?tracking=second')
        ->assertScript('window.fathomEvents', [$event])
        ->assertScript('window.fathomPageviews.map(url => new URL(url).pathname + new URL(url).search)', ['/login', '/login'])
        ->assertNoJavaScriptErrors();
})->with(['signup', 'workspace_created']);

it('walks a new owner through every setup screen to the setup conversation', function (): void {
    Feature::define(SetupConversation::class, true);
    config()->set('services.azure.client_id', 'azure-client');
    Queue::fake();

    $user = User::factory()->create();

    loginViaBrowser($user)
        ->assertPathIs('/app/new')
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
        ->assertSee('Your name')
        ->type('[id="form.name"]', 'My First Workspace')
        ->type('[id="form.slug"]', 'my-first-workspace')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('How did you hear about us?')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Start with the people you already email')
        ->assertPathIs('/app/my-first-workspace/setup')
        ->assertNoJavaScriptErrors()
        ->assertSee('Continue with Google')
        ->assertSee('Continue with Microsoft')
        ->assertScript(ACTION_MODALS_READY, true)
        ->press("I'll add people and companies myself")
        ->waitForText('Continue without your mailbox?')
        ->press('button:visible:has-text("Yes, I\'m sure")')
        ->waitForText('Help us customize your workspace')
        ->click('[for$="onboarding_use_case-other"]')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Invite your team')
        ->press('Get started')
        ->assertPathContains('/my-first-workspace/chats/')
        ->assertNoJavaScriptErrors();

    $user->refresh();

    $workspace = $user->ownedWorkspaces->first();

    expect($user->ownedWorkspaces)->toHaveCount(1)
        ->and($workspace->name)->toBe('My First Workspace')
        ->and($workspace->onboarding_step)->toBeNull()
        ->and($workspace->setupConversation)->not->toBeNull();
});

it('returns an owner who left mid-setup to the step they were on', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    walkToMailboxStep($user, 'Resume Workspace', 'resume-workspace')
        ->navigate('/app/resume-workspace')
        ->waitForEvent('networkidle')
        ->assertPathIs('/app/resume-workspace/setup')
        ->assertSee('Start with the people you already email')
        ->assertScript(ACTION_MODALS_READY, true)
        ->press("I'll add people and companies myself")
        ->waitForText('Continue without your mailbox?')
        ->press('button:visible:has-text("Yes, I\'m sure")')
        ->waitForText('Help us customize your workspace')
        ->navigate('/app/resume-workspace/companies')
        ->assertPathIs('/app/resume-workspace/setup')
        ->assertSee('Help us customize your workspace')
        ->assertDontSee('Start with the people you already email');
});

it('stores the sharing level an owner picks after a mailbox is connected', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    $page = walkToMailboxStep($user, 'Northwind Studio', 'northwind-studio');

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->getKey(),
        'workspace_id' => Workspace::query()->where('slug', 'northwind-studio')->value('id'),
        'email_address' => 'olivia@northwind.test',
    ]));

    app()->forgetInstance(WorkspaceActivationFacts::class);

    $page->navigate('/app/northwind-studio/setup')
        ->waitForText('Choose what your team sees')
        ->assertSee('olivia@northwind.test')
        ->assertSee('Subject line and participants')
        ->click('[data-tier="subject"]')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Help us customize your workspace')
        ->assertDontSee('Choose what your team sees');

    expect($user->refresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::SUBJECT);
});

it('moves the preview panel with the setup step', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $activeNavigation = 'document.querySelector("[data-preview-nav][data-active]")?.dataset.previewNav';
    $visibleStages = '[...document.querySelectorAll("[data-preview-stage]")].filter(stage => stage.offsetParent !== null).map(stage => stage.dataset.previewStage)';

    walkToMailboxStep($user, 'Hiring Desk', 'hiring-desk')
        ->assertScript($activeNavigation, 'people')
        ->assertScript($visibleStages, [])
        ->assertScript(ACTION_MODALS_READY, true)
        ->press("I'll add people and companies myself")
        ->waitForText('Continue without your mailbox?')
        ->press('button:visible:has-text("Yes, I\'m sure")')
        ->waitForText('Help us customize your workspace')
        ->assertScript($activeNavigation, 'dashboard')
        ->assertScript($visibleStages, [])
        ->click('[for$="onboarding_use_case-recruiting"]')
        ->waitForText('Pick what applies to you.')
        ->assertScript($activeNavigation, 'opportunities')
        ->assertScript($visibleStages, array_keys(OnboardingUseCase::Recruiting->pipelineStages()))
        ->press('Back')
        ->waitForText('Start with the people you already email')
        ->assertScript($activeNavigation, 'people')
        ->assertScript($visibleStages, []);
});

it('changes the invite button when an address is typed and confirms the copied link', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    $page = walkToMailboxStep($user, 'Acme Sales', 'acme-sales')
        ->assertScript(ACTION_MODALS_READY, true)
        ->press("I'll add people and companies myself")
        ->waitForText('Continue without your mailbox?')
        ->press('button:visible:has-text("Yes, I\'m sure")')
        ->waitForText('Help us customize your workspace')
        ->click('[for$="onboarding_use_case-other"]')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Invite your team')
        ->assertDontSee('Send invites and get started')
        ->type('[id="form.emails"]', 'maya@acme.com')
        ->assertSee('Send invites and get started');

    $page->assertSee('Copy link')
        ->script('navigator.clipboard.writeText = () => Promise.resolve()');

    $page->assertDontSee('Link copied.')
        ->press('Copy link')
        ->assertSee('Link copied.');
});

it('shows the invite link to copy by hand when the clipboard is unavailable', function (string $clipboard): void {
    Queue::fake();

    $user = User::factory()->create();
    $manualLink = '[data-invite-link-manual]';

    $page = walkToMailboxStep($user, 'Acme Sales', 'acme-sales')
        ->assertScript(ACTION_MODALS_READY, true)
        ->press("I'll add people and companies myself")
        ->waitForText('Continue without your mailbox?')
        ->press('button:visible:has-text("Yes, I\'m sure")')
        ->waitForText('Help us customize your workspace')
        ->click('[for$="onboarding_use_case-other"]')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Invite your team')
        ->assertMissing($manualLink);

    $token = Workspace::query()->where('slug', 'acme-sales')->value('invite_link_token');

    $page->script("(() => { {$clipboard} })()");

    $page->press('Copy link')
        ->assertVisible($manualLink)
        ->assertScript("new URL(document.querySelector('{$manualLink}').value).pathname", route('workspaces.join', ['token' => $token], absolute: false))
        ->assertScript("(field => field.selectionStart === 0 && field.selectionEnd === field.value.length)(document.querySelector('{$manualLink}'))", true)
        ->assertDontSee('Link copied.');
})->with([
    'write rejected' => "navigator.clipboard.writeText = () => Promise.reject(new Error('denied'))",
    'no clipboard api' => "Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true })",
]);

it('stores the use case and its sub-option chosen in the browser', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    loginViaBrowser($user)
        ->assertPathIs('/app/new')
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
        ->type('[id="form.name"]', 'Hiring Desk')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('How did you hear about us?')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Start with the people you already email')
        ->assertScript(ACTION_MODALS_READY, true)
        ->press("I'll add people and companies myself")
        ->waitForText('Continue without your mailbox?')
        ->press('button:visible:has-text("Yes, I\'m sure")')
        ->waitForText('Help us customize your workspace')
        ->click('[for$="onboarding_use_case-recruiting"]')
        ->waitForText('Pick what applies to you.')
        ->click('[for$="onboarding_context-sourcing"]')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Invite your team')
        ->press('Get started')
        ->assertPathIs('/app/hiring-desk')
        ->assertSee('Workspace created');

    $user->refresh();

    $workspace = $user->ownedWorkspaces->first();

    expect($workspace->onboarding_use_case)->toBe(OnboardingUseCase::Recruiting)
        ->and($workspace->onboarding_context)->toBe(['sourcing'])
        ->and($workspace->name)->toBe('Hiring Desk');
});

it('stores the assistant and the question behind an AI referral picked in the browser', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    loginViaBrowser($user)
        ->assertPathIs('/app/new')
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
        ->type('[id="form.name"]', 'Assistant Desk')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('How did you hear about us?')
        ->assertDontSee('Which assistant was it?')
        ->click('[for$="onboarding_referral_source-ai"]')
        ->waitForText('Which assistant was it?')
        ->click('[for$="onboarding_referral_detail-claude"]')
        ->type('[id$="onboarding_referral_prompt"]', 'A CRM my assistant can update')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Start with the people you already email')
        ->assertScript(ACTION_MODALS_READY, true)
        ->press("I'll add people and companies myself")
        ->waitForText('Continue without your mailbox?')
        ->press('button:visible:has-text("Yes, I\'m sure")')
        ->waitForText('Help us customize your workspace')
        ->click('[for$="onboarding_use_case-other"]')
        ->press('button:visible:has-text("Continue")')
        ->waitForText('Invite your team')
        ->press('Get started')
        ->assertPathIs('/app/assistant-desk')
        ->assertSee('Workspace created');

    $workspace = $user->refresh()->ownedWorkspaces->first();

    expect($workspace->onboarding_referral_source)->toBe(OnboardingReferralSource::AI)
        ->and($workspace->onboarding_referral_detail)->toBe('claude')
        ->and($workspace->onboarding_referral_prompt)->toBe('A CRM my assistant can update');
});
