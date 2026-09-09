<?php

declare(strict_types=1);

use App\Enums\SocialiteProvider;
use App\Features\AccountDeletion;
use App\Filament\Actions\ConfirmIdentityAction;
use App\Livewire\App\Profile\DeleteAccount;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Notifications\UserDeletionScheduledNotification;
use App\Support\Auth\IdentityConfirmation;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Notification;
use Laravel\Passkeys\Passkey;
use Laravel\Pennant\Feature;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

mutates(DeleteAccount::class, User::class, ConfirmIdentityAction::class, IdentityConfirmation::class);

beforeEach(function (): void {
    Feature::define(AccountDeletion::class, true);
});

test('a direct deletion call cannot bypass the confirmation form after an unrelated identity proof', function (): void {
    Notification::fake();
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());
    session()->put('auth.password_confirmed_at', time() - 60);
    $component = Livewire::test(DeleteAccount::class);

    expect(fn (): mixed => $component->call('deleteAccount'))->toThrow(MethodNotFoundException::class);

    expect($user->refresh()->scheduled_deletion_at)->toBeNull()
        ->and($user->personalTeam()->scheduled_deletion_at)->toBeNull();
    Notification::assertNothingSent();
});

test('account deletion cannot be opened when the feature is disabled', function (): void {
    $this->actingAs(User::factory()->withPersonalTeam()->create());
    Feature::deactivate(AccountDeletion::class);

    Livewire::test(DeleteAccount::class)->assertForbidden();
});

test('an open deletion form cannot submit after the feature is disabled', function (): void {
    Notification::fake();
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());
    session()->put('auth.password_confirmed_at', time());
    $component = Livewire::test(DeleteAccount::class)
        ->mountAction('deleteAccount')
        ->setActionData(['confirm_email' => $user->email, 'password' => 'password']);
    Feature::deactivate(AccountDeletion::class);

    $component->callMountedAction()->assertForbidden();

    expect($user->refresh()->scheduled_deletion_at)->toBeNull()
        ->and($user->personalTeam()->scheduled_deletion_at)->toBeNull();
    Notification::assertNothingSent();
});

test('the legacy deletion form cannot bypass the disabled feature', function (): void {
    $this->actingAs(User::factory()->withPersonalTeam()->create());
    Feature::deactivate(AccountDeletion::class);

    Livewire::test('profile.delete-user-form')->assertForbidden();
});

