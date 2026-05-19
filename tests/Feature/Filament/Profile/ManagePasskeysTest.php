<?php

declare(strict_types=1);

use App\Livewire\App\Profile\ManagePasskeys;
use App\Models\User;
use Laravel\Passkeys\Passkey;

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

it('deletes a passkey owned by the user', function (): void {
    $passkey = Passkey::create([
        'user_id' => $this->user->id,
        'name' => 'Delete Me',
        'credential_id' => 'cred-del-'.uniqid(),
        'credential' => [],
    ]);

    livewire(ManagePasskeys::class)
        ->call('deletePasskey', $passkey->id)
        ->assertHasNoErrors();

    expect(Passkey::find($passkey->id))->toBeNull();
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
        ->call('deletePasskey', $passkey->id);

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

it('renders the add passkey button text', function (): void {
    livewire(ManagePasskeys::class)
        ->assertSee('Add passkey');
});
