<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\Chat\ChatInterface;

mutates(ChatInterface::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

/**
 * `?prompt=` seeds the composer and stops. It used to feed initialMessage,
 * which sends itself on arrival, so any link from anywhere could spend a
 * workspace credit before its owner had read what was typed.
 */
it('seeds the composer from the prompt query parameter without sending it', function (): void {
    Livewire::withQueryParams(['prompt' => 'Show my overdue tasks'])
        ->test(ChatInterface::class)
        ->assertSet('initialPrompt', 'Show my overdue tasks')
        ->assertSet('initialMessage', null);
});

it('leaves an explicit initialMessage prop untouched by a prompt query', function (): void {
    Livewire::withQueryParams(['prompt' => 'from query'])
        ->test(ChatInterface::class, ['initialMessage' => 'from prop'])
        ->assertSet('initialMessage', 'from prop');
});

it('leaves both null when no prompt query and no prop', function (): void {
    Livewire::test(ChatInterface::class)
        ->assertSet('initialMessage', null)
        ->assertSet('initialPrompt', null);
});

it('ignores a blank prompt query rather than seeding whitespace', function (): void {
    Livewire::withQueryParams(['prompt' => '   '])
        ->test(ChatInterface::class)
        ->assertSet('initialPrompt', null);
});

/**
 * The composer refuses anything longer, so a crafted link cannot paste an
 * essay into somebody's editor.
 */
it('caps a prompt at the composer limit', function (): void {
    Livewire::withQueryParams(['prompt' => str_repeat('a', 6000)])
        ->test(ChatInterface::class)
        ->assertSet('initialPrompt', str_repeat('a', 5000));
});

it('keeps the side panel empty state to its record-aware greeting', function (): void {
    Livewire::test(ChatInterface::class, ['context' => 'side-panel'])
        ->assertSee('Ask about this record, or anything else in your CRM.')
        ->assertDontSee('Try one of these');
});

it('keeps the full-page empty state to its greeting, with no starter chips', function (): void {
    Livewire::test(ChatInterface::class)
        ->assertSee("Ask about a deal, a contact, or what's overdue.")
        ->assertDontSee('Try one of these');
});
