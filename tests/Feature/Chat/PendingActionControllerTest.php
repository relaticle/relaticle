<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Actions\Company\DeleteCompany;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Http\Controllers\PendingActionController;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;

mutates(PendingActionController::class);

beforeEach(function () {
    Bus::fake();

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

function insertConversation(string $id, User $user, Team $team): void
{
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('approves a pending action and creates the record', function (): void {
    insertConversation('conv-123', $this->user, $this->team);

    $pending = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => 'conv-123',
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Test Corp'],
        'display_data' => ['title' => 'Create Company', 'summary' => 'Create company "Test Corp"', 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->postJson(route('chat.actions.approve', $pending))
        ->assertOk()
        ->assertJsonPath('status', 'approved');

    $this->assertDatabaseHas('companies', ['name' => 'Test Corp']);
});

it('rejects a pending action without creating the record', function (): void {
    insertConversation('conv-123', $this->user, $this->team);

    $pending = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => 'conv-123',
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Rejected Corp'],
        'display_data' => ['title' => 'Create Company', 'summary' => 'Create company "Rejected Corp"', 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->postJson(route('chat.actions.reject', $pending))
        ->assertOk()
        ->assertJsonPath('status', 'rejected');

    $this->assertDatabaseMissing('companies', ['name' => 'Rejected Corp']);
});

it('returns 422 for expired actions', function (): void {
    insertConversation('conv-123', $this->user, $this->team);

    $pending = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => 'conv-123',
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Expired Corp'],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->subMinutes(5),
    ]);

    $this->postJson(route('chat.actions.approve', $pending))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'This action has expired');
});

it('returns 422 for already resolved actions', function (): void {
    insertConversation('conv-123', $this->user, $this->team);

    $pending = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => 'conv-123',
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Already Done Corp'],
        'display_data' => [],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now(),
    ]);

    $this->postJson(route('chat.actions.approve', $pending))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'This action has already been resolved');
});

it('returns 404 for actions from another team', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    insertConversation('conv-other', $otherUser, $otherUser->currentTeam);

    $pending = PendingAction::query()->create([
        'team_id' => $otherUser->currentTeam->getKey(),
        'user_id' => $otherUser->getKey(),
        'conversation_id' => 'conv-other',
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Other Team Corp'],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->postJson(route('chat.actions.approve', $pending))
        ->assertNotFound();
});

it('returns 404 for actions belonging to another user on same team', function (): void {
    $otherUser = User::factory()->create();
    $this->team->users()->attach($otherUser, ['role' => 'member']);
    insertConversation('conv-other-user', $otherUser, $this->team);

    $pending = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $otherUser->getKey(),
        'conversation_id' => 'conv-other-user',
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Not My Action'],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->postJson(route('chat.actions.approve', $pending))
        ->assertNotFound();
});

it('rejects unauthenticated approve request', function (): void {
    insertConversation('conv-123', $this->user, $this->team);

    $pending = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => 'conv-123',
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Unauth Corp'],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    auth()->logout();

    $this->postJson(route('chat.actions.approve', $pending))
        ->assertUnauthorized();
});

it('rejects are idempotent under second call', function (): void {
    insertConversation('conv-x', $this->user, $this->team);

    $pending = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => 'conv-x',
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Acme'],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $service = app(PendingActionService::class);

    $service->reject($pending);

    expect(fn () => $service->reject($pending->refresh()))->toThrow(RuntimeException::class);
});

it('restores a soft-deleted record within the undo window', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Restore Me Corp']);
    $company->delete();
    insertConversation('conv-restore', $this->user, $this->team);

    $pending = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => 'conv-restore',
        'action_class' => DeleteCompany::class,
        'operation' => PendingActionOperation::Delete,
        'entity_type' => 'company',
        'action_data' => [
            '_record_ids' => [$company->getKey()],
            '_model_class' => Company::class,
        ],
        'display_data' => [],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now()->subMinute(),
    ]);

    $this->postJson(route('chat.actions.restore', $pending))
        ->assertOk()
        ->assertJsonPath('status', 'restored')
        ->assertJsonPath('record.id', (string) $company->getKey())
        ->assertJsonPath('record.type', 'company');

    expect($company->fresh()->trashed())->toBeFalse();
    expect($pending->fresh()->status)->toBe(PendingActionStatus::Restored);
});

it('restores a bulk-deleted set of records and returns null record reference', function (): void {
    $c1 = Company::factory()->for($this->team)->create(['name' => 'Bulk Corp One']);
    $c2 = Company::factory()->for($this->team)->create(['name' => 'Bulk Corp Two']);
    $c1->delete();
    $c2->delete();
    insertConversation('conv-restore-bulk', $this->user, $this->team);

    $pending = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => 'conv-restore-bulk',
        'action_class' => DeleteCompany::class,
        'operation' => PendingActionOperation::Delete,
        'entity_type' => 'company',
        'action_data' => [
            '_record_ids' => [$c1->getKey(), $c2->getKey()],
            '_model_class' => Company::class,
        ],
        'display_data' => [],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now()->subMinute(),
    ]);

    $this->postJson(route('chat.actions.restore', $pending))
        ->assertOk()
        ->assertJsonPath('status', 'restored')
        ->assertJsonPath('record', null);

    expect($c1->fresh()->trashed())->toBeFalse();
    expect($c2->fresh()->trashed())->toBeFalse();
});

