<?php

declare(strict_types=1);

use App\Filament\Actions\ConfirmIdentityAction;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\EnsureAuthenticationComplete;
use App\Livewire\App\Profile\LogoutOtherBrowserSessions;
use App\Models\User;
use App\Support\Auth\IdentityConfirmation;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;

mutates(LogoutOtherBrowserSessions::class, ConfirmIdentityAction::class, IdentityConfirmation::class);
mutates(EnsureAuthenticationComplete::class);

test('social user can log out other sessions without confirmation', function (): void {
    $this->actingAs(User::factory()->withTeam()->socialOnly()->create());
    session()->put('auth.password_confirmed_at', time());

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->call('logoutOtherBrowserSessions')
        ->assertSuccessful();
});

test('password user can log out other sessions after confirmation', function (): void {
    $this->actingAs(User::factory()->withTeam()->create());
    session()->put('auth.password_confirmed_at', time());

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->call('logoutOtherBrowserSessions')
        ->assertSuccessful();
});

test('blocked without confirmation', function (): void {
    $this->actingAs(User::factory()->withTeam()->create());
    session()->forget('auth.password_confirmed_at');

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->call('logoutOtherBrowserSessions')
        ->assertNotified(__('profile.notifications.identity_confirmation_failed.title'));
});

test('a passkey-only user (no password, no social account) can log out other sessions through the ceremony', function (): void {
    config(['session.driver' => 'database']);

    $user = User::factory()->withTeam()->create(['password' => null]);
    $this->actingAs($user);

    Passkey::create([
        'user_id' => $user->id,
        'name' => 'Only Key',
        'credential_id' => 'cred-'.uniqid(),
        'credential' => [],
    ]);

    $component = Livewire::test(LogoutOtherBrowserSessions::class)
        ->callAction('deleteBrowserSessions')
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony');

    IdentityConfirmation::markConfirmed();

    $component->callMountedAction()
        ->assertNotified(__('profile.notifications.logged_out_other_sessions.success'));
});

test('a social-only user can log out other sessions through the real modal after a realistic delay', function (): void {
    config(['session.driver' => 'database']);

    $user = User::factory()->withTeam()->socialOnly()->create();
    $this->actingAs($user);
    session()->put('auth.password_confirmed_at', time());

    $this->travel(10)->seconds();

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->callAction('deleteBrowserSessions')
        ->assertNotified(__('profile.notifications.logged_out_other_sessions.success'));
});

test('password user with a passkey triggers the ceremony', function (): void {
    $this->actingAs($user = User::factory()->withTeam()->create());
    session()->forget('auth.password_confirmed_at');

    Passkey::create([
        'user_id' => $user->id,
        'name' => 'My MacBook',
        'credential_id' => 'cred-'.uniqid(),
        'credential' => [],
    ]);

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->callAction('deleteBrowserSessions')
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony');
});

test('confirmed passkey logout rotates the remember token', function (): void {
    config(['session.driver' => 'database']);
    session()->put('auth.password_confirmed_at', time());

    $this->actingAs($user = User::factory()->withTeam()->create());
    $user->forceFill(['remember_token' => 'original-token'])->save();

    Passkey::create([
        'user_id' => $user->id,
        'name' => 'My MacBook',
        'credential_id' => 'cred-'.uniqid(),
        'credential' => [],
    ]);

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->call('logoutOtherBrowserSessions');

    expect($user->refresh()->remember_token)->not->toBe('original-token');
});

