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

it('assembles starter prompts regardless of panel state when closed', function (): void {
    $component = Livewire::test(ChatSidePanel::class)
        ->set('starterPrompts', [])
        ->set('isOpen', false)
        ->call('refreshContext');

    expect($component->get('starterPrompts'))->not->toBeEmpty();
});

it('assembles starter prompts regardless of panel state when open', function (): void {
    $component = Livewire::test(ChatSidePanel::class)
        ->set('starterPrompts', [])
        ->set('isOpen', true)
        ->call('refreshContext');

    expect($component->get('starterPrompts'))->not->toBeEmpty();
});
