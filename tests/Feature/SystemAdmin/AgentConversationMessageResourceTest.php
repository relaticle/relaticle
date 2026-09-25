<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\MessageOrigin;
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
    $user = User::factory()->withPersonalWorkspace()->create();
    $conversationId = (string) Str::uuid7();
    $messageId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => 'msg test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => $messageId,
        'conversation_id' => $conversationId,
        'agent' => 'crm-assistant',
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'role' => $role,
        'content' => 'hello from the probe',
        'attachments' => '[]',
        'steps' => '[]',
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

it('labels a synthetic user row by its origin in the list and on the view page', function (): void {
    $greeting = seedAdminMessage('user');
    DB::table('agent_conversation_messages')->where('id', $greeting->getKey())->update(['origin' => MessageOrigin::Greeting->value]);
    $greeting->refresh();

    livewire(ListAgentConversationMessages::class)
        ->assertCanSeeTableRecords([$greeting])
        ->assertTableColumnExists('origin')
        ->assertTableColumnFormattedStateSet('origin', MessageOrigin::Greeting->getLabel(), $greeting);

    livewire(ViewAgentConversationMessage::class, ['record' => $greeting->getKey()])
        ->assertSee(MessageOrigin::Greeting->getLabel());
});

it('shows an origin only on user rows', function (): void {
    $user = seedAdminMessage('user');
    $assistant = seedAdminMessage('assistant');
    $tool = seedAdminMessage('tool');

    livewire(ListAgentConversationMessages::class)
        ->assertTableColumnStateSet('origin', MessageOrigin::Typed, $user)
        ->assertTableColumnStateSet('origin', null, $assistant)
        ->assertTableColumnStateSet('origin', null, $tool);

    livewire(ViewAgentConversationMessage::class, ['record' => $assistant->getKey()])
        ->assertDontSee(MessageOrigin::Typed->getLabel());
});
