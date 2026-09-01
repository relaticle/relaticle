<?php

declare(strict_types=1);

use App\Features\SocialAuth;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Http\Responses\PasskeyLoginResponse;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\UserSocialAccount;
use Filament\Auth\Notifications\VerifyEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;

mutates(Login::class);
mutates(PasskeyLoginResponse::class);

beforeEach(function (): void {
    RateLimiter::clear('login-discover-ip:127.0.0.1');
});

test('login screen can be rendered', function () {
    $response = $this->get(url()->getAppUrl('login'));

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertRedirect(url()->getAppUrl((string) $team->slug));

    $this->assertAuthenticated();
});

test('users cannot authenticate with invalid password', function () {
    $user = User::factory()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->fillForm(['password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

test('login email field has autocomplete=username webauthn for conditional mediation', function (): void {
    livewire(Login::class)
        ->assertSeeHtml('autocomplete="username webauthn"');
});

test('PasskeyLoginResponse contract resolves to our admin-panel response', function (): void {
    expect(app(PasskeyLoginResponseContract::class))->toBeInstanceOf(PasskeyLoginResponse::class);
});

test('the passkey login endpoint is rate limited', function (): void {
    $lastStatus = 200;

    foreach (range(1, 40) as $ignored) {
        $lastStatus = $this->postJson('/passkeys/login', [])->getStatusCode();

        if ($lastStatus === 429) {
            break;
        }
    }

    expect($lastStatus)->toBe(429);
});

test('the passkey confirmation endpoint is rate limited', function (): void {
    $this->actingAs(User::factory()->create());

    $lastStatus = 200;

    foreach (range(1, 40) as $ignored) {
        $lastStatus = $this->postJson('/passkeys/confirm', [])->getStatusCode();

        if ($lastStatus === 429) {
            break;
        }
    }

    expect($lastStatus)->toBe(429);
});

test('passkey login is allowed for active users', function (): void {
    $user = User::factory()->create();
    $passkey = Passkey::create([
        'user_id' => $user->id,
        'name' => 'Test',
        'credential_id' => 'authorize-active-'.uniqid(),
        'credential' => [],
    ]);

    expect(Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey))->toBeTrue();
});

test('passkey login is allowed for users scheduled for deletion so they reach the cancellation interstitial', function (): void {
    // Consistent with password and social login: a scheduled-for-deletion user authenticates and
    // the CheckScheduledDeletion middleware routes them to the interstitial where they can cancel.
    // Blocking only the passkey path left passwordless users with a confusing dead-end.
    $user = User::factory()->create([
        'scheduled_deletion_at' => now()->subDay(),
    ]);
    $passkey = Passkey::create([
        'user_id' => $user->id,
        'name' => 'Test',
        'credential_id' => 'authorize-scheduled-'.uniqid(),
        'credential' => [],
    ]);

    expect(Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey))->toBeTrue();
});

test('continue with a password account reveals the password field', function (): void {
    $user = User::factory()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password');
});

test('continue with an unknown email offers to sign up', function (): void {
    livewire(Login::class)
        ->fillForm(['email' => 'nobody-'.uniqid().'@example.com'])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup');
});

test('continue with a passkey account dispatches the passkey challenge', function (): void {
    $user = User::factory()->create();
    Passkey::create([
        'user_id' => $user->id,
        'name' => 'Key',
        'credential_id' => 'discover-'.uniqid(),
        'credential' => [],
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'passkey')
        ->assertSet('passkeyUserHasPassword', true)
        ->assertDispatched('passkey-login');
});

test('a passkey user can fall back to their password', function (): void {
    $user = User::factory()->create();
    Passkey::create([
        'user_id' => $user->id,
        'name' => 'Key',
        'credential_id' => 'fallback-'.uniqid(),
        'credential' => [],
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'passkey')
        ->call('usePassword')
        ->assertSet('authMethod', 'password')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
});

test('continue with a social-only account switches to the social method', function (): void {
    $user = User::factory()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'google',
        'provider_id' => 'g-'.uniqid(),
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'social');
});

test('two step password login still authenticates', function (): void {
    $user = User::factory()->withTeam()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
});

test('editing the email resets discovery', function (): void {
    $user = User::factory()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password')
        ->fillForm(['email' => 'someone-else-'.uniqid().'@example.com'])
        ->assertSet('authMethod', null);
});

test('re-pressing continue on a passkey account re-dispatches without consuming rate limit', function (): void {
    $user = User::factory()->create();
    Passkey::create([
        'user_id' => $user->id,
        'name' => 'Key',
        'credential_id' => 'repress-'.uniqid(),
        'credential' => [],
    ]);

    $key = 'login-discover:'.mb_strtolower($user->email).'|127.0.0.1';
    RateLimiter::clear($key);

    $component = livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'passkey');

    $attemptsAfterFirstDiscovery = RateLimiter::attempts($key);

    $component
        ->call('authenticate')
        ->assertDispatched('passkey-login');

    expect(RateLimiter::attempts($key))->toBe($attemptsAfterFirstDiscovery);
});

test('a social only account discovery is treated as password when the flag is off', function (): void {
    Feature::define(SocialAuth::class, false);
    Feature::flushCache();

    $user = User::factory()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'google',
        'provider_id' => 'g-'.uniqid(),
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password');
});

test('discovery is rate limited per email and ip', function (): void {
    $email = 'throttled-'.uniqid().'@example.com';
    $key = 'login-discover:'.mb_strtolower($email).'|127.0.0.1';
    RateLimiter::clear($key);

    for ($i = 0; $i < 5; $i++) {
        livewire(Login::class)
            ->fillForm(['email' => $email])
            ->call('authenticate')
            ->assertSet('authMethod', 'signup');
    }

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);
});

test('a client cannot preset the auth method by writing the locked property directly', function (): void {
    expect(fn () => livewire(Login::class)->set('authMethod', 'junk'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('discovery matches a stored email case-insensitively for passkey users', function (): void {
    $user = User::factory()->create(['email' => 'MixedCase@Example.com']);
    Passkey::create([
        'user_id' => $user->id,
        'name' => 'Key',
        'credential_id' => 'mixedcase-'.uniqid(),
        'credential' => [],
    ]);

    livewire(Login::class)
        ->fillForm(['email' => 'mixedcase@example.com'])
        ->call('authenticate')
        ->assertSet('authMethod', 'passkey');
});

test('a legacy github-only account falls back to the password field', function (): void {
    $user = User::factory()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'github',
        'provider_id' => 'gh-'.uniqid(),
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password');
});

test('a social-only account with no linked social row falls back to the password field', function (): void {
    $user = User::factory()->socialOnly()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password');
});

test('a microsoft-only account falls back to the password field when microsoft is not configured', function (): void {
    config(['services.microsoft.client_id' => null]);

    $user = User::factory()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'microsoft',
        'provider_id' => 'ms-'.uniqid(),
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password');
});

test('a microsoft-only account switches to the social method when microsoft is configured', function (): void {
    config(['services.microsoft.client_id' => 'configured-client-id']);

    $user = User::factory()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'microsoft',
        'provider_id' => 'ms-'.uniqid(),
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('authMethod', 'social');
});

test('discovery is rate limited per ip across different emails', function (): void {
    $ipKey = 'login-discover-ip:127.0.0.1';
    RateLimiter::clear($ipKey);

    $emails = [];

    for ($i = 0; $i < 20; $i++) {
        $email = 'sweep-'.$i.'-'.uniqid().'@example.com';
        $emails[] = $email;
        RateLimiter::clear('login-discover:'.mb_strtolower($email).'|127.0.0.1');

        livewire(Login::class)
            ->fillForm(['email' => $email])
            ->call('authenticate')
            ->assertSet('authMethod', 'signup');
    }

    $blockedEmail = 'sweep-blocked-'.uniqid().'@example.com';
    RateLimiter::clear('login-discover:'.mb_strtolower($blockedEmail).'|127.0.0.1');

    livewire(Login::class)
        ->fillForm(['email' => $blockedEmail])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);
});

test('social hint renders after discovering a social-only account', function (): void {
    $user = User::factory()->socialOnly()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => 'google',
        'provider_id' => 'g-'.uniqid(),
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSee(__('auth.login.social_hint'));
});

test('password fallback link renders only for passkey users with a password', function (): void {
    $user = User::factory()->create();
    Passkey::create([
        'user_id' => $user->id,
        'name' => 'Key',
        'credential_id' => 'fallback-link-'.uniqid(),
        'credential' => [],
    ]);

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSee(__('auth.login.use_password'));
});

test('login page renders the google button and no passkey button', function (): void {
    $response = $this->get(url()->getAppUrl('login'));

    $response->assertSee(__('auth.login.continue_with', ['provider' => 'Google']));
    $response->assertDontSee('Sign in with a passkey');
});

test('microsoft button renders only when configured', function (): void {
    config(['services.microsoft.client_id' => null]);
    $this->get(url()->getAppUrl('login'))->assertDontSee('Microsoft');

    config(['services.microsoft.client_id' => 'test-client']);
    $this->get(url()->getAppUrl('login'))->assertSee('Microsoft');
});

test('signup mode reveals a single password field with a helper text and a sign up label', function (): void {
    livewire(Login::class)
        ->fillForm(['email' => 'reveal-signup-'.uniqid().'@gmail.com'])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->assertSee(__('auth.login.password_helper'))
        ->assertSee(__('auth.login.sign_up'));
});

test('signup guesses a title-cased name from the email local part', function (): void {
    $email = 'jane.doe_smith@gmail.com';
    RateLimiter::clear('login-discover:'.$email.'|127.0.0.1');

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(User::where('email', $email)->value('name'))->toBe('Jane Doe Smith');
});

test('signup creates a user and sends a verification email', function (): void {
    Notification::fake();

    $email = 'signup-verify-user@gmail.com';
    RateLimiter::clear('login-discover:'.$email.'|127.0.0.1');

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $user = User::where('email', $email)->first();

    expect($user)->not->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmail::class);

    $this->assertAuthenticated();
    expect($user->fresh()->remember_token)->not->toBeNull();
});

test('signup with a matching invitation auto-verifies the email and fires Verified', function (): void {
    Event::fake([Verified::class]);
    Notification::fake();

    $team = Team::factory()->create();
    $email = 'Invited-Signup-'.uniqid().'@Gmail.com';
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $email,
    ]);

    session(['url.intended' => "/team-invitations/{$invitation->id}/accept"]);

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $user = User::where('email', mb_strtolower($email))->first();

    expect($user)->not->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeTrue();

    Notification::assertNotSentTo($user, VerifyEmail::class);
    Event::assertDispatched(Verified::class, fn (Verified $event): bool => $event->user->is($user));

    $acceptUrl = URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]);

    $this->get($acceptUrl)->assertRedirect(Dashboard::getUrl(['tenant' => $team]));
});

