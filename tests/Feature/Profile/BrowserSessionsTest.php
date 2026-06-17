<?php

declare(strict_types=1);

use App\Filament\Actions\ConfirmIdentityAction;
use App\Livewire\App\Profile\LogoutOtherBrowserSessions;
use App\Models\User;
use App\Support\Auth\IdentityConfirmation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;

mutates(LogoutOtherBrowserSessions::class, ConfirmIdentityAction::class, IdentityConfirmation::class);

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
