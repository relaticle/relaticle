<?php

declare(strict_types=1);

use App\Enums\WorkspaceSetupStep;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\CreateWorkspace;
use App\Filament\Pages\SetupWorkspace;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Relaticle\Cloud\Analytics\Actions\AssociateAcquisitionWorkspace;
use Relaticle\Cloud\Analytics\Actions\CompleteAcquisitionSetup;
use Relaticle\Cloud\Analytics\Actions\RecordAcquisition;
use Relaticle\Cloud\Analytics\Attribution;
use Relaticle\Cloud\Analytics\Http\Middleware\CaptureAcquisition;
use Relaticle\Cloud\Analytics\Models\Acquisition;

mutates(Attribution::class, CaptureAcquisition::class, RecordAcquisition::class, AssociateAcquisitionWorkspace::class, CompleteAcquisitionSetup::class);

beforeEach(function (): void {
    app()->rebinding('request', fn (Application $app, Request $request) => $request->setLaravelSession($app['session.store']));
});

function registerAcquiredUser(): User
{
    $email = 'analytics-'.uniqid().'@gmail.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    return User::query()->where('email', $email)->sole();
}

it('keeps the first vetted acquisition across pages and registration', function (): void {
    config(['analytics.campaigns' => ['autumn-launch']]);
    $this->get('/pricing?utm_source=github&utm_medium=referral&utm_campaign=autumn-launch&email=private@example.com')->assertOk();
    $this->get(url()->getAppUrl('login').'?utm_source=google')->assertOk();

    $user = registerAcquiredUser();

    $this->assertDatabaseHas('acquisitions', [
        'user_id' => $user->getKey(), 'source' => 'github', 'medium' => 'referral',
        'campaign' => 'autumn-launch', 'landing' => 'pricing', 'workspace_id' => null,
    ]);
    expect(session()->has('analytics.acquisition'))->toBeFalse();
});

it('attributes signups arriving directly on developer documentation', function (string $path, int $status): void {
    $this->get($path.'?utm_source=github')->assertStatus($status);

    $user = registerAcquiredUser();

    $this->assertDatabaseHas('acquisitions', [
        'user_id' => $user->getKey(), 'source' => 'github', 'landing' => 'docs',
    ]);
})->with([
    'canonical index' => ['/developers', 200],
    'canonical guide' => ['/developers/mcp', 200],
    'legacy docs' => ['/docs', 301],
    'legacy documentation' => ['/documentation', 301],
]);

it('attributes signups arriving on a product page to that page', function (string $path, string $landing): void {
    Feature::define(EmailIntegration::class, true);
    $this->get($path.'?utm_source=reddit')->assertOk();

    $user = registerAcquiredUser();

    $this->assertDatabaseHas('acquisitions', [
        'user_id' => $user->getKey(), 'source' => 'reddit', 'landing' => $landing,
    ]);
})->with([
    'self-hosting' => ['/self-hosted', 'self-hosted'],
    'security' => ['/security', 'security'],
    'email and calendar' => ['/email-and-calendar', 'email-and-calendar'],
]);

it('attributes a visit from Hacker News by referrer or by tag', function (string $referrer, string $query): void {
    $this->withHeader('referer', $referrer)->get('/'.$query)
        ->assertSessionHas('analytics.acquisition.source', 'hackernews');
})->with([
    'thread referrer' => ['https://news.ycombinator.com/', ''],
    'tagged link' => ['', '?utm_source=hackernews'],
]);

it('drops unvetted campaign values and never stores raw referrers or landing paths', function (): void {
    $this->withHeader('referer', 'https://github.com.evil.test/private/person@example.com')
        ->get('/pricing?utm_source[]=github&utm_medium=person@example.com&utm_campaign=person@example.com')->assertOk();

    $user = registerAcquiredUser();

    $this->assertDatabaseHas('acquisitions', [
        'user_id' => $user->getKey(), 'source' => 'unknown', 'medium' => null,
        'campaign' => null, 'landing' => 'pricing',
    ]);
});