test('a fresh teamless signup lands on tenant registration, not the login page', function (): void {
    $email = 'fresh-signup-'.uniqid().'@gmail.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect(url()->getAppUrl());

    $this->get(url()->getAppUrl())
        ->assertRedirect(url()->getAppUrl('new'));
});

test('signup rejects a submission caught by the honeypot', function (): void {
    config(['honeypot.enabled' => true]);

    $email = 'bot-signup-'.uniqid().'@gmail.com';

    $component = livewire(Login::class);

    $this->travel(2)->seconds();

    $component
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup');

    $component
        ->set('extraFields.my_name', 'I fill every field I see')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertForbidden();

    expect(User::where('email', $email)->exists())->toBeFalse();
});

test('signup registers normally when the honeypot stays empty and enough time has passed', function (): void {
    config(['honeypot.enabled' => true]);

    $email = 'human-signup-'.uniqid().'@gmail.com';

    $component = livewire(Login::class);

    $this->travel(2)->seconds();

    $component
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup');

    $component
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(User::where('email', $email)->exists())->toBeTrue();
});

test('discovery rejects a submission caught by the honeypot', function (): void {
    config(['honeypot.enabled' => true]);

    $component = livewire(Login::class);

    $this->travel(2)->seconds();

    $component
        ->set('extraFields.my_name', 'I fill every field I see')
        ->fillForm(['email' => 'bot-discover-'.uniqid().'@gmail.com'])
        ->call('authenticate')
        ->assertForbidden();

    expect($component->get('authMethod'))->toBeNull();
});

