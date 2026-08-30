<?php

declare(strict_types=1);

use App\Filament\Actions\ConfirmIdentityAction;
use App\Livewire\App\Profile\ManagePasskeys;
use App\Models\User;
use App\Support\Auth\IdentityConfirmation;
use Laravel\Passkeys\Passkey;

mutates(ManagePasskeys::class, ConfirmIdentityAction::class, IdentityConfirmation::class);

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

it('confirms registration without a password for passwordless users', function (): void {
    $this->actingAs(User::factory()->create(['password' => null]));

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey')
        ->assertHasNoActionErrors()
        ->assertActionHalted()
        ->assertDispatched('passkey-register');

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
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

it('completes the deletion when the ceremony refreshed the proof after the attempt began', function (): void {
    $this->actingAs(User::factory()->create(['password' => null]));
    $passkey = createPasskey(auth()->user(), 'Existing Key');
    session()->put('auth.password_confirmed_at', time());

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', data: ['confirm_started_at' => (string) (time() - 10)], arguments: ['passkeyId' => $passkey->id])
        ->assertNotDispatched('confirm-identity-ceremony');

    expect(Passkey::find($passkey->id))->toBeNull();
});

it('ignores a client-supplied attempt marker when adding a passkey', function (): void {
    $this->actingAs(User::factory()->create(['password' => null]));
    createPasskey(auth()->user(), 'Existing Key');

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', data: ['confirm_started_at' => '0'])
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony')
        ->assertNotDispatched('passkey-register');

    expect(IdentityConfirmation::confirmedRecently())->toBeFalse();
});

it('ignores a client-supplied attempt marker when removing a passkey', function (): void {
    $this->actingAs(User::factory()->create(['password' => null]));
    $passkey = createPasskey(auth()->user(), 'Existing Key');

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', data: ['confirm_started_at' => '0'], arguments: ['passkeyId' => $passkey->id])
        ->assertActionHalted()
        ->assertDispatched('confirm-identity-ceremony');

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

// --- Naming ---------------------------------------------------------------

it('registers without asking for a name', function (): void {
    $this->actingAs(User::factory()->create(['password' => null]));

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey')
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
