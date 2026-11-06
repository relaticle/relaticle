<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Enums\ProposalOutcome;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Queries\ConversationMessagesQuery;
use Relaticle\Chat\Queries\ProposalReceiptsQuery;
use Tests\Helpers\ChatDocument;

mutates(ConversationMessagesQuery::class);

it('approved actions expose record.url after conversation reload', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $angel = People::factory()->for($user->currentWorkspace)->create(['name' => 'Angel']);

    $convId = '019df800-4444-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $convId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pending = PendingAction::query()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
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
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'crm',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
    ];

    DB::table('agent_conversation_messages')->insert([
        'id' => '019df800-4444-7000-8000-000000000010',
        'role' => 'assistant',
        'content' => 'I have proposed creating a person.',
        'steps' => storedToolSteps($toolResults),
        'created_at' => now(),
        'updated_at' => now(),
    ] + $base);

    $messages = resolve(ConversationMessagesQuery::class)->get($user, $convId);

    $assistant = collect($messages)->firstWhere('role', 'assistant');
    $action = $assistant['pending_actions'][0] ?? null;

    expect($action)->not->toBeNull();
    expect($action['record'] ?? null)->toMatchArray([
        'id' => (string) $angel->id,
        'type' => 'people',
        'url' => PeopleResource::getUrl('view', ['record' => (string) $angel->id]),
    ]);
});

it('reconstructs per-item batch chips so resolved items survive reload', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $angel = People::factory()->for($user->currentWorkspace)->create(['name' => 'Angel']);

    $convId = '019df800-4444-7000-8000-000000000003';
    DB::table('agent_conversations')->insert([
        'id' => $convId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pending = PendingAction::query()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $convId,
        'action_class' => 'App\\Actions\\People\\CreatePeople',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'people',
        'action_data' => ['_batch' => true, 'records' => [['name' => 'Angel'], ['name' => 'Beth'], ['name' => 'Cara']]],
        'display_data' => ['title' => 'Create People', 'summary' => 'Create 3 people', 'items' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
        'result_data' => ['items' => [
            '0' => ['status' => 'approved', 'id' => (string) $angel->id],
            '1' => ['status' => 'rejected'],
        ]],
    ]);

    $toolResults = [[
        'id' => 'toolu_'.uniqid(),
        'name' => 'CreatePersonTool',
        'result' => json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'entity_type' => 'people',
            'operation' => 'create',
            'data' => ['_batch' => true],
            'display' => ['title' => 'Create People'],
        ]),
    ]];

    $base = [
        'conversation_id' => $convId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'crm',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
    ];

    DB::table('agent_conversation_messages')->insert([
        'id' => '019df800-4444-7000-8000-000000000030',
        'role' => 'assistant',
        'content' => 'I have proposed creating people.',
        'steps' => storedToolSteps($toolResults),
        'created_at' => now(),
        'updated_at' => now(),
    ] + $base);

    $messages = resolve(ConversationMessagesQuery::class)->get($user, $convId);
    $action = collect($messages)->firstWhere('role', 'assistant')['pending_actions'][0] ?? null;

    expect($action)->not->toBeNull();
    expect($action['status'])->toBe('pending');

    $itemResults = $action['itemResults'] ?? null;
    expect($itemResults)->not->toBeNull();

    expect($itemResults['0']['status'])->toBe('approved');
    expect($itemResults['0']['record'])->toMatchArray([
        'id' => (string) $angel->id,
        'type' => 'people',
        'url' => PeopleResource::getUrl('view', ['record' => (string) $angel->id]),
        'label' => 'Angel',
    ]);

    expect($itemResults['1']['status'])->toBe('skipped');
    expect($itemResults['1']['record'])->toBeNull();
});

