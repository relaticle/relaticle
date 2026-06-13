<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\CustomField;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Http\Controllers\PendingActionController;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\ProposalEditor;

mutates(ProposalEditor::class, PendingActionController::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    Bus::fake([ContinueChatMessage::class]);
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

/**
 * @param  array<string, mixed>  $actionData
 */
function makeCreateProposal(
    User $user,
    string $entityType,
    string $actionClass,
    array $actionData,
    PendingActionOperation $operation = PendingActionOperation::Create,
): PendingAction {
    return PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => null,
        'action_class' => $actionClass,
        'operation' => $operation,
        'entity_type' => $entityType,
        'action_data' => $actionData,
        'display_data' => ['title' => 'Create', 'summary' => 'Create', 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
}

function seededTaskStatusField(string $teamId): CustomField
{
    $status = CustomField::query()
        ->where('tenant_id', $teamId)
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->with('options')
        ->first();

    expect($status)->not->toBeNull('seeded task status field is required for this test');

    return $status;
}

it('returns the editable schema for a single company create proposal', function (): void {
    $pending = makeCreateProposal(
        $this->user,
        'company',
        'App\\Actions\\Company\\CreateCompany',
        ['name' => 'Acme Corp', 'account_owner_id' => null],
    );

    $response = $this->getJson(route('chat.actions.editable', $pending))
        ->assertOk()
        ->assertJsonPath('pending_action_id', $pending->getKey())
        ->assertJsonPath('entity_type', 'company')
        ->assertJsonPath('is_batch', false)
        ->assertJsonPath('index', null)
        ->assertJsonPath('fields.0.code', 'name')
        ->assertJsonPath('fields.0.value', 'Acme Corp');

    expect($response->json('fields'))->toBeArray()->not->toBeEmpty();
});

it('returns the editable schema for a single batch task item', function (): void {
    $pending = makeCreateProposal(
        $this->user,
        'task',
        'App\\Actions\\Task\\CreateTask',
        ['_batch' => true, 'records' => [['title' => 'A'], ['title' => 'B']]],
    );

    $this->getJson(route('chat.actions.items.editable', ['pendingAction' => $pending, 'index' => 1]))
        ->assertOk()
        ->assertJsonPath('is_batch', true)
        ->assertJsonPath('index', 1)
        ->assertJsonPath('fields.0.code', 'title')
        ->assertJsonPath('fields.0.value', 'B');
});

it('returns 404 for a proposal belonging to another team', function (): void {
    $other = User::factory()->withPersonalTeam()->create();

    $pending = makeCreateProposal(
        $other,
        'company',
        'App\\Actions\\Company\\CreateCompany',
        ['name' => 'Other Corp'],
    );

    $this->getJson(route('chat.actions.editable', $pending))
        ->assertNotFound();
});

it('returns 422 for a non-create proposal', function (): void {
    $pending = makeCreateProposal(
        $this->user,
        'company',
        'App\\Actions\\Company\\DeleteCompany',
        ['_model_class' => 'App\\Models\\Company', '_record_ids' => ['123']],
        PendingActionOperation::Delete,
    );

    $this->getJson(route('chat.actions.editable', $pending))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'Only pending create proposals can be edited');
});

it('edits a single company name, re-renders display, and does not create the company', function (): void {
    $pending = makeCreateProposal(
        $this->user,
        'company',
        'App\\Actions\\Company\\CreateCompany',
        ['name' => 'Acme Corp', 'account_owner_id' => (string) $this->user->getKey()],
    );

    $this->patchJson(route('chat.actions.edit', $pending), ['fields' => ['name' => 'New Name']])
        ->assertOk()
        ->assertJsonPath('status', 'edited')
        ->assertJsonPath('index', null)
        ->assertJsonPath('display.fields.0.value', 'New Name');

    $pending->refresh();

    expect($pending->action_data['name'])->toBe('New Name')
        ->and($pending->status)->toBe(PendingActionStatus::Pending);

    $this->assertDatabaseMissing('companies', ['name' => 'New Name']);
    $this->assertDatabaseMissing('companies', ['name' => 'Acme Corp']);
    Bus::assertNotDispatched(ContinueChatMessage::class);
});

it('edits a task custom select by option label and stores the option id in action_data', function (): void {
    $status = seededTaskStatusField($this->team->getKey());
    $done = $status->options->firstWhere('name', 'Done');
    expect($done)->not->toBeNull();

    $pending = makeCreateProposal(
        $this->user,
        'task',
        'App\\Actions\\Task\\CreateTask',
        ['title' => 'Ship it'],
    );

    $this->patchJson(route('chat.actions.edit', $pending), ['fields' => ['title' => 'Ship it', 'status' => 'Done']])
        ->assertOk()
        ->assertJsonPath('status', 'edited');

    $pending->refresh();

    expect($pending->action_data['custom_fields']['status'])->toBe((string) $done->id)
        ->and($pending->status)->toBe(PendingActionStatus::Pending);

    $this->assertDatabaseMissing('tasks', ['title' => 'Ship it']);
});

it('edits a task custom select by option id and stores the same id (locks the id->label->id contract)', function (): void {
    $status = seededTaskStatusField($this->team->getKey());
    $done = $status->options->firstWhere('name', 'Done');
    expect($done)->not->toBeNull();

    $pending = makeCreateProposal(
        $this->user,
        'task',
        'App\\Actions\\Task\\CreateTask',
        ['title' => 'Ship it'],
    );

    $this->patchJson(route('chat.actions.edit', $pending), ['fields' => ['title' => 'Ship it', 'status' => (string) $done->id]])
        ->assertOk()
        ->assertJsonPath('status', 'edited');

    $pending->refresh();

    expect($pending->action_data['custom_fields']['status'])->toBe((string) $done->id);
});

it('rejects an empty required name and leaves action_data unchanged', function (): void {
    $pending = makeCreateProposal(
        $this->user,
        'company',
        'App\\Actions\\Company\\CreateCompany',
        ['name' => 'Acme Corp', 'account_owner_id' => (string) $this->user->getKey()],
    );

    $this->patchJson(route('chat.actions.edit', $pending), ['fields' => ['name' => '   ']])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'Name is required.');

    $pending->refresh();

    expect($pending->action_data['name'])->toBe('Acme Corp');
});

