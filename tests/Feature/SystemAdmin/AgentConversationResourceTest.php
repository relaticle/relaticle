<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationResource;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationResource\Pages\ListAgentConversations;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationResource\Pages\ViewAgentConversation;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(AgentConversationResource::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

function seedAdminConversation(string $title = 'Probe chat', int $messages = 0): AgentConversation
{
    $user = User::factory()->withPersonalTeam()->create();
    $id = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    for ($i = 0; $i < $messages; $i++) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $id,
            'agent' => 'crm-assistant',
            'user_id' => (string) $user->getKey(),
            'role' => $i % 2 === 0 ? 'user' : 'assistant',
            'content' => "message {$i}",
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return AgentConversation::query()->findOrFail($id);
}

it('lists conversations across all tenants with a message count', function (): void {
    $a = seedAdminConversation('Acme chat', messages: 3);
    $b = seedAdminConversation('Globex chat', messages: 1);

    livewire(ListAgentConversations::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$a, $b])
        ->assertCanRenderTableColumn('team.name')
        ->assertCanRenderTableColumn('messages_count');
});

it('shows a conversation detail page', function (): void {
    $conversation = seedAdminConversation(messages: 2);

    livewire(ViewAgentConversation::class, ['record' => $conversation->getKey()])
        ->assertSuccessful();
});
