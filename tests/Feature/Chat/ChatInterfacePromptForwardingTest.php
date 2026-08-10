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

it('hides the starter prompt chips in the side panel', function (): void {
    Livewire::test(ChatInterface::class, [
        'context' => 'side-panel',
        'contextPrompts' => [['label' => 'Summarize Acme', 'prompt' => 'Summarize Acme']],
    ])
        ->assertSee('Ask about your CRM data.')
        ->assertDontSee('or try one of these');
});

it('keeps the starter prompt chips on the full-page chat', function (): void {
    Livewire::test(ChatInterface::class)
        ->assertSee('or try one of these');
});
