<?php

declare(strict_types=1);

use App\Enums\OnboardingUseCase;
use App\Features\SetupConversation;
use App\Filament\Pages\CreateWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Symfony\Component\DomCrawler\Crawler;

mutates(CreateWorkspace::class);

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

it('new user without workspaces is directed to onboarding wizard', function (): void {
    Feature::define(SetupConversation::class, true);
    Queue::fake();

    $user = User::factory()->create();

    loginViaBrowser($user)
        ->assertPathIs('/app/new')
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
        ->assertSee('Your name')
        // Step 1: Create workspace
        ->type('[id="form.name"]', 'My First Workspace')
        ->type('[id="form.slug"]', 'my-first-workspace')
        ->press('Continue')
        ->waitForText('How did you hear about us?')
        // Step 2: Attribution (optional, just proceed)
        ->press('Continue')
        ->waitForText('Help us customize your workspace')
        // Step 3: Use case (select "Other" which has no sub-options)
        ->click('[for$="onboarding_use_case-other"]')
        ->press('Get started')
        ->assertPathContains('/my-first-workspace/chats/');

    $user->refresh();

    $workspace = $user->ownedWorkspaces->first();

    expect($user->ownedWorkspaces)->toHaveCount(1)
        ->and($workspace->name)->toBe('My First Workspace')
        ->and($workspace->setupConversation)->not->toBeNull();
});

it('stores the use case and its sub-option chosen in the browser', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    loginViaBrowser($user)
        ->assertPathIs('/app/new')
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
        ->type('[id="form.name"]', 'Hiring Desk')
        ->press('Continue')
        ->waitForText('How did you hear about us?')
        ->press('Continue')
        ->waitForText('Help us customize your workspace')
        ->click('[for$="onboarding_use_case-recruiting"]')
        ->waitForText('Pick what applies to you.')
        ->click('[for$="onboarding_context-sourcing"]')
        ->press('Get started')
        ->assertPathContains('/hiring-desk');

    $user->refresh();

    $workspace = $user->ownedWorkspaces->first();

    expect($workspace->onboarding_use_case)->toBe(OnboardingUseCase::Recruiting)
        ->and($workspace->onboarding_context)->toBe(['sourcing'])
        ->and($workspace->name)->toBe('Hiring Desk');
});
