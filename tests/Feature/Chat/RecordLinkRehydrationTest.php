<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Actions\ListConversationMessages;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Tests\Helpers\ChatDocument;

mutates(ListConversationMessages::class);

it('approved actions expose record.url after conversation reload', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    $angel = People::factory()->for($user->currentTeam)->create(['name' => 'Angel']);

    $convId = '019df800-4444-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $convId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pending = PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $convId,
        'action_class' => 'App\\Actions\\People\\CreatePeople',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'people',
        'action_data' => ['name' => 'Angel'],
        'display_data' => ['title' => 'Create Person'],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now(),
        'result_data' => ['id' => (string) $angel->id, 'type' => 'people'],
    ]);

    $toolCallId = 'toolu_'.uniqid();
    $toolResults = [[
        'id' => $toolCallId,
        'name' => 'CreatePersonTool',
        'result' => json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'action' => 'CreatePeople',
            'entity_type' => 'people',
            'operation' => 'create',
            'data' => ['name' => 'Angel'],
            'display' => ['title' => 'Create Person'],
        ]),
    ]];

    $base = [
        'conversation_id' => $convId,
        'user_id' => (string) $user->getKey(),
        'agent' => 'crm',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'usage' => '{}',
        'meta' => '{}',
    ];

    DB::table('agent_conversation_messages')->insert([
        'id' => '019df800-4444-7000-8000-000000000010',
        'role' => 'assistant',
        'content' => 'I have proposed creating a person.',
        'tool_results' => json_encode($toolResults),
        'created_at' => now(),
        'updated_at' => now(),
    ] + $base);

    $messages = resolve(ListConversationMessages::class)->execute($user, $convId);

    $assistant = collect($messages)->firstWhere('role', 'assistant');
    $action = $assistant['pending_actions'][0] ?? null;

    expect($action)->not->toBeNull();
    expect($action['record'] ?? null)->toMatchArray([
        'id' => (string) $angel->id,
        'type' => 'people',
        'url' => PeopleResource::getUrl('view', ['record' => (string) $angel->id]),
    ]);
});

it('does not expose record on pending or rejected actions', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    $convId = '019df800-4444-7000-8000-000000000002';
    DB::table('agent_conversations')->insert([
        'id' => $convId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pending = PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $convId,
        'action_class' => 'App\\Actions\\People\\CreatePeople',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'people',
        'action_data' => ['name' => 'Angel'],
        'display_data' => ['title' => 'Create Person'],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $toolResults = [[
        'id' => 'toolu_test',
        'name' => 'CreatePersonTool',
        'result' => json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'entity_type' => 'people',
            'operation' => 'create',
            'data' => ['name' => 'Angel'],
            'display' => ['title' => 'Create Person'],
        ]),
    ]];

    $base = [
        'conversation_id' => $convId,
        'user_id' => (string) $user->getKey(),
        'agent' => 'crm',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'usage' => '{}',
        'meta' => '{}',
    ];

    DB::table('agent_conversation_messages')->insert([
        'id' => '019df800-4444-7000-8000-000000000020',
        'role' => 'assistant',
        'content' => 'Pending.',
        'tool_results' => json_encode($toolResults),
        'created_at' => now(),
        'updated_at' => now(),
    ] + $base);

    $messages = resolve(ListConversationMessages::class)->execute($user, $convId);
    $action = collect($messages)->firstWhere('role', 'assistant')['pending_actions'][0] ?? null;

    expect($action)->not->toBeNull();
    expect($action['record'] ?? null)->toBeNull();
});
