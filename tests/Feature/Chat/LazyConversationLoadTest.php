<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\App\Chat\ChatSidePanel;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

it('does not assemble suggested prompts when panel is closed', function (): void {
    $component = Livewire::test(ChatSidePanel::class)
        ->set('suggestedPrompts', [])
        ->set('isOpen', false)
        ->call('refreshContext');

    expect($component->get('suggestedPrompts'))->toBe([]);
});

it('assembles suggested prompts when panel is open', function (): void {
    $component = Livewire::test(ChatSidePanel::class)
        ->set('suggestedPrompts', [])
        ->set('isOpen', true)
        ->call('refreshContext');

    expect($component->get('suggestedPrompts'))->not->toBeEmpty();
});
