<?php

declare(strict_types=1);

use App\Livewire\App\Profile\ManagePasskeys;
use App\Models\User;
use Laravel\Passkeys\Passkey;

mutates(ManagePasskeys::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('shows empty state when user has no passkeys', function (): void {
    livewire(ManagePasskeys::class)
        ->assertSee('No passkeys yet. Add one to sign in without a password.');
});

it('lists user passkeys with name', function (): void {
    Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'My MacBook',
        'credential_id' => 'cred-list-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->assertSee('My MacBook');
});

it('does not show passkeys belonging to other users', function (): void {
    $other = User::factory()->create();
    Passkey::create([
        'user_id' => $other->id,
        'name' => 'Other Device',
        'credential_id' => 'cred-other-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->assertDontSee('Other Device');
});

it('deletes a passkey owned by the user when password is correct', function (): void {
    $passkey = Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'Delete Me',
        'credential_id' => 'cred-del-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->call('deletePasskey', $passkey->id, 'password')
        ->assertHasNoErrors();

    expect(Passkey::find($passkey->id))->toBeNull();
});

it('rejects delete when password is wrong', function (): void {
    $passkey = Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'Keep Me',
        'credential_id' => 'cred-keep-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->call('deletePasskey', $passkey->id, 'wrong-password')
        ->assertHasErrors(['password']);

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('does not delete a passkey belonging to another user', function (): void {
    $other = User::factory()->create();
    $passkey = Passkey::create([
        'user_id' => $other->id,
        'name' => 'Not Yours',
        'credential_id' => 'cred-not-yours-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->call('deletePasskey', $passkey->id, 'password');

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('refreshes the list after loadPasskeys is called', function (): void {
    livewire(ManagePasskeys::class)
        ->assertDontSee('Freshly Added')
        ->tap(fn () => Passkey::create([
            'user_id' => $this->user->id,
            'name' => 'Freshly Added',
            'credential_id' => 'cred-fresh-'.uniqid(),
            'credential' => [],
        ]))
        ->call('loadPasskeys')
        ->assertSee('Freshly Added');
});

it('confirms the password and dispatches the ceremony trigger on registration', function (): void {
    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['name' => 'My MacBook', 'password' => 'password'])
        ->assertHasNoActionErrors()
        ->assertDispatched('passkey-register-confirmed');

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

it('rejects registration when the password is wrong', function (): void {
    session()->forget('auth.password_confirmed_at');

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['name' => 'My MacBook', 'password' => 'wrong-password'])
        ->assertHasActionErrors(['password'])
        ->assertNotDispatched('passkey-register-confirmed');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('confirms registration without a password for passwordless users', function (): void {
    $this->actingAs(User::factory()->create(['password' => null]));

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['name' => 'My MacBook'])
        ->assertHasNoActionErrors()
        ->assertDispatched('passkey-register-confirmed');

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
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

it('dispatches the passkey confirmation ceremony when a password user with a passkey adds another', function (): void {
    session()->forget('auth.password_confirmed_at');

    Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'Existing Key',
        'credential_id' => 'cred-existing-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['name' => 'New MacBook'])
        ->assertHasNoActionErrors()
        ->assertDispatched('passkey-confirm-then-register')
        ->assertNotDispatched('passkey-register-confirmed');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('registers via the password fallback when the user opts into password confirmation', function (): void {
    Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'Existing Key',
        'credential_id' => 'cred-existing2-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['name' => 'New MacBook', 'use_password' => true, 'password' => 'password'])
        ->assertHasNoActionErrors()
        ->assertDispatched('passkey-register-confirmed')
        ->assertNotDispatched('passkey-confirm-then-register');

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

it('rejects the password fallback when the password is wrong', function (): void {
    session()->forget('auth.password_confirmed_at');

    Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'Existing Key',
        'credential_id' => 'cred-existing3-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['name' => 'New MacBook', 'use_password' => true, 'password' => 'wrong-password'])
        ->assertHasActionErrors(['password'])
        ->assertNotDispatched('passkey-register-confirmed')
        ->assertNotDispatched('passkey-confirm-then-register');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('requires a password and never reaches the ceremony for a password user registering their first passkey', function (): void {
    session()->forget('auth.password_confirmed_at');

    livewire(ManagePasskeys::class)
        ->callAction('registerPasskey', ['name' => 'First Key'])
        ->assertHasActionErrors(['password'])
        ->assertNotDispatched('passkey-confirm-then-register')
        ->assertNotDispatched('passkey-register-confirmed');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('dispatches the passkey confirmation ceremony when deleting without a password', function (): void {
    $passkey = Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'Ceremony Delete',
        'credential_id' => 'cred-cer-del-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', arguments: ['passkeyId' => $passkey->id])
        ->assertDispatched('passkey-confirm-then-delete', passkeyId: $passkey->id);

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('deletes via the password fallback when the user opts into password confirmation', function (): void {
    $passkey = Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'Password Delete',
        'credential_id' => 'cred-pw-del-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', data: ['use_password' => true, 'password' => 'password'], arguments: ['passkeyId' => $passkey->id])
        ->assertHasNoActionErrors();

    expect(Passkey::find($passkey->id))->toBeNull();
});

it('rejects the delete password fallback when the password is wrong', function (): void {
    $passkey = Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'Wrong Password Delete',
        'credential_id' => 'cred-wrong-pw-del-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', data: ['use_password' => true, 'password' => 'wrong-password'], arguments: ['passkeyId' => $passkey->id])
        ->assertHasActionErrors(['password']);

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('deletes after a fresh passkey confirmation', function (): void {
    session()->put('auth.password_confirmed_at', time());

    $passkey = Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'Confirmed Delete',
        'credential_id' => 'cred-conf-del-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->call('deletePasskeyAfterPasskeyConfirmation', $passkey->id);

    expect(Passkey::find($passkey->id))->toBeNull();
});

it('refuses to delete after passkey confirmation when no confirmation happened', function (): void {
    session()->forget('auth.password_confirmed_at');

    $passkey = Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'Unconfirmed Delete',
        'credential_id' => 'cred-unconf-del-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->call('deletePasskeyAfterPasskeyConfirmation', $passkey->id);

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('refuses to delete after passkey confirmation when the confirmation is stale', function (): void {
    session()->put('auth.password_confirmed_at', time() - (config('auth.password_timeout') + 100));

    $passkey = Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'Stale Delete',
        'credential_id' => 'cred-stale-del-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->call('deletePasskeyAfterPasskeyConfirmation', $passkey->id);

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('refuses to delete after passkey confirmation at exactly the timeout boundary', function (): void {
    session()->put('auth.password_confirmed_at', time() - config('auth.password_timeout'));

    $passkey = Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'Boundary Delete',
        'credential_id' => 'cred-boundary-del-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->call('deletePasskeyAfterPasskeyConfirmation', $passkey->id);

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('does not delete another user passkey after passkey confirmation', function (): void {
    session()->put('auth.password_confirmed_at', time());

    $other = User::factory()->create();
    $passkey = Passkey::create([
        'user_id' => $other->id,
        'name' => 'Foreign Key',
        'credential_id' => 'cred-foreign-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->call('deletePasskeyAfterPasskeyConfirmation', $passkey->id);

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('deletes directly for passwordless users without a ceremony', function (): void {
    $passwordless = User::factory()->create(['password' => null]);
    $this->actingAs($passwordless);

    $passkey = Passkey::create([
        'user_id' => $passwordless->id,
        'name' => 'Social Delete',
        'credential_id' => 'cred-social-del-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->callAction('deletePasskey', arguments: ['passkeyId' => $passkey->id])
        ->assertNotDispatched('passkey-confirm-then-delete');

    expect(Passkey::find($passkey->id))->toBeNull();
});
