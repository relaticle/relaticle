<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationResource;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationResource\Pages\ListAgentConversations;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationResource\Pages\ViewAgentConversation;
use Relaticle\SystemAdmin\Filament\Resources\AgentConversationResource\RelationManagers\MessagesRelationManager;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(AgentConversationResource::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

function seedAdminConversation(string $title = 'Probe chat', int $messages = 0): AgentConversation
{
    $user = User::factory()->withPersonalWorkspace()->create();
    $id = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    for ($i = 0; $i < $messages; $i++) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $id,
            'agent' => 'crm-assistant',
            'participant_type' => 'user',
            'participant_id' => (string) $user->getKey(),
            'role' => $i % 2 === 0 ? 'user' : 'assistant',
            'content' => "message {$i}",
            'attachments' => '[]',
            'steps' => '[]',
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
        ->assertCanRenderTableColumn('workspace.name')
        ->assertCanRenderTableColumn('messages_count');
});

it('shows a conversation detail page', function (): void {
    $conversation = seedAdminConversation(messages: 2);

    livewire(ViewAgentConversation::class, ['record' => $conversation->getKey()])
        ->assertSuccessful();
});

it('renders the stored title in the table without an Untitled placeholder', function (): void {
    $conversation = seedAdminConversation('Real conversation title');

    livewire(ListAgentConversations::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$conversation])
        ->assertTableColumnStateSet('title', 'Real conversation title', record: $conversation)
        ->assertDontSee('Untitled');
});

it('renders the stored title on the detail page without an Untitled placeholder', function (): void {
    $conversation = seedAdminConversation('Detail page title');

    livewire(ViewAgentConversation::class, ['record' => $conversation->getKey()])
        ->assertSuccessful()
        ->assertSee('Detail page title')
        ->assertDontSee('Untitled');
});

it('shows an origin only on user rows in the messages relation manager', function (): void {
    $conversation = seedAdminConversation(messages: 2);
    [$user, $assistant] = $conversation->messages()->orderBy('id')->get()->all();

    livewire(MessagesRelationManager::class, [
        'ownerRecord' => $conversation,
        'pageClass' => ViewAgentConversation::class,
    ])
        ->assertTableColumnStateSet('origin', MessageOrigin::Typed, $user)
        ->assertTableColumnStateSet('origin', null, $assistant);
});
