<?php

declare(strict_types=1);

use App\Filament\Actions\ConfirmIdentityAction;
use App\Livewire\App\Profile\DeleteAccount;
use App\Models\User;
use App\Notifications\UserDeletionScheduledNotification;
use App\Support\Auth\IdentityConfirmation;
use Illuminate\Support\Facades\Notification;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;

mutates(DeleteAccount::class, User::class, ConfirmIdentityAction::class, IdentityConfirmation::class);

test('schedules deletion after a fresh confirmation', function (): void {
    Notification::fake();

    $this->actingAs($user = User::factory()->withPersonalTeam()->create());
    session()->put('auth.password_confirmed_at', time());

    Livewire::test(DeleteAccount::class)
        ->call('deleteAccount')
        ->assertRedirect();

    expect($user->refresh()->scheduled_deletion_at)->not->toBeNull();

    Notification::assertSentTo($user, UserDeletionScheduledNotification::class);
});

test('blocked without a fresh confirmation', function (): void {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());
    session()->forget('auth.password_confirmed_at');

    Livewire::test(DeleteAccount::class)
        ->call('deleteAccount')
        ->assertNotified(__('profile.notifications.identity_confirmation_failed.title'));

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});

test('password user with a passkey triggers the ceremony', function (): void {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());
    session()->forget('auth.password_confirmed_at');

    Passkey::create([
        'user_id' => $user->id,
        'name' => 'My MacBook',
        'credential_id' => 'cred-'.uniqid(),
        'credential' => [],
    ]);

    Livewire::test(DeleteAccount::class)
        ->callAction('deleteAccount', ['confirm_email' => $user->email])
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony');

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});

test('a stale freshness window does not bypass the deletion ceremony', function (): void {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());
    session()->put('auth.password_confirmed_at', time() - 60);

    Passkey::create([
        'user_id' => $user->id,
        'name' => 'My MacBook',
        'credential_id' => 'cred-'.uniqid(),
        'credential' => [],
    ]);

    Livewire::test(DeleteAccount::class)
        ->callAction('deleteAccount', ['confirm_email' => $user->email])
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony');

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});

test('a stale freshness window still demands the password fallback', function (): void {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());
    session()->put('auth.password_confirmed_at', time() - 60);

    Livewire::test(DeleteAccount::class)
        ->callAction('deleteAccount', ['confirm_email' => $user->email, 'password' => 'wrong-password'])
        ->assertHasActionErrors(['password']);

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});

test('password fallback deletes account', function (): void {
    Notification::fake();

    $this->actingAs($user = User::factory()->withPersonalTeam()->create());
    session()->forget('auth.password_confirmed_at');

    Livewire::test(DeleteAccount::class)
        ->callAction('deleteAccount', ['confirm_email' => $user->email, 'password' => 'password'])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    expect($user->refresh()->scheduled_deletion_at)->not->toBeNull();
});

test('wrong password is rejected', function (): void {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());
    session()->forget('auth.password_confirmed_at');

    Livewire::test(DeleteAccount::class)
        ->callAction('deleteAccount', ['confirm_email' => $user->email, 'password' => 'wrong-password'])
        ->assertHasActionErrors(['password']);

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});

test('social user deletes without confirmation', function (): void {
    Notification::fake();

    $this->actingAs($user = User::factory()->withPersonalTeam()->socialOnly()->create());

    Livewire::test(DeleteAccount::class)
        ->call('deleteAccount')
        ->assertRedirect();

    expect($user->refresh()->scheduled_deletion_at)->not->toBeNull();
});

test('user cannot schedule deletion when owning team with members', function (): void {
    Notification::fake();

    $this->actingAs($user = User::factory()->withTeam()->create());
    session()->put('auth.password_confirmed_at', time());

    $team = $user->currentTeam;
    $team->users()->attach(User::factory()->create(), ['role' => 'editor']);

    Livewire::test(DeleteAccount::class)
        ->call('deleteAccount')
        ->assertHasNoErrors()
        ->assertNotified(__('profile.notifications.delete_account_blocked.title'));

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
    Notification::assertNothingSentTo($user);
});

test('delete account component renders correctly', function (): void {
    $this->actingAs(User::factory()->withPersonalTeam()->create());

    Livewire::test(DeleteAccount::class)
        ->assertSuccessful()
        ->assertSee('Delete Account');
});

test('the delete modal copy does not instruct users to enter a password', function (): void {
    $this->actingAs(User::factory()->withPersonalTeam()->create());

    $description = Livewire::test(DeleteAccount::class)
        ->instance()
        ->deleteAccountAction()
        ->getModalDescription();

    expect(mb_strtolower((string) $description))->not->toContain('password');
});

test('deletion is blocked until the account email is typed', function (): void {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());
    session()->put('auth.password_confirmed_at', time());

    Livewire::test(DeleteAccount::class)
        ->callAction('deleteAccount', ['confirm_email' => 'wrong@example.com'])
        ->assertHasActionErrors(['confirm_email']);

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});

test('typed email confirmation is case-insensitive and trimmed', function (): void {
    Notification::fake();
    $this->actingAs($user = User::factory()->withPersonalTeam()->create(['email' => 'owner@example.com']));
    session()->forget('auth.password_confirmed_at');

    Livewire::test(DeleteAccount::class)
        ->callAction('deleteAccount', ['confirm_email' => '  OWNER@Example.com  ', 'password' => 'password'])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    expect($user->refresh()->scheduled_deletion_at)->not->toBeNull();
});

test('the password fallback is rate limited after repeated failures', function (): void {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());
    session()->forget('auth.password_confirmed_at');

    foreach (range(1, 5) as $ignored) {
        Livewire::test(DeleteAccount::class)
            ->callAction('deleteAccount', ['confirm_email' => $user->email, 'password' => 'wrong-password'])
            ->assertHasActionErrors(['password']);
    }

    Livewire::test(DeleteAccount::class)
        ->callAction('deleteAccount', ['confirm_email' => $user->email, 'password' => 'password'])
        ->assertHasActionErrors(['password']); // throttled even though the password is now correct

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});