test('discovery is capped per ip per day independently of the minute window', function (): void {
    $ip = '127.0.0.1';
    RateLimiter::clear('login-discover-ip:'.$ip);

    $dayKey = 'login-discover-ip-day:'.$ip;
    RateLimiter::clear($dayKey);

    for ($i = 0; $i < 300; $i++) {
        RateLimiter::hit($dayKey, 86400);
    }

    livewire(Login::class)
        ->fillForm(['email' => 'day-capped-'.uniqid().'@example.com'])
        ->call('authenticate')
        ->assertHasFormErrors(['email'])
        ->assertSet('authMethod', null);
});

test('signup rejects a duplicate email at submission', function (): void {
    $email = 'dup-signup-'.uniqid().'@gmail.com';

    $component = livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup');

    User::factory()->create(['email' => $email]);

    $component
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(User::where('email', $email)->count())->toBe(1);
});

test('swapping the email after discovery via a wholesale data set does not create a user and still consumes a throttle hit', function (): void {
    $discoveredEmail = 'discover-swap-source-'.uniqid().'@gmail.com';
    $swappedEmail = 'discover-swap-target-'.uniqid().'@gmail.com';

    $swappedKey = 'login-discover:'.mb_strtolower($swappedEmail).'|127.0.0.1';
    RateLimiter::clear($swappedKey);

    $component = livewire(Login::class)
        ->fillForm(['email' => $discoveredEmail])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->assertSet('discoveredEmail', mb_strtolower($discoveredEmail));

    expect(RateLimiter::attempts($swappedKey))->toBe(0);

    $component
        ->set('data', ['email' => $swappedEmail, 'password' => 'Password123!'])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->assertSet('discoveredEmail', mb_strtolower($swappedEmail));

    expect(RateLimiter::attempts($swappedKey))->toBe(1)
        ->and(User::where('email', $swappedEmail)->exists())->toBeFalse()
        ->and(User::where('email', $discoveredEmail)->exists())->toBeFalse();

    $this->assertGuest();
});