it('does not expose record on pending or rejected actions', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $convId = '019df800-4444-7000-8000-000000000002';
    DB::table('agent_conversations')->insert([
        'id' => $convId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pending = PendingAction::query()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
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
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'crm',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
    ];

    DB::table('agent_conversation_messages')->insert([
        'id' => '019df800-4444-7000-8000-000000000020',
        'role' => 'assistant',
        'content' => 'Pending.',
        'steps' => storedToolSteps($toolResults),
        'created_at' => now(),
        'updated_at' => now(),
    ] + $base);

    $messages = resolve(ConversationMessagesQuery::class)->get($user, $convId);
    $action = collect($messages)->firstWhere('role', 'assistant')['pending_actions'][0] ?? null;

    expect($action)->not->toBeNull();
    expect($action['record'] ?? null)->toBeNull();
});

it('rehydrates a pending proposal with the instant it lapses', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $convId = '019df800-4444-7000-8000-000000000005';
    DB::table('agent_conversations')->insert([
        'id' => $convId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pending = PendingAction::query()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $convId,
        'action_class' => 'App\\Actions\\People\\CreatePeople',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'people',
        'action_data' => ['name' => 'Angel'],
        'display_data' => ['title' => 'Create Person'],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(1440),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => '019df800-4444-7000-8000-000000000050',
        'conversation_id' => $convId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'crm',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'role' => 'assistant',
        'content' => 'I have proposed creating a person.',
        'steps' => storedToolSteps([[
            'id' => 'toolu_'.uniqid(),
            'name' => 'CreatePersonTool',
            'result' => json_encode([
                'type' => 'pending_action',
                'pending_action_id' => $pending->id,
                'entity_type' => 'people',
                'operation' => 'create',
                'data' => ['name' => 'Angel'],
                'display' => ['title' => 'Create Person'],
            ]),
        ]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = resolve(ConversationMessagesQuery::class)->get($user, $convId);
    $action = collect($messages)->firstWhere('role', 'assistant')['pending_actions'][0];

    // Without this the client cannot tell a lapsed proposal from a live one, and
    // since the composer is hidden while one is docked, a tab left open past the
    // expiry is left with no way to type. The sweeper broadcasts nothing.
    expect($action['status'])->toBe('pending')
        ->and($action['expires_at'])->toBe($pending->expires_at->toIso8601String());
});

/**
 * @param  list<array<string, mixed>>  $steps
 * @return list<array<string, mixed>>
 */
function reloadedProposals(User $user, array $steps): array
{
    $convId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $convId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $results = [];

    foreach ($steps as $step) {
        $message = $step['message'] ?? 0;
        $entityType = $step['entity_type'];
        $operation = $step['operation'] ?? PendingActionOperation::Create;

        $pending = PendingAction::query()->create([
            'workspace_id' => $user->currentWorkspace->getKey(),
            'user_id' => $user->getKey(),
            'conversation_id' => $convId,
            'turn_id' => $step['turn_id'] ?? null,
            'action_class' => 'App\\Actions\\Receipt',
            'operation' => $operation,
            'entity_type' => $entityType,
            'action_data' => $step['action_data'] ?? ['name' => 'x'],
            'display_data' => $step['display'] ?? ['title' => 'x'],
            'status' => $step['status'],
            'expires_at' => now()->addMinutes(15),
            'resolved_at' => $step['status'] === PendingActionStatus::Pending ? null : now(),
            'result_data' => $step['result'] ?? null,
        ]);

        $results[$message][] = [
            'id' => 'toolu_'.Str::random(8),
            'name' => 'ReceiptTool',
            'result' => json_encode([
                'type' => 'pending_action',
                'pending_action_id' => $pending->id,
                'entity_type' => $entityType,
                'operation' => $operation->value,
                'data' => $pending->action_data,
                'display' => $pending->display_data,
            ]),
        ];
    }

    foreach ($results as $toolResults) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $convId,
            'participant_type' => 'user',
            'participant_id' => (string) $user->getKey(),
            'agent' => 'crm',
            'role' => 'assistant',
            'content' => 'Done.',
            'document' => ChatDocument::emptyJson(),
            'attachments' => '[]',
            'steps' => storedToolSteps($toolResults),
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return collect(resolve(ConversationMessagesQuery::class)->get($user, $convId))->pluck('pending_actions')->flatten(1)->all();
}

it('carries the excluded codes and the outcome of an approved update', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    [$update] = reloadedProposals($user, [[
        'entity_type' => 'opportunity',
        'operation' => PendingActionOperation::Update,
        'status' => PendingActionStatus::Approved,
        'result' => ['success' => true, 'excluded' => ['name']],
    ]]);

    expect($update['outcome'])->toBe('applied')
        ->and($update['excluded'])->toBe(['name'])
        ->and($update['resolved_at'])->toBeString()
        ->and($update['moved_to'])->toBeNull();
});

it('reads a rejected step beside an approved one as left out, and an unsent email as not sent', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $actions = reloadedProposals($user, [
        ['entity_type' => 'company', 'turn_id' => 'turn-a', 'status' => PendingActionStatus::Approved, 'result' => ['success' => true]],
        ['entity_type' => 'task', 'turn_id' => 'turn-a', 'status' => PendingActionStatus::Rejected],
        ['entity_type' => 'emails', 'turn_id' => 'turn-a', 'status' => PendingActionStatus::Rejected],
        ['entity_type' => 'company', 'turn_id' => 'turn-b', 'status' => PendingActionStatus::Rejected],
        ['entity_type' => 'task', 'turn_id' => 'turn-b', 'status' => PendingActionStatus::Rejected],
    ]);

    expect(array_column($actions, 'outcome'))->toBe(['applied', 'left_out', 'not_sent', 'discarded', 'discarded']);
});

it('reads a superseded proposal as replaced, which never offers Propose again', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    [$replaced, $expired] = reloadedProposals($user, [
        ['entity_type' => 'company', 'status' => PendingActionStatus::Superseded],
        ['entity_type' => 'task', 'status' => PendingActionStatus::Expired],
    ]);

    $outcomes = ProposalOutcome::forClient()['outcomes'];

    expect($replaced['outcome'])->toBe('replaced')
        ->and($outcomes['replaced']['again'])->toBeFalse()
        ->and($expired['outcome'])->toBe('expired')
        ->and($outcomes['expired']['again'])->toBeTrue();
});

