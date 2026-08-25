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

it('picks up the prompt query parameter on mount', function (): void {
    Livewire::withQueryParams(['prompt' => 'Show my overdue tasks'])
        ->test(ChatInterface::class)
        ->assertSet('initialMessage', 'Show my overdue tasks');
});

it('prefers explicit initialMessage prop over prompt query', function (): void {
    Livewire::withQueryParams(['prompt' => 'from query'])
        ->test(ChatInterface::class, ['initialMessage' => 'from prop'])
        ->assertSet('initialMessage', 'from prop');
});

it('leaves initialMessage null when no prompt query and no prop', function (): void {
    Livewire::test(ChatInterface::class)
        ->assertSet('initialMessage', null);
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
