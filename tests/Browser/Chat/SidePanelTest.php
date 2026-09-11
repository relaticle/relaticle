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

it('stays closed when the browser goes back to a page where it was closed', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $assistantName = config('chat.assistant_name');

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
        ->click('a.fi-sidebar-item-btn[href$="/people"]')
        ->waitForText('No people')
        ->click("button[aria-label=\"Ask {$assistantName}\"]")
        ->assertVisible('[data-chat-side-panel]')
        ->click('[data-chat-side-panel] button[aria-label="Close chat panel"]')
        ->wait(0.5)
        ->assertMissing('[data-chat-side-panel]')
        ->click('a.fi-sidebar-item-btn[href$="/companies"]')
        ->waitForText('No companies')
        ->back()
        ->waitForText('No people')
        ->wait(0.5);

    $page->assertMissing('[data-chat-side-panel]');
});

it('does not restore an open panel when the browser goes back', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $assistantName = config('chat.assistant_name');

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
        ->click('a.fi-sidebar-item-btn[href$="/people"]')
        ->waitForText('No people')
        ->click("button[aria-label=\"Ask {$assistantName}\"]")
        ->assertVisible('[data-chat-side-panel]');

    $page->script("window.Livewire.navigate('/app/{$team->slug}/companies')");

    $page->waitForText('No companies')
        ->back()
        ->waitForText('No people')
        ->wait(0.5);

    $page->assertMissing('[data-chat-side-panel]');
});