it('gives a deleted record no link', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $task = Task::factory()->for($user->currentWorkspace)->create();

    [$delete] = reloadedProposals($user, [[
        'entity_type' => 'task',
        'operation' => PendingActionOperation::Delete,
        'status' => PendingActionStatus::Approved,
        'action_data' => ['_batch' => true, '_record_ids' => [$task->getKey()]],
        'result' => ['items' => [['status' => 'approved', 'id' => (string) $task->getKey()]], 'ids' => [(string) $task->getKey()]],
    ]]);

    expect($delete['itemResults']['0']['status'])->toBe('approved')
        ->and($delete['itemResults']['0']['record'])->toBeNull();
});

it('reports a move only when every written row changed only the stage to one value', function (array $rows, ?string $moved): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $stage = fn (string $to): array => ['label' => 'Stage', 'code' => 'stage', 'old' => 'Open', 'new' => $to];

    [$update] = reloadedProposals($user, [[
        'entity_type' => 'opportunity',
        'operation' => PendingActionOperation::Update,
        'status' => PendingActionStatus::Approved,
        'action_data' => ['_batch' => true, 'records' => array_map(fn (): array => ['id' => 'x'], $rows)],
        'display' => ['title' => 'Update', 'items' => array_map(fn (array $fields): array => ['summary' => 'x', 'fields' => array_map(fn (mixed $f): array => is_string($f) ? $stage($f) : $f, $fields)], $rows)],
        'result' => ['items' => array_map(fn (): array => ['status' => 'approved', 'id' => (string) Str::ulid()], $rows)],
    ]]);

    expect($update['moved_to'])->toBe($moved)
        ->and($update['moved_total'])->toBeNull();
})->with([
    'one stage for every row' => [[['Negotiation'], ['Negotiation']], 'Negotiation'],
    'two different stages' => [[['Negotiation'], ['Won']], null],
    'a row that also changes the amount' => [[['Negotiation'], ['Negotiation', ['label' => 'Amount', 'code' => 'amount', 'old' => '1', 'new' => '2']]], null],
]);

