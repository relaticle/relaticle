<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\Chat\Livewire\App\Chat\ChatAllChatsPanel;

mutates(ChatAllChatsPanel::class);

/**
 * Extra Email Integration nav items push this trigger under the sticky footer.
 * Playwright's hit-tested click then times out; scroll the nav and click in JS.
 */
function openAllChatsFromSidebar(AwaitableWebpage $page): void
{
    $page->script(<<<'JS'
        (() => {
            window.Alpine?.store('sidebar')?.open();
            const btn = document.querySelector('button[aria-label="Open all chats"]');
            if (! btn) {
                throw new Error('Open all chats trigger is not in the DOM.');
            }
            btn.closest('.fi-sidebar-nav')?.scrollTo({ top: btn.offsetTop });
            btn.click();
            return true;
        })();
    JS);

    $page->wait(0.5);
}

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

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->resize(1920, 1080)
        ->assertVisible('button[aria-label="Open all chats"]')
        ->assertSourceHas('aria-label="Open all chats"');

    openAllChatsFromSidebar($page);

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

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->resize(1920, 1080)
        ->assertVisible('button[aria-label="Open all chats"]');

    openAllChatsFromSidebar($page);

    $page->click('[data-chat-all-chats-panel] a[href*="cnav1"]')
        ->assertPathIs("/app/{$team->slug}/chats/cnav1");
});
