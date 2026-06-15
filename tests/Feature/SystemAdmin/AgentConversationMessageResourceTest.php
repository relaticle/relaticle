<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationMessageResource;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationMessageResource\Pages\ListAgentConversationMessages;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationMessageResource\Pages\ViewAgentConversationMessage;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(AgentConversationMessageResource::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

function seedAdminMessage(string $role = 'user', ?string $supersededAt = null): AgentConversationMessage
{
    $user = User::factory()->withPersonalTeam()->create();
    $conversationId = (string) Str::uuid7();
    $messageId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'msg test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => $messageId,
        'conversation_id' => $conversationId,
        'agent' => 'crm-assistant',
        'user_id' => (string) $user->getKey(),
        'role' => $role,
        'content' => 'hello from the probe',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'superseded_at' => $supersededAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return AgentConversationMessage::query()->findOrFail($messageId);
}

it('lists messages across all tenants with role and content', function (): void {
    $userMsg = seedAdminMessage('user');
    $assistantMsg = seedAdminMessage('assistant');

    livewire(ListAgentConversationMessages::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$userMsg, $assistantMsg])
        ->assertCanRenderTableColumn('role')
        ->assertCanRenderTableColumn('content');
});

it('filters messages by role', function (): void {
    $userMsg = seedAdminMessage('user');
    $assistantMsg = seedAdminMessage('assistant');

    livewire(ListAgentConversationMessages::class)
        ->filterTable('role', 'assistant')
        ->assertCanSeeTableRecords([$assistantMsg])
        ->assertCanNotSeeTableRecords([$userMsg]);
});

it('renders a tool-role message without an unhandled match', function (): void {
    $toolMsg = seedAdminMessage('tool');

    livewire(ListAgentConversationMessages::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$toolMsg]);
});

it('shows a message detail page including superseded state', function (): void {
    $message = seedAdminMessage('assistant', supersededAt: now()->toDateTimeString());

    livewire(ViewAgentConversationMessage::class, ['record' => $message->getKey()])
        ->assertSuccessful();
});
