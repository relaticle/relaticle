<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
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