test('the legacy deletion form uses the confirmed deletion schedule when enabled', function (): void {
    Notification::fake();
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    Livewire::test('profile.delete-user-form')
        ->callAction('deleteAccount', ['confirm_email' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    expect($user->refresh()->scheduled_deletion_at)->not->toBeNull();
    Notification::assertSentTo($user, UserDeletionScheduledNotification::class);
});

test('schedules deletion after confirming identity and the account email', function (): void {
    Notification::fake();

    $this->actingAs($user = User::factory()->withPersonalTeam()->create());
    session()->put('auth.password_confirmed_at', time());

    Livewire::test(DeleteAccount::class)
        ->callAction('deleteAccount', ['confirm_email' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    expect($user->refresh()->scheduled_deletion_at)->not->toBeNull();

    Notification::assertSentTo($user, UserDeletionScheduledNotification::class);
});

test('blocked without a fresh confirmation', function (): void {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());
    session()->forget('auth.password_confirmed_at');

    Livewire::test(DeleteAccount::class)
        ->callAction('deleteAccount', ['confirm_email' => $user->email])
        ->assertHasActionErrors(['password']);

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});

test('a passkey-only user (no password, no social account) can delete their account through the ceremony', function (): void {
    $user = User::factory()->withPersonalTeam()->create(['password' => null]);
    $this->actingAs($user);

    Passkey::create([
        'user_id' => $user->id,
        'name' => 'Only Key',
        'credential_id' => 'cred-'.uniqid(),
        'credential' => [],
    ]);

    $component = Livewire::test(DeleteAccount::class)
        ->callAction(TestAction::make('deleteAccount')->schemaComponent(), data: ['confirm_email' => $user->email])
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony');

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();

    IdentityConfirmation::markConfirmed();

    $component->callMountedAction()
        ->assertHasNoActionErrors();

    expect($user->refresh()->scheduled_deletion_at)->not->toBeNull();
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

test('social user without a fresh confirmation is blocked from deleting', function (): void {
    $this->actingAs($user = User::factory()->withPersonalTeam()->socialOnly()->create());

    Livewire::test(DeleteAccount::class)
        ->callAction('deleteAccount', ['confirm_email' => $user->email])
        ->assertActionHalted();

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});

test('social user completes deletion after confirming through their linked provider', function (): void {
    Notification::fake();

    $user = User::factory()->withPersonalTeam()->socialOnly()->create();
    $this->actingAs($user);

    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => 'confirmed-google-id',
    ]);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'confirmed-google-id';
    $socialiteUser->name = $user->name;
    $socialiteUser->email = $user->email;

    Socialite::fake(SocialiteProvider::GOOGLE->value, $socialiteUser);

    $this->get(route('auth.socialite.confirm.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();

    $this->travel(10)->seconds();

    livewire(DeleteAccount::class)
        ->callAction('deleteAccount', data: ['confirm_email' => $user->email])
        ->assertHasNoActionErrors();

    expect($user->refresh()->scheduled_deletion_at)->not->toBeNull();
});

test('a provider identity mismatch does not confirm a pending deletion', function (): void {
    $user = User::factory()->withPersonalTeam()->socialOnly()->create();
    $this->actingAs($user);

    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => 'the-real-linked-id',
    ]);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'a-different-id';
    $socialiteUser->name = $user->name;
    $socialiteUser->email = $user->email;

    Socialite::fake(SocialiteProvider::GOOGLE->value, $socialiteUser);

    $this->get(route('auth.socialite.confirm.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect();

    expect(session('auth.password_confirmed_at'))->toBeNull();

    Livewire::test(DeleteAccount::class)
        ->callAction('deleteAccount', ['confirm_email' => $user->email])
        ->assertActionHalted();

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});

test('user cannot schedule deletion when owning team with members', function (): void {
    Notification::fake();

    $this->actingAs($user = User::factory()->withTeam()->create());
    session()->put('auth.password_confirmed_at', time());

    $team = $user->currentTeam;
    $team->users()->attach(User::factory()->create(), ['role' => 'editor']);

    Livewire::test(DeleteAccount::class)
        ->callAction('deleteAccount', ['confirm_email' => $user->email, 'password' => 'password'])
        ->assertHasNoErrors()
        ->assertNotified(__('profile.notifications.delete_account_blocked.title'));

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
    Notification::assertNothingSentTo($user);
});

test('delete account component renders correctly', function (): void {
    $this->actingAs(User::factory()->withPersonalTeam()->create());

    Livewire::test(DeleteAccount::class)
        ->assertSuccessful()
        ->assertSee('Delete Account')
        ->assertSee('Permanently delete your account after a 30-day grace period.')
        ->assertSee('Records in shared workspaces will remain without your profile.')
        ->assertDontSee('all your data');
});

test('the confirmation modal explains what deletion keeps and removes', function () {
    $this->actingAs(User::factory()->withPersonalTeam()->create());

    Livewire::test(DeleteAccount::class)
        ->mountAction(TestAction::make('deleteAccount')->schemaComponent())
        ->assertMountedActionModalSee('Your profile and sign-in account will be deleted after 30 days.')
        ->assertMountedActionModalSee('Shared workspace records will remain.');
});

test('a social-only account sees the same deletion scope copy', function () {
    $this->actingAs(User::factory()->withPersonalTeam()->socialOnly()->create());

    Livewire::test(DeleteAccount::class)
        ->mountAction(TestAction::make('deleteAccount')->schemaComponent())
        ->assertMountedActionModalSee('Your profile and sign-in account will be deleted after 30 days.')
        ->assertMountedActionModalSee('Shared workspace records will remain.');
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
        ->assertHasActionErrors(['password']);

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});