it('rejects an unknown option label on a choice field without mutating custom_fields', function (): void {
    seededTaskStatusField($this->team->getKey());

    $pending = makeCreateProposal(
        $this->user,
        'task',
        'App\\Actions\\Task\\CreateTask',
        ['title' => 'Ship it'],
    );

    $this->patchJson(route('chat.actions.edit', $pending), ['fields' => ['title' => 'Ship it', 'status' => 'Nope']])
        ->assertUnprocessable();

    $pending->refresh();

    expect($pending->action_data)->not->toHaveKey('custom_fields');
});

it('returns 422 when editing a non-pending proposal', function (): void {
    $pending = makeCreateProposal(
        $this->user,
        'company',
        'App\\Actions\\Company\\CreateCompany',
        ['name' => 'Acme Corp'],
    );
    $pending->update(['status' => PendingActionStatus::Approved, 'resolved_at' => now()]);

    $this->patchJson(route('chat.actions.edit', $pending), ['fields' => ['name' => 'New Name']])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'This action has already been resolved');
});

it('returns 422 when the fields payload is not an object', function (): void {
    $pending = makeCreateProposal(
        $this->user,
        'company',
        'App\\Actions\\Company\\CreateCompany',
        ['name' => 'Acme Corp'],
    );

    $this->patchJson(route('chat.actions.edit', $pending), ['fields' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'fields must be an object.');
});

it('edits one batch item without touching sibling records or status', function (): void {
    $pending = makeCreateProposal(
        $this->user,
        'task',
        'App\\Actions\\Task\\CreateTask',
        [
            '_batch' => true,
            'records' => [
                ['title' => 'A'],
                ['title' => 'B'],
            ],
        ],
    );

    $pending->update([
        'display_data' => [
            'title' => 'Create Tasks',
            'summary' => 'Create 2 tasks',
            'items' => [
                ['title' => 'Create Task', 'summary' => 'Create task "A"', 'fields' => [['label' => 'Title', 'value' => 'A']]],
                ['title' => 'Create Task', 'summary' => 'Create task "B"', 'fields' => [['label' => 'Title', 'value' => 'B']]],
            ],
        ],
    ]);

    $this->patchJson(route('chat.actions.items.edit', ['pendingAction' => $pending, 'index' => 0]), [
        'fields' => ['title' => 'A-edited'],
    ])
        ->assertOk()
        ->assertJsonPath('status', 'edited')
        ->assertJsonPath('index', 0)
        ->assertJsonPath('display.items.0.fields.0.value', 'A-edited');

    $pending->refresh();

    expect($pending->action_data['records'][0]['title'])->toBe('A-edited')
        ->and($pending->action_data['records'][1]['title'])->toBe('B')
        ->and($pending->status)->toBe(PendingActionStatus::Pending)
        ->and($pending->display_data['items'][1]['fields'][0]['value'])->toBe('B')
        ->and($pending->display_data['title'])->toBe('Create Tasks')
        ->and($pending->display_data['summary'])->toBe('Create 2 tasks');

    $this->assertDatabaseMissing('tasks', ['title' => 'A-edited']);
    Bus::assertNotDispatched(ContinueChatMessage::class);
});

it('422s an out-of-range batch item edit index', function (): void {
    $pending = makeCreateProposal(
        $this->user,
        'task',
        'App\\Actions\\Task\\CreateTask',
        [
            '_batch' => true,
            'records' => [
                ['title' => 'Only'],
            ],
        ],
    );

    $this->patchJson(route('chat.actions.items.edit', ['pendingAction' => $pending, 'index' => 9]), [
        'fields' => ['title' => 'X'],
    ])
        ->assertUnprocessable();
});

it('404s a batch item edit for another team', function (): void {
    $other = User::factory()->withPersonalTeam()->create();

    $pending = makeCreateProposal(
        $other,
        'task',
        'App\\Actions\\Task\\CreateTask',
        [
            '_batch' => true,
            'records' => [
                ['title' => 'Other Task'],
            ],
        ],
    );

    $this->patchJson(route('chat.actions.items.edit', ['pendingAction' => $pending, 'index' => 0]), [
        'fields' => ['title' => 'Hacked'],
    ])
        ->assertNotFound();
});

it('editing only the name leaves existing custom_fields intact', function (): void {
    $icpField = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'company')
        ->where('code', 'icp')
        ->first();

    expect($icpField)->not->toBeNull('seeded company icp field is required for this test');

    $pending = makeCreateProposal(
        $this->user,
        'company',
        'App\\Actions\\Company\\CreateCompany',
        [
            'name' => 'Acme Corp',
            'account_owner_id' => (string) $this->user->getKey(),
            'custom_fields' => ['icp' => true],
        ],
    );

    $this->patchJson(route('chat.actions.edit', $pending), ['fields' => ['name' => 'Renamed']])
        ->assertOk()
        ->assertJsonPath('status', 'edited');

    $pending->refresh();

    expect($pending->action_data['name'])->toBe('Renamed')
        ->and($pending->action_data['custom_fields'])->toHaveKey('icp')
        ->and($pending->action_data['custom_fields']['icp'])->toBeTrue();
});