test('deletes other sessions and sends success notification', function (): void {
    config(['session.driver' => 'database']);
    session()->put('auth.password_confirmed_at', time());

    $this->actingAs($user = User::factory()->withTeam()->create());

    $currentSessionId = Session::getId();

    DB::table(config('session.table', 'sessions'))->insert([
        'id' => 'other-session-1',
        'user_id' => $user->getAuthIdentifier(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla/5.0',
        'payload' => base64_encode('test'),
        'last_activity' => time(),
    ]);

    DB::table(config('session.table', 'sessions'))->insert([
        'id' => 'other-session-2',
        'user_id' => $user->getAuthIdentifier(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Mozilla/5.0',
        'payload' => base64_encode('test'),
        'last_activity' => time(),
    ]);

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->call('logoutOtherBrowserSessions')
        ->assertNotified(__('profile.notifications.logged_out_other_sessions.success'));

    expect(
        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->where('id', '!=', $currentSessionId)
            ->count()
    )->toBe(0);
});

test('keeps the current session while deleting the others', function (): void {
    config(['session.driver' => 'database']);
    session()->put('auth.password_confirmed_at', time());

    $this->actingAs($user = User::factory()->withTeam()->create());

    $currentSessionId = Session::getId();

    foreach ([$currentSessionId, 'other-session-1'] as $id) {
        DB::table(config('session.table', 'sessions'))->insert([
            'id' => $id,
            'user_id' => $user->getAuthIdentifier(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0',
            'payload' => base64_encode('test'),
            'last_activity' => time(),
        ]);
    }

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->call('logoutOtherBrowserSessions');

    expect(DB::table(config('session.table', 'sessions'))->where('id', $currentSessionId)->exists())->toBeTrue()
        ->and(DB::table(config('session.table', 'sessions'))->where('id', 'other-session-1')->exists())->toBeFalse();
});

test('browser sessions component renders correctly', function (): void {
    $this->actingAs(User::factory()->withTeam()->create());

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->assertSuccessful()
        ->assertSee('Browser Sessions');
});

test('a confirmation older than the confirmation window no longer satisfies the gate', function (): void {
    $this->actingAs(User::factory()->withTeam()->create());
    session()->put('auth.password_confirmed_at', time() - 1000); // ~16.6 min > 900s window

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->call('logoutOtherBrowserSessions')
        ->assertNotified(__('profile.notifications.identity_confirmation_failed.title'));
});

test('a confirmation inside the confirmation window still satisfies the gate', function (): void {
    config(['session.driver' => 'database']);
    $this->actingAs(User::factory()->withTeam()->create());
    session()->put('auth.password_confirmed_at', time() - 60);

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->call('logoutOtherBrowserSessions')
        ->assertNotified(__('profile.notifications.logged_out_other_sessions.success'));
});

test('a genuine remember-me cookie with enrolled MFA is suspended and its recaller cookie cleared', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $rememberToken = Str::random(60);
    $user->forceFill(['remember_token' => $rememberToken])->save();

    $recallerName = 'remember_web_'.sha1(SessionGuard::class);
    $recallerValue = "{$user->getAuthIdentifier()}|{$rememberToken}|{$user->password}";

    $this->withCookie($recallerName, $recallerValue)
        ->get(Dashboard::getUrl(['tenant' => $user->currentTeam]))
        ->assertRedirect(route('two-factor.login'))
        ->assertCookieExpired($recallerName);

    $this->assertGuest('web');
    expect($user->fresh()->remember_token)->toBe($rememberToken);
});

test('a restored session with enrolled MFA is suspended before reaching a protected page', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $user->forceFill(['remember_token' => 'original-remember-token'])->save();

    $this->actingAs($user);

    $this->get(Dashboard::getUrl(['tenant' => $user->currentTeam]))
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest('web');
    expect(session('auth.pending.method'))->toBe('remembered');
    expect($user->fresh()->remember_token)->toBe('original-remember-token');
});

test('a restored session stays challenged across repeated requests until MFA completes', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);

    $dashboard = Dashboard::getUrl(['tenant' => $user->currentTeam]);

    $this->get($dashboard)->assertRedirect(route('two-factor.login'));
    $this->assertGuest('web');

    $this->get($dashboard)->assertRedirect();
    $this->assertGuest('web');
});

test('completing the challenge after a restored-session suspension resumes the original destination', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $team = $user->currentTeam;
    $this->actingAs($user);

    $this->get("/app/{$team->slug}/companies")->assertRedirect(route('two-factor.login'));
    $this->assertGuest('web');

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-one',
    ])->assertRedirect("/app/{$team->slug}/companies");

    $this->assertAuthenticatedAs($user);
});
test('suspending a non-remembered incomplete session does not escalate into a persistent remember-me cookie', function (): void {
    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);

    $this->get(Dashboard::getUrl(['tenant' => $user->currentTeam]))
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest('web');

    $response = $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-one',
    ]);

    $response->assertRedirect();
    $this->assertAuthenticatedAs($user);

    $recallerName = 'remember_web_'.sha1(SessionGuard::class);
    $response->assertCookieMissing($recallerName);
});

test('a restored session with enrolled MFA dispatches the same challenge event every other primary method uses', function (): void {
    Event::fake([TwoFactorAuthenticationChallenged::class]);

    $user = User::factory()->withTeam()->withConfirmedMfa()->create();
    $this->actingAs($user);

    $this->get(Dashboard::getUrl(['tenant' => $user->currentTeam]))
        ->assertRedirect(route('two-factor.login'));

    Event::assertDispatched(
        TwoFactorAuthenticationChallenged::class,
        fn (TwoFactorAuthenticationChallenged $event): bool => $event->user->is($user),
    );
});
