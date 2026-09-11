<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Livewire\App\Chat\ChatAllChatsPanel;

mutates(ChatAllChatsPanel::class);

it('opens the all-chats flyout from the sidebar trigger and lists chats', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $rows = [
        ['id' => 'cb1', 'participant_type' => 'user', 'participant_id' => $user->getKey(), 'team_id' => $team->getKey(), 'title' => 'Acme onboarding', 'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)],
        ['id' => 'cb2', 'participant_type' => 'user', 'participant_id' => $user->getKey(), 'team_id' => $team->getKey(), 'title' => 'Q3 pipeline review', 'created_at' => now()->subMinutes(19), 'updated_at' => now()->subMinutes(19)],
    ];
    for ($i = 3; $i <= 8; $i++) {
        $rows[] = [
            'id' => "cb{$i}",
            'participant_type' => 'user',
            'participant_id' => $user->getKey(),
            'team_id' => $team->getKey(),
            'title' => "Filler {$i}",
            'created_at' => now()->subMinutes(20 - $i),
            'updated_at' => now()->subMinutes(20 - $i),
        ];
    }
    DB::table('agent_conversations')->insert($rows);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
        ->assertSourceHas('aria-label="Open all chats"');

    $page->click('button[aria-label="Open all chats"]');

    // Wait for Livewire to process the dispatched window event and re-render
    $page->script(<<<'JS'
        (() => new Promise((resolve) => setTimeout(resolve, 500)))();
    JS);

    $page->assertSee('Acme onboarding')
        ->assertSee('Q3 pipeline review');
});

it('navigates to a chat when clicked from the panel', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $rows = [[
        'id' => 'cnav1',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Navigate to me',
        'created_at' => now(),
        'updated_at' => now(),
    ]];
    for ($i = 2; $i <= 8; $i++) {
        $rows[] = [
            'id' => "cnav{$i}",
            'participant_type' => 'user',
            'participant_id' => $user->getKey(),
            'team_id' => $team->getKey(),
            'title' => "Filler {$i}",
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ];
    }
    DB::table('agent_conversations')->insert($rows);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
        ->click('button[aria-label="Open all chats"]');

    // Wait for Livewire to process the dispatched window event and re-render
    $page->script(<<<'JS'
        (() => new Promise((resolve) => setTimeout(resolve, 500)))();
    JS);

    $page->click('[data-chat-all-chats-panel] a[href*="cnav1"]')
        ->assertPathIs("/app/{$team->slug}/chats/cnav1");
});

it('does not restore an open flyout when the browser goes back', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $rows = [];
    for ($i = 1; $i <= 8; $i++) {
        $rows[] = [
            'id' => "cback{$i}",
            'participant_type' => 'user',
            'participant_id' => $user->getKey(),
            'team_id' => $team->getKey(),
            'title' => "Filler {$i}",
            'created_at' => now()->subMinutes(20 - $i),
            'updated_at' => now()->subMinutes(20 - $i),
        ];
    }
    DB::table('agent_conversations')->insert($rows);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
        ->click('button[aria-label="Open all chats"]')
        ->wait(0.5)
        ->assertVisible('[data-chat-all-chats-panel]');

    $page->script("window.Livewire.navigate('/app/{$team->slug}/people')");

    $page->waitForText('No people')
        ->back()
        ->waitForText('Get started')
        ->wait(0.5);

    $page->assertMissing('[data-chat-all-chats-panel]');
});
