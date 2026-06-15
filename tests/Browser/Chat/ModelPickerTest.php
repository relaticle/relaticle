<?php

declare(strict_types=1);

use App\Models\User;

it('closes the model picker when the user presses Escape', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button[type="submit"]')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->click('[data-chat-context="conversation"] [aria-label="Select AI model"]')
        ->assertVisible('[data-chat-context="conversation"] [role="listbox"][aria-label="AI model options"]')
        ->keys('[data-chat-context="conversation"] [aria-label="Select AI model"]', 'Escape')
        ->assertMissing('[data-chat-context="conversation"] [role="listbox"][aria-label="AI model options"]');
});

it('reopens the model picker after Escape closes it', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button[type="submit"]')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->click('[data-chat-context="conversation"] [aria-label="Select AI model"]')
        ->keys('[data-chat-context="conversation"] [aria-label="Select AI model"]', 'Escape')
        ->click('[data-chat-context="conversation"] [aria-label="Select AI model"]')
        ->assertVisible('[data-chat-context="conversation"] [role="listbox"][aria-label="AI model options"]');
});
