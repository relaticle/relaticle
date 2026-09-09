<?php

declare(strict_types=1);

use App\Actions\Passkeys\DeletePasskey;
use App\Enums\SocialiteProvider;
use App\Features\SocialAuth;
use App\Filament\Actions\ConfirmIdentityAction;
use App\Livewire\App\Profile\ManagePasskeys;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\Auth\AuthenticationSession;
use App\Support\Auth\IdentityConfirmation;
use Database\Factories\UserFactory;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkey;
use Laravel\Pennant\Feature;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PragmaRX\Google2FA\Google2FA;

mutates(DeletePasskey::class, ManagePasskeys::class, ConfirmIdentityAction::class, IdentityConfirmation::class, AuthenticationSession::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session()->forget('auth.password_confirmed_at');
});

function createPasskey(User $user, string $name = 'Key'): Passkey
{
    return Passkey::create([
        'user_id' => $user->id,
        'name' => $name,
        'credential_id' => 'cred-'.uniqid(),
        'credential' => [],
    ]);
}

it('shows empty state when user has no passkeys', function (): void {
    livewire(ManagePasskeys::class)
        ->assertSee('No passkeys yet. Add one to sign in without a password.');
});

it('lists user passkeys with name', function (): void {
    createPasskey($this->user, 'My MacBook');

    livewire(ManagePasskeys::class)
        ->assertSee('My MacBook');
});

it('does not show passkeys belonging to other users', function (): void {
    createPasskey(User::factory()->create(), 'Other Device');

    livewire(ManagePasskeys::class)
        ->assertDontSee('Other Device');
});

it('refreshes the list after loadPasskeys is called', function (): void {
    livewire(ManagePasskeys::class)
        ->assertDontSee('Freshly Added')
        ->tap(fn () => createPasskey($this->user, 'Freshly Added'))
        ->call('loadPasskeys')
        ->assertSee('Freshly Added');
});

it('renders the add passkey button text', function (): void {
    livewire(ManagePasskeys::class)
        ->assertSee('Add passkey');
});

it('renders the Passkeys section heading and description', function (): void {
    livewire(ManagePasskeys::class)
        ->assertSee('Passkeys')
        ->assertSee('Manage your passkeys for passwordless sign-in.');
});

it('reports passkey ownership via hasPasskey', function (): void {
    expect($this->user->hasPasskey())->toBeFalse();

    createPasskey($this->user);

    expect($this->user->refresh()->hasPasskey())->toBeTrue();
});

// --- Registration ---------------------------------------------------------