test('a mixed-case signup is stored lowercase and can log back in with any casing', function (): void {
    $local = 'MixedCase-'.uniqid();
    $email = $local.'@Gmail.com';
    $canonical = mb_strtolower($email);

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(User::where('email', $canonical)->exists())->toBeTrue()
        ->and(User::where('email', $email)->exists())->toBeFalse();

    $this->assertAuthenticated();

    auth()->guard('web')->logout();
    $this->app['session']->flush();

    livewire(Login::class)
        ->fillForm(['email' => mb_strtoupper($local).'@GMAIL.COM'])
        ->call('authenticate')
        ->assertSet('authMethod', 'password')
        ->fillForm(['password' => 'Password123!'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
});

test('a legacy mixed-case account can log in with a lowercase-typed email after normalization', function (): void {
    User::factory()->withTeam()->create(['email' => 'Legacy-Mixed@Example.com', 'password' => 'password']);

    livewire(Login::class)
        ->fillForm(['email' => 'legacy-mixed@example.com'])
        ->call('authenticate')
        ->assertSet('authMethod', 'password')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
});

test('signup rejects a password shorter than 8 characters', function (): void {
    $email = 'short-pass-signup-'.uniqid().'@gmail.com';

    livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'signup')
        ->fillForm(['password' => 'short'])
        ->call('authenticate')
        ->assertHasFormErrors(['password']);

    expect(User::where('email', $email)->exists())->toBeFalse();
});

test('the remember me checkbox no longer renders on the login page', function (): void {
    $this->get(url()->getAppUrl('login'))
        ->assertDontSee(__('filament-panels::auth/pages/login.form.remember.label'));
});

test('password login always remembers the session even without a checkbox', function (): void {
    $user = User::factory()->withTeam()->create();

    livewire(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->fillForm(['password' => 'password'])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticated();
    expect($user->fresh()->remember_token)->not->toBeNull();
});
