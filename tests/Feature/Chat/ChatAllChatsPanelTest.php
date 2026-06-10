<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\App\Chat\ChatAllChatsPanel;
use Tests\Helpers\ChatDocument;

mutates(ChatAllChatsPanel::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

it('mounts closed by default', function (): void {
    $component = Livewire::test(ChatAllChatsPanel::class);

    expect($component->get('isOpen'))->toBeFalse();
    expect($component->get('search'))->toBe('');
});

it('opens when chat:open-all-chats event is received', function (): void {
    Livewire::test(ChatAllChatsPanel::class)
        ->dispatch('chat:open-all-chats')
        ->assertSet('isOpen', true);
});

it('closes when chat:close-all-chats event is received', function (): void {
    Livewire::test(ChatAllChatsPanel::class)
        ->set('isOpen', true)
        ->set('search', 'acme')
        ->dispatch('chat:close-all-chats')
        ->assertSet('isOpen', false)
        ->assertSet('search', '');
});

it('deletes a conversation via livewire action', function (): void {
    DB::table('agent_conversations')->insert([
        'id' => 'cap-del',
        'user_id' => $this->user->getKey(),
        'team_id' => $this->user->current_team_id,
        'title' => 'Delete me',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ChatAllChatsPanel::class)
        ->call('deleteConversation', 'cap-del')
        ->assertDispatched('chat:conversation-deleted');

    expect(DB::table('agent_conversations')->where('id', 'cap-del')->exists())->toBeFalse();
});

it('lists up to 50 conversations newest first when search is empty', function (): void {
    for ($i = 1; $i <= 60; $i++) {
        DB::table('agent_conversations')->insert([
            'id' => "c{$i}",
            'user_id' => $this->user->getKey(),
            'team_id' => $this->user->current_team_id,
            'title' => "Chat {$i}",
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    $component = Livewire::test(ChatAllChatsPanel::class);
    $conversations = $component->viewData('conversations');

    expect($conversations)->toHaveCount(50);
    expect($conversations->first()->title)->toBe('Chat 1');
    expect($conversations->last()->title)->toBe('Chat 50');
});

it('filters conversations by title when search is set', function (): void {
    DB::table('agent_conversations')->insert([
        ['id' => 's1', 'user_id' => $this->user->getKey(), 'team_id' => $this->user->current_team_id, 'title' => 'About Acme Corp', 'created_at' => now()->subMinutes(1), 'updated_at' => now()->subMinutes(1)],
        ['id' => 's2', 'user_id' => $this->user->getKey(), 'team_id' => $this->user->current_team_id, 'title' => 'Generic title', 'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)],
        ['id' => 's3', 'user_id' => $this->user->getKey(), 'team_id' => $this->user->current_team_id, 'title' => 'Pipeline review', 'created_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3)],
    ]);

    $component = Livewire::test(ChatAllChatsPanel::class)->set('search', 'Acme');
    $conversations = $component->viewData('conversations');

    expect($conversations)->toHaveCount(1);
    expect($conversations->first()->title)->toBe('About Acme Corp');
});

it('falls back to the full list when search is whitespace', function (): void {
    DB::table('agent_conversations')->insert([
        ['id' => 'w1', 'user_id' => $this->user->getKey(), 'team_id' => $this->user->current_team_id, 'title' => 'First', 'created_at' => now()->subMinutes(1), 'updated_at' => now()->subMinutes(1)],
        ['id' => 'w2', 'user_id' => $this->user->getKey(), 'team_id' => $this->user->current_team_id, 'title' => 'Second', 'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)],
    ]);

    $component = Livewire::test(ChatAllChatsPanel::class)->set('search', '   ');

    expect($component->viewData('conversations'))->toHaveCount(2);
});

it('matches by message content via SearchConversations', function (): void {
    DB::table('agent_conversations')->insert([
        'id' => 'm1',
        'user_id' => $this->user->getKey(),
        'team_id' => $this->user->current_team_id,
        'title' => 'Generic',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => 'msg1',
        'conversation_id' => 'm1',
        'user_id' => $this->user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'user',
        'content' => 'Find me companies in Berlin please',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(ChatAllChatsPanel::class)->set('search', 'Berlin');

    expect($component->viewData('conversations')->pluck('id')->all())->toBe(['m1']);
});

it('renders the search input bound to the search property', function (): void {
    Livewire::test(ChatAllChatsPanel::class)
        ->set('isOpen', true)
        ->assertSeeHtml('wire:model.live.debounce.250ms="search"')
        ->assertSeeHtml('placeholder="Search chats..."');
});

it('renders a no-matches message when search returns empty', function (): void {
    DB::table('agent_conversations')->insert([
        'id' => 'e1',
        'user_id' => $this->user->getKey(),
        'team_id' => $this->user->current_team_id,
        'title' => 'Hello world',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(ChatAllChatsPanel::class)
        ->set('isOpen', true)
        ->set('search', 'doesnotmatchanything')
        ->assertSee('No matches');
});

it('registers the all-chats panel render hook view', function (): void {
    expect(view()->exists('chat::filament.app.chat-all-chats-panel-hook'))->toBeTrue();
});
