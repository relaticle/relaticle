<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Relaticle\Chat\Livewire\App\Chat\ChatSidePanel;

mutates(ChatSidePanel::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

function sidePanelConversation(User $user, string $id, string $title): void
{
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'team_id' => $user->current_team_id,
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('loads a conversation picked from the history dropdown', function (): void {
    sidePanelConversation($this->user, 'csp-open', 'Pipeline review');

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', true)
        ->call('openConversation', 'csp-open')
        ->assertSet('conversationId', 'csp-open');
});

it('drops back to a fresh transcript when a new chat is started', function (): void {
    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', true)
        ->set('conversationId', 'csp-open')
        ->call('startNewConversation')
        ->assertSet('conversationId', null);
});

it('deletes the conversation and resets the panel to a fresh transcript', function (): void {
    sidePanelConversation($this->user, 'csp-delete', 'Throwaway');

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', true)
        ->set('conversationId', 'csp-delete')
        ->call('deleteConversation', 'csp-delete')
        ->assertSet('conversationId', null)
        ->assertDispatched('chat:conversation-deleted');

    expect(DB::table('agent_conversations')->where('id', 'csp-delete')->exists())->toBeFalse();
});

it('keeps the open transcript when a different conversation is deleted', function (): void {
    sidePanelConversation($this->user, 'csp-open', 'Keep me');
    sidePanelConversation($this->user, 'csp-other', 'Delete me');

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', true)
        ->set('conversationId', 'csp-open')
        ->call('deleteConversation', 'csp-other')
        ->assertSet('conversationId', 'csp-open');

    expect(DB::table('agent_conversations')->where('id', 'csp-open')->exists())->toBeTrue();
});

it('refuses to delete a conversation belonging to another user', function (): void {
    $stranger = User::factory()->withPersonalTeam()->create();
    sidePanelConversation($stranger, 'csp-theirs', 'Not yours');

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', true)
        ->set('conversationId', 'csp-theirs')
        ->call('deleteConversation', 'csp-theirs')
        ->assertSet('conversationId', 'csp-theirs')
        ->assertNotDispatched('chat:conversation-deleted');

    expect(DB::table('agent_conversations')->where('id', 'csp-theirs')->exists())->toBeTrue();
});

it('renders on a tenant-less page, where the chat routes have no url', function (): void {
    Filament::setTenant(null);

    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', true)
        ->assertOk();
});

it('no longer renders the credit balance footer', function (): void {
    Livewire::test(ChatSidePanel::class)
        ->set('isOpen', true)
        ->assertDontSee('Upgrade to Pro')
        ->assertDontSee('Buy more credits');
});
