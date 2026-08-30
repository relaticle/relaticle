<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Livewire\App\Chat\ChatSidePanel;

mutates(ChatSidePanel::class);

it('renders the side panel on the dashboard', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
        ->assertSourceHas('data-chat-side-panel');
});