it('rejects restore after the 5-minute window', function (): void {
    $company = Company::factory()->for($this->team)->create();
    $company->delete();
    insertConversation('conv-restore-late', $this->user, $this->team);

    $pending = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => 'conv-restore-late',
        'action_class' => DeleteCompany::class,
        'operation' => PendingActionOperation::Delete,
        'entity_type' => 'company',
        'action_data' => [
            '_record_ids' => [$company->getKey()],
            '_model_class' => Company::class,
        ],
        'display_data' => [],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now()->subMinutes(10),
    ]);

    $this->postJson(route('chat.actions.restore', $pending))
        ->assertStatus(410)
        ->assertJsonPath('error', 'undo_window_expired');

    expect($company->fresh()->trashed())->toBeTrue();
});

it('rejects restore for non-delete operations', function (): void {
    insertConversation('conv-restore-create', $this->user, $this->team);

    $pending = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => 'conv-restore-create',
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Something'],
        'display_data' => [],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now()->subMinute(),
    ]);

    $this->postJson(route('chat.actions.restore', $pending))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'Only deleted records can be restored');
});

it('rejects restore for cross-team users', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $company = Company::factory()->for($otherUser->currentTeam)->create();
    $company->delete();
    insertConversation('conv-restore-cross', $otherUser, $otherUser->currentTeam);

    $pending = PendingAction::query()->create([
        'team_id' => $otherUser->currentTeam->getKey(),
        'user_id' => $otherUser->getKey(),
        'conversation_id' => 'conv-restore-cross',
        'action_class' => DeleteCompany::class,
        'operation' => PendingActionOperation::Delete,
        'entity_type' => 'company',
        'action_data' => [
            '_record_ids' => [$company->getKey()],
            '_model_class' => Company::class,
        ],
        'display_data' => [],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now()->subMinute(),
    ]);

    $this->postJson(route('chat.actions.restore', $pending))
        ->assertNotFound();

    expect($company->fresh()->trashed())->toBeTrue();
});

it('approves a single batch item via the endpoint and reports not finalized', function (): void {
    $pending = PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['_batch' => true, 'records' => [['title' => 'Endpoint A'], ['title' => 'Endpoint B']]],
        'display_data' => ['items' => [['summary' => 'Endpoint A'], ['summary' => 'Endpoint B']]],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $res = $this->postJson("/chat/actions/{$pending->id}/items/0/approve");

    $res->assertOk()->assertJson(['status' => 'approved', 'index' => 0, 'finalized' => false]);
    expect($pending->fresh()->status)->toBe(PendingActionStatus::Pending);
});

it('finalizes when the last item is resolved via the endpoint', function (): void {
    $pending = PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['_batch' => true, 'records' => [['title' => 'Only A'], ['title' => 'Only B']]],
        'display_data' => ['items' => [['summary' => 'Only A'], ['summary' => 'Only B']]],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->postJson("/chat/actions/{$pending->id}/items/0/approve")->assertOk();
    $res = $this->postJson("/chat/actions/{$pending->id}/items/1/reject");

    $res->assertOk()->assertJson(['status' => 'rejected', 'index' => 1, 'finalized' => true]);
    expect($pending->fresh()->status)->toBe(PendingActionStatus::Approved); // one was created
});

it('404s an item endpoint for another tenant and 422s an out-of-range index', function (): void {
    $other = User::factory()->withPersonalTeam()->create();
    $foreign = PendingAction::query()->create([
        'team_id' => $other->currentTeam->getKey(),
        'user_id' => $other->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['_batch' => true, 'records' => [['title' => 'X']]],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
    $this->postJson("/chat/actions/{$foreign->id}/items/0/approve")->assertNotFound();

    $mine = PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['_batch' => true, 'records' => [['title' => 'A']]],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
    $this->postJson("/chat/actions/{$mine->id}/items/9/approve")->assertStatus(422);
});

it('dispatches no continuation when approving a pending action via the endpoint', function (): void {
    insertConversation('conv-no-continuation', $this->user, $this->team);

    $pending = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => 'conv-no-continuation',
        'message_id' => null,
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'No Continuation Co'],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->postJson(route('chat.actions.approve', $pending))
        ->assertOk()
        ->assertJsonPath('status', 'approved');

    $this->assertDatabaseHas('companies', ['name' => 'No Continuation Co']);
});
