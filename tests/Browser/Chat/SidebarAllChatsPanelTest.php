<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\Chat\Livewire\App\Chat\ChatAllChatsPanel;

mutates(ChatAllChatsPanel::class);

function openAllChatsFromSidebar(AwaitableWebpage $page): void
{
    $page->script(<<<'JS'
        async () => {
            window.Alpine?.store('sidebar')?.open();

            const deadline = Date.now() + 20_000;
            let dispatched = false;

            while (Date.now() < deadline) {
                const btn = document.querySelector('button[aria-label="Open all chats"]');
                if (btn && window.Livewire?.dispatch) {
                    btn.scrollIntoView({ block: 'center' });
                    window.Livewire.dispatch('chat:open-all-chats');
                    dispatched = true;
                    break;
                }
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            if (! dispatched) {
                throw new Error('Open all chats trigger or Livewire was not ready.');
            }

            while (Date.now() < deadline) {
                const panel = document.querySelector('[data-chat-all-chats-panel]');
                if (panel && getComputedStyle(panel).display !== 'none') {
                    return true;
                }
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            throw new Error('All chats panel did not open.');
        }
    JS);

    $page->assertVisible('[data-chat-all-chats-panel]');
}

it('opens the all-chats flyout from the sidebar trigger and lists chats', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $rows = [
        ['id' => 'cb1', 'participant_type' => 'user', 'participant_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(), 'title' => 'Acme onboarding', 'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)],
        ['id' => 'cb2', 'participant_type' => 'user', 'participant_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(), 'title' => 'Q3 pipeline review', 'created_at' => now()->subMinutes(19), 'updated_at' => now()->subMinutes(19)],
    ];
    for ($i = 3; $i <= 8; $i++) {
        $rows[] = [
            'id' => "cb{$i}",
            'participant_type' => 'user',
            'participant_id' => $user->getKey(),
            'workspace_id' => $workspace->getKey(),
            'title' => "Filler {$i}",
            'created_at' => now()->subMinutes(20 - $i),
            'updated_at' => now()->subMinutes(20 - $i),
        ];
    }
    DB::table('agent_conversations')->insert($rows);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->assertSourceHas('aria-label="Open all chats"');

    openAllChatsFromSidebar($page);

    $page->assertSee('Acme onboarding')
        ->assertSee('Q3 pipeline review');
});

it('navigates to a chat when clicked from the panel', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $rows = [[
        'id' => 'cnav1',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Navigate to me',
        'created_at' => now(),
        'updated_at' => now(),
    ]];
    for ($i = 2; $i <= 8; $i++) {
        $rows[] = [
            'id' => "cnav{$i}",
            'participant_type' => 'user',
            'participant_id' => $user->getKey(),
            'workspace_id' => $workspace->getKey(),
            'title' => "Filler {$i}",
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ];
    }
    DB::table('agent_conversations')->insert($rows);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}");

    openAllChatsFromSidebar($page);

    $page->click('[data-chat-all-chats-panel] a[href*="cnav1"]')
        ->assertPathIs("/app/{$workspace->slug}/chats/cnav1");
});

it('does not restore an open flyout when the browser goes back', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $rows = [];
    for ($i = 1; $i <= 8; $i++) {
        $rows[] = [
            'id' => "cback{$i}",
            'participant_type' => 'user',
            'participant_id' => $user->getKey(),
            'workspace_id' => $workspace->getKey(),
            'title' => "Filler {$i}",
            'created_at' => now()->subMinutes(20 - $i),
            'updated_at' => now()->subMinutes(20 - $i),
        ];
    }
    DB::table('agent_conversations')->insert($rows);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}");

    openAllChatsFromSidebar($page);

    $page->script("window.Livewire.navigate('/app/{$workspace->slug}/people')");

    $page->waitForText('No people')
        ->back()
        ->waitForText('Get started')
        ->wait(0.5);

    $page->assertMissing('[data-chat-all-chats-panel]');
});
