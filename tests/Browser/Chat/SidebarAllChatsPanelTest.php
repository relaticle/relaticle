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
        ['id' => 'cb1', 'user_id' => $user->getKey(), 'team_id' => $team->getKey(), 'title' => 'Acme onboarding', 'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)],
        ['id' => 'cb2', 'user_id' => $user->getKey(), 'team_id' => $team->getKey(), 'title' => 'Q3 pipeline review', 'created_at' => now()->subMinutes(19), 'updated_at' => now()->subMinutes(19)],
    ];
    for ($i = 3; $i <= 8; $i++) {
        $rows[] = [
            'id' => "cb{$i}",
            'user_id' => $user->getKey(),
            'team_id' => $team->getKey(),
            'title' => "Filler {$i}",
            'created_at' => now()->subMinutes(20 - $i),
            'updated_at' => now()->subMinutes(20 - $i),
        ];
    }
    DB::table('agent_conversations')->insert($rows);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}")
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
        'user_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Navigate to me',
        'created_at' => now(),
        'updated_at' => now(),
    ]];
    for ($i = 2; $i <= 8; $i++) {
        $rows[] = [
            'id' => "cnav{$i}",
            'user_id' => $user->getKey(),
            'team_id' => $team->getKey(),
            'title' => "Filler {$i}",
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ];
    }
    DB::table('agent_conversations')->insert($rows);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}")
        ->click('button[aria-label="Open all chats"]');

    // Wait for Livewire to process the dispatched window event and re-render
    $page->script(<<<'JS'
        (() => new Promise((resolve) => setTimeout(resolve, 500)))();
    JS);

    $page->click('[data-chat-all-chats-panel] a[href*="cnav1"]')
        ->assertPathIs("/app/{$team->slug}/chats/cnav1");
});