it('recognizes an allowlisted referrer without keeping its URL', function (): void {
    $this->withHeader('referer', 'https://www.github.com/relaticle/relaticle?private=secret')
        ->get('/pricing')->assertSessionHas('analytics.acquisition.source', 'github');

    expect(session('analytics.acquisition'))->toHaveKeys(['source', 'medium', 'campaign', 'landing', 'captured_at']);
    expect(json_encode(session('analytics.acquisition')))->not->toContain('private', 'secret', 'https://');
});

it('expires anonymous attribution after thirty days without inventing a source', function (int $days, string $source): void {
    $this->travelTo(now()->startOfSecond());
    $this->get('/pricing?utm_source=github')->assertOk();
    $this->travel($days)->days();
    $this->get('/contact')->assertOk();

    $user = registerAcquiredUser();

    $this->assertDatabaseHas('acquisitions', ['user_id' => $user->getKey(), 'source' => $source]);
})->with(['last valid day' => [30, 'github'], 'expired' => [31, 'unknown']]);

it('honors acquisition privacy opt-outs through registration', function (string $header): void {
    $this->get('/pricing?utm_source=github')->assertSessionHas('analytics.acquisition');
    $this->withHeader($header, '1')->get('/contact')->assertSessionMissing('analytics.acquisition');
    app()->rebinding('request', function (Application $app, Request $request) use ($header): void {
        $request->setLaravelSession($app['session.store']);
        $request->headers->set($header, '1');
    });

    registerAcquiredUser();

    $this->assertDatabaseCount('acquisitions', 0);
})->with(['Do Not Track' => 'DNT', 'Global Privacy Control' => 'Sec-GPC']);

it('does not backfill acquisition when an existing user logs in', function (): void {
    $user = User::factory()->create();
    $this->get('/pricing?utm_source=linkedin')->assertOk();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseCount('acquisitions', 0);
    expect(session('fathom.track_login_completed'))->toBeUuid();
});

it('retains acquisition and verification across a new OAuth account session rotation', function (): void {
    $this->get('/pricing?utm_source=github')->assertOk();
    Socialite::fake('google', (new SocialiteUser)->setRaw(['email_verified' => true])->map([
        'id' => 'analytics-google-user', 'name' => 'Maya Chen', 'email' => 'analytics-oauth@example.com',
    ]));

    $this->get(route('auth.socialite.callback', ['provider' => 'google', 'code' => 'test-code']))->assertRedirect();

    $user = User::query()->where('email', 'analytics-oauth@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('acquisitions', ['user_id' => $user->getKey(), 'source' => 'github', 'landing' => 'pricing']);
    expect(session('fathom.track_signup'))->toBeTrue();
    expect(session('fathom.track_email_verified'))->toBeUuid();
    expect(session('fathom.track_login_completed'))->toBeUuid();
});

it('counts explicit authentication only after MFA succeeds', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'))->assertSessionMissing('fathom.track_login_completed');
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'wrong-code'])
        ->assertSessionHasErrors()->assertSessionMissing('fathom.track_login_completed');
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one'])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect(session('fathom.track_login_completed'))->toBeUuid();
});

it('links only the first owned workspace and records setup only when it finishes', function (): void {
    $this->get('/pricing?utm_source=github')->assertOk();
    $user = registerAcquiredUser();
    $user->markEmailAsVerified();

    livewire(CreateWorkspace::class)->fillForm(['name' => 'Northstar Analytics'])->call('register')->assertHasNoFormErrors();
    $workspace = $user->fresh()->currentWorkspace;
    Filament::setTenant($workspace);
    $workspace->update(['onboarding_step' => WorkspaceSetupStep::Invite]);
    $this->assertDatabaseHas('acquisitions', ['user_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(), 'setup_completed_at' => null]);

    livewire(SetupWorkspace::class)->call('finish')->assertHasNoFormErrors();

    $acquisition = Acquisition::query()->findOrFail($user->getKey());
    expect($acquisition->setup_completed_at)->not->toBeNull();
    expect(session('fathom.track_setup_completed'))->toBeUuid();
    Filament::setTenant(null);
    livewire(CreateWorkspace::class)->fillForm(['name' => 'Northstar Expansion'])->call('register')->assertHasNoFormErrors();

    expect($acquisition->refresh()->workspace_id)->toBe($workspace->getKey());
});
