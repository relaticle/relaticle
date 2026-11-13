<?php

declare(strict_types=1);

use App\Actions\User\UpdateKeyboardShortcuts as UpdateKeyboardShortcutsAction;
use App\Filament\Pages\EditProfile;
use App\Livewire\App\Profile\UpdateKeyboardShortcuts;
use App\Models\User;
use Livewire\Livewire;

mutates(UpdateKeyboardShortcuts::class, UpdateKeyboardShortcutsAction::class);

it('turns single-key shortcuts off from the profile page', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $this->get(EditProfile::getUrl(tenant: $user->currentWorkspace))
        ->assertOk()
        ->assertSee(__('profile.form.single_key_shortcuts.label'))
        ->assertSee('data-single-key-shortcuts="true"', false);

    $toggle = Livewire::test(UpdateKeyboardShortcuts::class)
        ->assertSet('data.single_key_shortcuts', true)
        ->set('data.single_key_shortcuts', false)
        ->assertNotified(__('profile.notifications.keyboard_shortcuts_saved'));

    expect($user->refresh()->single_key_shortcuts)->toBeFalse()
        ->and(collect($toggle->effects['xjs'] ?? [])->pluck('expression')->implode(';'))
        ->toContain("setAttribute('data-single-key-shortcuts', 'false')")
        ->toContain("store('shortcuts')?.readPreference()");

    $this->get(EditProfile::getUrl(tenant: $user->currentWorkspace))
        ->assertSee('data-single-key-shortcuts="false"', false);
});

it('turns single-key shortcuts back on', function (): void {
    $user = User::factory()->withWorkspace()->create(['single_key_shortcuts' => false]);
    $this->actingAs($user);

    Livewire::test(UpdateKeyboardShortcuts::class)
        ->assertSet('data.single_key_shortcuts', false)
        ->set('data.single_key_shortcuts', true);

    expect($user->refresh()->single_key_shortcuts)->toBeTrue();
});
