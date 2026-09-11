<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\App\Chat\ChatSidebarNav;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

/**
 * The three listener-registration tests this replaces asserted wiring that
 * provably did nothing: this component is nested inside Filament's sidebar, so
 * its own re-render is discarded and only the parent's paints. What has to hold
 * now is that anything changing the list asks Filament for that parent repaint.
 */
it('asks filament to repaint the sidebar when a conversation is deleted', function (): void {
    DB::table('agent_conversations')->insert([
        'id' => 'c-repaint',
        'participant_type' => 'user',
        'participant_id' => $this->user->getKey(),
        'team_id' => $this->user->current_team_id,
        'title' => 'Delete me',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ChatSidebarNav::class)
        ->call('deleteConversation', 'c-repaint')
        ->assertDispatched('refresh-sidebar');
});

it('deletes a conversation via livewire action', function (): void {
    DB::table('agent_conversations')->insert([
        'id' => 'c-del',
        'participant_type' => 'user',
        'participant_id' => $this->user->getKey(),
        'team_id' => $this->user->current_team_id,
        'title' => 'Kill me',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ChatSidebarNav::class)
        ->call('deleteConversation', 'c-del')
        ->assertDispatched('chat:conversation-deleted');

    expect(DB::table('agent_conversations')->where('id', 'c-del')->exists())->toBeFalse();
});

it('shows empty state when no conversations exist', function (): void {
    Livewire::test(ChatSidebarNav::class)
        ->assertSee('No chats yet');
});

it('renders an "All chats" trigger when more than 7 chats exist', function (): void {
    $rows = [];
    for ($i = 1; $i <= 8; $i++) {
        $rows[] = [
            'id' => "all-{$i}",
            'participant_type' => 'user',
            'participant_id' => $this->user->getKey(),
            'team_id' => $this->user->current_team_id,
            'title' => "Chat {$i}",
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ];
    }
    DB::table('agent_conversations')->insert($rows);

    Livewire::test(ChatSidebarNav::class)
        ->assertSee('All chats')
        ->assertSeeHtml("dispatchEvent(new CustomEvent('chat:open-all-chats'))");
});

it('hides the "All chats" trigger when 7 or fewer chats exist', function (): void {
    $rows = [];
    for ($i = 1; $i <= 7; $i++) {
        $rows[] = [
            'id' => "few-{$i}",
            'participant_type' => 'user',
            'participant_id' => $this->user->getKey(),
            'team_id' => $this->user->current_team_id,
            'title' => "Chat {$i}",
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ];
    }
    DB::table('agent_conversations')->insert($rows);

    Livewire::test(ChatSidebarNav::class)
        ->assertDontSee('Open all chats');
});

/**
 * The rows carry live per-row Alpine state (an open rename input, a saving
 * flag), and this list is repainted whenever a rename or a delete changes it.
 * Unkeyed, Livewire morphs the <li> elements positionally, so the element
 * holding an open rename input is reused for whatever conversation slid into
 * its index.
 */
it('gives each conversation row an identity the morph can follow', function (): void {
    $ids = ['c-key-1', 'c-key-2', 'c-key-3'];

    foreach ($ids as $index => $id) {
        DB::table('agent_conversations')->insert([
            'id' => $id,
            'participant_type' => 'user',
            'participant_id' => $this->user->getKey(),
            'team_id' => $this->user->current_team_id,
            'title' => "Chat {$index}",
            'created_at' => now()->subMinutes(10 - $index),
            'updated_at' => now()->subMinutes(10 - $index),
        ]);
    }

    $html = Livewire::test(ChatSidebarNav::class)->html();

    foreach ($ids as $id) {
        expect($html)->toContain('wire:key="conversation-'.$id.'"');
    }
});
