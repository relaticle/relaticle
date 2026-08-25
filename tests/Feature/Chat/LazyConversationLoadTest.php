<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\App\Chat\ChatSidePanel;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

/**
 * The panel resolves its record binding eagerly, whether or not it is open: the
 * embedded interface renders the moment the panel opens, not on the next
 * navigation, so a binding computed lazily would arrive too late.
 *
 * These used to assert on starter prompts, which were the other thing
 * refreshContext() eagerly assembled. Those are gone (an empty composer offers
 * no canned suggestions now), so the same guarantee is asserted against the
 * record binding that remains.
 */
it('resolves the record binding regardless of panel state when closed', function (): void {
    $person = People::factory()->for($this->user->currentTeam)->create(['name' => 'Manch Minasyan']);

    $component = Livewire::test(ChatSidePanel::class)
        ->set('isOpen', false)
        ->call('refreshContext', "https://consolidate-ask-relaticle.test/app/{$this->user->currentTeam->slug}/people/{$person->getKey()}");

    expect($component->get('recordType'))->toBe('people')
        ->and($component->get('recordName'))->toBe('Manch Minasyan');
});

it('resolves the record binding regardless of panel state when open', function (): void {
    $person = People::factory()->for($this->user->currentTeam)->create(['name' => 'Manch Minasyan']);

    $component = Livewire::test(ChatSidePanel::class)
        ->set('isOpen', true)
        ->call('refreshContext', "https://consolidate-ask-relaticle.test/app/{$this->user->currentTeam->slug}/people/{$person->getKey()}");

    expect($component->get('recordType'))->toBe('people')
        ->and($component->get('recordName'))->toBe('Manch Minasyan');
});