it('sums the Amount of the moved opportunities in the field currency', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $opportunities = Opportunity::factory()->count(2)->for($user->currentWorkspace)->create();
    $opportunities[0]->saveCustomFields(['amount' => 60000]);
    $opportunities[1]->saveCustomFields(['amount' => 50200]);

    $stage = ['label' => 'Stage', 'code' => 'stage', 'old' => 'Open', 'new' => 'Negotiation'];

    [$update] = reloadedProposals($user, [[
        'entity_type' => 'opportunity',
        'operation' => PendingActionOperation::Update,
        'status' => PendingActionStatus::Approved,
        'action_data' => ['_batch' => true, 'records' => [['id' => 'x'], ['id' => 'y']]],
        'display' => ['title' => 'Update', 'items' => [['summary' => 'x', 'fields' => [$stage]], ['summary' => 'y', 'fields' => [$stage]]]],
        'result' => ['items' => [
            ['status' => 'approved', 'id' => (string) $opportunities[0]->getKey()],
            ['status' => 'approved', 'id' => (string) $opportunities[1]->getKey()],
        ]],
    ]]);

    expect($update['moved_to'])->toBe('Negotiation')
        ->and($update['moved_total'])->toBe('$110,200.00');
});

it('reads a plan that spans two messages the way the live payload does', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $actions = reloadedProposals($user, [
        ['entity_type' => 'company', 'turn_id' => 'turn-span', 'message' => 0, 'status' => PendingActionStatus::Approved, 'result' => ['success' => true]],
        ['entity_type' => 'task', 'turn_id' => 'turn-span', 'message' => 1, 'status' => PendingActionStatus::Rejected],
    ]);

    $live = resolve(ProposalReceiptsQuery::class)->forPlanOf(PendingAction::query()->where('turn_id', 'turn-span')->firstOrFail());

    expect(array_column($actions, 'outcome'))->toBe(['applied', 'left_out'])
        ->and(array_map(fn (array $receipt): ?string => $receipt['outcome'], array_values($live)))->toBe(['applied', 'left_out']);
});

it('does not read more as moved steps are added', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $opportunities = Opportunity::factory()->count(6)->for($user->currentWorkspace)->create();
    $opportunities->each(fn (Opportunity $opportunity) => $opportunity->saveCustomFields(['amount' => 1000]));

    $stage = ['label' => 'Stage', 'code' => 'stage', 'old' => 'Open', 'new' => 'Negotiation'];
    $movedStep = fn (Opportunity $opportunity): array => [
        'entity_type' => 'opportunity',
        'operation' => PendingActionOperation::Update,
        'status' => PendingActionStatus::Approved,
        'action_data' => ['_batch' => true, 'records' => [['id' => 'x']]],
        'display' => ['title' => 'Update', 'items' => [['summary' => 'x', 'fields' => [$stage]]]],
        'result' => ['items' => [['status' => 'approved', 'id' => (string) $opportunity->getKey()]]],
    ];

    $queriesFor = function (array $steps) use ($user): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        reloadedProposals($user, $steps);
        $count = count(array_filter(
            DB::getQueryLog(),
            fn (array $entry): bool => str_contains($entry['query'], 'custom_field_values') || str_contains($entry['query'], 'from "custom_fields"'),
        ));
        DB::disableQueryLog();

        return $count;
    };

    $one = $queriesFor([$movedStep($opportunities[0])]);
    $many = $queriesFor($opportunities->map($movedStep)->all());

    expect($one)->toBeGreaterThan(0)
        ->and($many)->toBe($one);
});