it('confirms with a password and triggers the register ceremony for a password user', function (): void {
    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['password' => 'password'])
        ->assertHasNoActionErrors()
        ->assertActionHalted()
        ->assertDispatched('passkey-register')
        ->assertNotDispatched('confirm-identity-ceremony');

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

it('rejects registration when the password is wrong', function (): void {
    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['password' => 'wrong-password'])
        ->assertHasActionErrors(['password'])
        ->assertNotDispatched('passkey-register');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('requires a password for a password user registering their first passkey', function (): void {
    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey')
        ->assertHasActionErrors(['password'])
        ->assertNotDispatched('confirm-identity-ceremony')
        ->assertNotDispatched('passkey-register');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('requires fresh proof before a passwordless session can register a passkey', function (): void {
    $this->actingAs(User::factory()->create(['password' => null]));

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey')
        ->assertNotDispatched('passkey-register');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('offers a linked provider for fresh proof without setting a confirmation timestamp', function (): void {
    $user = User::factory()->create(['password' => null]);
    $this->actingAs($user);

    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
    ]);

    livewire(ManagePasskeys::class)
        ->mountAction('registerPasskey')
        ->assertMountedActionModalSee(__('auth.confirm.continue_with_provider', ['provider' => 'Google']));

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('resumes first passkey registration after returning from the linked provider', function (): void {
    $user = User::factory()->withTeam()->socialOnly()->create();
    $this->actingAs($user);
    $account = UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
    ]);

    livewire(ManagePasskeys::class)->mountAction('registerPasskey');
    $grantId = AuthenticationSession::pendingOperation()['id'];
    $this->get(route('auth.socialite.confirm.redirect', ['provider' => 'google']))
        ->assertRedirect();
    Socialite::fake('google', (new SocialiteUser)->map([
        'id' => $account->provider_id,
        'name' => $user->name,
        'email' => $user->email,
    ]));
    $this->get(route('auth.socialite.confirm.callback', ['provider' => 'google', 'code' => 'accepted']))
        ->assertRedirect();

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey')
        ->assertHasNoActionErrors()
        ->assertDispatched('passkey-register');

    expect(AuthenticationSession::pendingOperation()['id'])->toBe($grantId);
});

it('does not reuse a provider confirmation for another operation', function (): void {
    $user = User::factory()->socialOnly()->create();
    $this->actingAs($user);
    AuthenticationSession::startOperation($user, 'set_password', null);
    AuthenticationSession::proveOperation($user, 'set_password', null);

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey')
        ->assertNotDispatched('passkey-register');
});

it('does not reuse an expired provider confirmation', function (): void {
    $user = User::factory()->socialOnly()->create();
    $this->actingAs($user);
    AuthenticationSession::startOperation($user, 'add_passkey', null);
    AuthenticationSession::proveOperation($user, 'add_passkey', null);
    $this->travel(16)->minutes();

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey')
        ->assertNotDispatched('passkey-register');
});

it('runs the confirmation ceremony when a password user with a passkey adds another', function (): void {
    createPasskey($this->user, 'Existing Key');

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey')
        ->assertHasNoActionErrors()
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony')
        ->assertNotDispatched('passkey-register');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('registers via the password fallback when the user opts into password confirmation', function (): void {
    createPasskey($this->user, 'Existing Key');

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['use_password' => true, 'password' => 'password'])
        ->assertHasNoActionErrors()
        ->assertActionHalted()
        ->assertDispatched('passkey-register')
        ->assertNotDispatched('confirm-identity-ceremony');

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

it('rejects the registration password fallback when the password is wrong', function (): void {
    createPasskey($this->user, 'Existing Key');

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['use_password' => true, 'password' => 'wrong-password'])
        ->assertHasActionErrors(['password'])
        ->assertNotDispatched('passkey-register')
        ->assertNotDispatched('confirm-identity-ceremony');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

// --- Deletion -------------------------------------------------------------

it('deletes a passkey via the password fallback when the password is correct', function (): void {
    $passkey = createPasskey($this->user, 'Delete Me');

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', data: ['use_password' => true, 'password' => 'password'], arguments: ['passkeyId' => $passkey->id])
        ->assertHasNoActionErrors();

    expect(Passkey::find($passkey->id))->toBeNull();
});

it('rejects the delete password fallback when the password is wrong', function (): void {
    $passkey = createPasskey($this->user, 'Keep Me');

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', data: ['use_password' => true, 'password' => 'wrong-password'], arguments: ['passkeyId' => $passkey->id])
        ->assertHasActionErrors(['password']);

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('runs the confirmation ceremony when deleting without a password', function (): void {
    $passkey = createPasskey($this->user, 'Ceremony Delete');

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', arguments: ['passkeyId' => $passkey->id])
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony');

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('re-confirms a deletion despite a prior fresh confirmation', function (): void {
    session()->put('auth.password_confirmed_at', time() - 60);
    $passkey = createPasskey($this->user, 'Confirmed Delete');

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', arguments: ['passkeyId' => $passkey->id])
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony');

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('does not delete a passkey belonging to another user even with a fresh confirmation', function (): void {
    session()->put('auth.password_confirmed_at', time());
    createPasskey($this->user, 'My Own Key');
    $foreign = createPasskey(User::factory()->create(), 'Not Yours');

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', arguments: ['passkeyId' => $foreign->id])
        ->assertHasNoActionErrors();

    expect(Passkey::find($foreign->id))->not->toBeNull();
});

it('requires a ceremony for a passwordless user deleting a passkey', function (): void {
    $passwordless = User::factory()->create(['password' => null]);
    $this->actingAs($passwordless);
    $passkey = createPasskey($passwordless, 'Social Delete');

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', arguments: ['passkeyId' => $passkey->id])
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony');

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('re-confirms when adding a passkey even inside the freshness window', function (): void {
    session()->put('auth.password_confirmed_at', time() - 60);
    createPasskey($this->user, 'Existing Key');

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey')
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony')
        ->assertNotDispatched('passkey-register');
});

it('re-confirms when removing a passkey even inside the freshness window', function (): void {
    session()->put('auth.password_confirmed_at', time() - 60);
    $passkey = createPasskey($this->user, 'Existing Key');

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', arguments: ['passkeyId' => $passkey->id])
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony');

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('completes the deletion once the ceremony has already satisfied this attempt', function (): void {
    $user = User::factory()->create(['password' => null]);
    $this->actingAs($user);
    $passkey = createPasskey($user, 'Existing Key');
    $backup = createPasskey($user, 'Backup Key');

    $component = livewire(ManagePasskeys::class)
        ->mountAction('deletePasskey', arguments: ['passkeyId' => $passkey->id]);

    AuthenticationSession::proveOperation($user, 'delete_passkey', (string) $passkey->id);
    IdentityConfirmation::markConfirmed();

    $component->callMountedAction()
        ->assertNotDispatched('confirm-identity-ceremony');

    expect(Passkey::find($passkey->id))->toBeNull();
    $this->assertModelExists($backup);
});

it('keeps the last primary method after passkey confirmation', function (): void {
    $user = User::factory()->create(['password' => null]);
    $this->actingAs($user);
    $passkey = createPasskey($user, 'Only Key');
    $component = livewire(ManagePasskeys::class)
        ->mountAction('deletePasskey', arguments: ['passkeyId' => $passkey->id]);
    AuthenticationSession::proveOperation($user, 'delete_passkey', (string) $passkey->id);
    IdentityConfirmation::markConfirmed();

    $component->callMountedAction()->assertNotified(__('auth.link.last_method'));

    $this->assertModelExists($passkey);
});

it('returns 422 when direct passkey deletion would remove the last primary method', function (bool $mfa): void {
    $user = User::factory()->when($mfa, fn (UserFactory $factory): UserFactory => $factory->withConfirmedMfa())->create(['password' => null]);
    $this->actingAs($user);
    $passkey = createPasskey($user, 'Only Key');
    AuthenticationSession::markComplete($user);
    AuthenticationSession::startOperation($user, 'delete_passkey', (string) $passkey->id);
    AuthenticationSession::proveOperation($user, 'delete_passkey', (string) $passkey->id);
    IdentityConfirmation::markConfirmed();

    $this->deleteJson(route('passkey.destroy', ['passkey' => $passkey->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('identity');

    $this->assertModelExists($passkey);
})->with(['without MFA' => false, 'MFA is not a primary method' => true]);

it('can remove its only passkey when a linked provider remains available', function (): void {
    $user = User::factory()->socialOnly()->create();
    $this->actingAs($user);
    UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider_name' => SocialiteProvider::GOOGLE->value]);
    $passkey = createPasskey($user, 'Only Key');
    AuthenticationSession::startOperation($user, 'delete_passkey', (string) $passkey->id);
    AuthenticationSession::proveOperation($user, 'delete_passkey', (string) $passkey->id);
    IdentityConfirmation::markConfirmed();

    $this->deleteJson(route('passkey.destroy', ['passkey' => $passkey->id]))->assertOk();

    $this->assertModelMissing($passkey);
});

it('does not count a disabled provider as another primary method', function (): void {
    $user = User::factory()->socialOnly()->create();
    $this->actingAs($user);
    Feature::deactivate(SocialAuth::class);
    UserSocialAccount::factory()->create(['user_id' => $user->id, 'provider_name' => SocialiteProvider::GOOGLE->value]);
    $passkey = createPasskey($user, 'Only Key');
    AuthenticationSession::startOperation($user, 'delete_passkey', (string) $passkey->id);
    AuthenticationSession::proveOperation($user, 'delete_passkey', (string) $passkey->id);
    IdentityConfirmation::markConfirmed();

    $this->deleteJson(route('passkey.destroy', ['passkey' => $passkey->id]))->assertUnprocessable();

    $this->assertModelExists($passkey);
});

it('returns 403 for another accounts passkey even with a matching proven grant', function (): void {
    $passkey = createPasskey(User::factory()->create(), 'Other Account Key');
    AuthenticationSession::startOperation($this->user, 'delete_passkey', (string) $passkey->id);
    AuthenticationSession::proveOperation($this->user, 'delete_passkey', (string) $passkey->id);
    IdentityConfirmation::markConfirmed();

    $this->deleteJson(route('passkey.destroy', ['passkey' => $passkey->id]))->assertForbidden();

    $this->assertModelExists($passkey);
});

it('ignores a client-supplied attempt id when adding a passkey', function (): void {
    $this->actingAs(User::factory()->create(['password' => null]));
    createPasskey(auth()->user(), 'Existing Key');

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', data: ['identity_attempt_id' => 'forged-attempt-id'])
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony')
        ->assertNotDispatched('passkey-register');

    expect(IdentityConfirmation::confirmedRecently())->toBeFalse();
});

it('ignores a client-supplied attempt id when removing a passkey', function (): void {
    $this->actingAs(User::factory()->create(['password' => null]));
    $passkey = createPasskey(auth()->user(), 'Existing Key');

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', data: ['identity_attempt_id' => 'forged-attempt-id'], arguments: ['passkeyId' => $passkey->id])
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony');

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('does not let a forged attempt id fall through to an unrelated fresh generic confirmation when deleting a passkey', function (): void {
    $this->actingAs(User::factory()->create(['password' => null]));
    $passkey = createPasskey(auth()->user(), 'Existing Key');

    IdentityConfirmation::markConfirmed();

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', data: ['identity_attempt_id' => 'forged-attempt-id'], arguments: ['passkeyId' => $passkey->id])
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony');

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('does not let a forged attempt id fall through to an unrelated fresh generic confirmation when adding a passkey', function (): void {
    $this->actingAs(User::factory()->create(['password' => null]));
    createPasskey(auth()->user(), 'Existing Key');

    IdentityConfirmation::markConfirmed();

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', data: ['identity_attempt_id' => 'forged-attempt-id'])
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony')
        ->assertNotDispatched('passkey-register');
});

it('does not let an expired attempt id fall through to an unrelated fresh generic confirmation', function (): void {
    $user = User::factory()->create(['password' => null]);
    $this->actingAs($user);
    $passkey = createPasskey($user, 'Existing Key');

    $component = livewire(ManagePasskeys::class)
        ->mountAction('deletePasskey', arguments: ['passkeyId' => $passkey->id]);

    $attemptId = $component->get('mountedActions.0.data.identity_attempt_id');

    session()->forget('auth.operation');
    IdentityConfirmation::markConfirmed();

    $component->set('mountedActions.0.data.identity_attempt_id', $attemptId)
        ->callMountedAction()
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony');

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

// --- MFA --------------------------------------------------------------------

it('requires an MFA code before a password confirmation completes for an enrolled user', function (): void {
    $this->actingAs(User::factory()->withConfirmedMfa()->create());

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['password' => 'password'])
        ->assertHasActionErrors(['code'])
        ->assertNotDispatched('passkey-register');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('rejects an incorrect MFA code even with the correct password', function (): void {
    $this->actingAs(User::factory()->withConfirmedMfa()->create());

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['password' => 'password', 'code' => 'invalid'])
        ->assertHasActionErrors(['code'])
        ->assertNotDispatched('passkey-register');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('completes registration once the correct MFA code accompanies the password', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();
    $this->actingAs($user);

    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $code = resolve(Google2FA::class)->getCurrentOtp($secret);

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['password' => 'password', 'code' => $code])
        ->assertHasNoActionErrors()
        ->assertDispatched('passkey-register');

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

// --- Naming ---------------------------------------------------------------

it('registers without asking for a name', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['password' => 'password'])
        ->assertHasNoActionErrors()
        ->assertDispatched('passkey-register');
});

it('renames a passkey', function (): void {
    $passkey = createPasskey($this->user, 'Chrome on macOS');

    livewire(ManagePasskeys::class)
        ->callAction('renamePasskey', data: ['name' => 'Work laptop'], arguments: ['passkeyId' => $passkey->id])
        ->assertHasNoActionErrors();

    expect($passkey->refresh()->name)->toBe('Work laptop');
});

it('prefills the rename form with the current name', function (): void {
    $passkey = createPasskey($this->user, 'Chrome on macOS');

    livewire(ManagePasskeys::class)
        ->mountAction('renamePasskey', arguments: ['passkeyId' => $passkey->id])
        ->assertSet('mountedActions.0.data.name', 'Chrome on macOS');
});

it('requires a name when renaming', function (): void {
    $passkey = createPasskey($this->user, 'Chrome on macOS');

    livewire(ManagePasskeys::class)
        ->callAction('renamePasskey', data: ['name' => ''], arguments: ['passkeyId' => $passkey->id])
        ->assertHasActionErrors(['name']);

    expect($passkey->refresh()->name)->toBe('Chrome on macOS');
});

it('does not rename a passkey belonging to another user', function (): void {
    $passkey = createPasskey(User::factory()->create(), 'Not Mine');

    livewire(ManagePasskeys::class)
        ->callAction('renamePasskey', data: ['name' => 'Hijacked'], arguments: ['passkeyId' => $passkey->id]);

    expect($passkey->refresh()->name)->toBe('Not Mine');
});

it('rejects a direct delete-passkey request when only the generic window is confirmed, never a delete_passkey grant', function (): void {
    $passkey = createPasskey($this->user, 'Direct Route Target');

    $this->postJson(route('password.confirm.store'), ['password' => 'password'])
        ->assertNoContent();

    $this->deleteJson(route('passkey.destroy', ['passkey' => $passkey->id]))
        ->assertUnprocessable();

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('allows a direct delete-passkey request once a matching delete_passkey grant is proven', function (): void {
    $passkey = createPasskey($this->user, 'Direct Route Target');

    AuthenticationSession::startOperation($this->user, 'delete_passkey', (string) $passkey->id);
    AuthenticationSession::proveOperation($this->user, 'delete_passkey', (string) $passkey->id);
    IdentityConfirmation::markConfirmed();

    $this->deleteJson(route('passkey.destroy', ['passkey' => $passkey->id]))
        ->assertOk();

    expect(Passkey::find($passkey->id))->toBeNull();
});

it('rejects a direct delete-passkey request when the proven grant targets a different passkey', function (): void {
    $target = createPasskey($this->user, 'Not The Target');
    $decoy = createPasskey($this->user, 'Proven For This One');

    AuthenticationSession::startOperation($this->user, 'delete_passkey', (string) $decoy->id);
    AuthenticationSession::proveOperation($this->user, 'delete_passkey', (string) $decoy->id);
    IdentityConfirmation::markConfirmed();

    $this->deleteJson(route('passkey.destroy', ['passkey' => $target->id]))
        ->assertUnprocessable();

    expect(Passkey::find($target->id))->not->toBeNull();
});

it('spends a delete_passkey grant on first use, so a replayed request against a different passkey fails', function (): void {
    $first = createPasskey($this->user, 'First');
    $second = createPasskey($this->user, 'Second');

    AuthenticationSession::startOperation($this->user, 'delete_passkey', (string) $first->id);
    AuthenticationSession::proveOperation($this->user, 'delete_passkey', (string) $first->id);
    IdentityConfirmation::markConfirmed();

    $this->deleteJson(route('passkey.destroy', ['passkey' => $first->id]))->assertOk();

    $this->deleteJson(route('passkey.destroy', ['passkey' => $second->id]))
        ->assertUnprocessable();

    expect(Passkey::find($second->id))->not->toBeNull();
});

it('rejects a direct add-passkey request when only the generic window is confirmed, never an add_passkey grant', function (): void {
    $this->postJson(route('password.confirm.store'), ['password' => 'password'])
        ->assertNoContent();

    $this->postJson(route('passkey.store'), ['name' => 'Attacker Device'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('identity');

    expect($this->user->passkeys()->count())->toBe(0);
});
