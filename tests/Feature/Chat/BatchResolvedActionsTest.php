<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    Bus::fake();
    $this->user = User::factory()->withPersonalTeam()->create();
    Auth::guard('web')->setUser($this->user);
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);

    $this->convId = '019df901-5555-7000-8000-000000000002';
    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('returns record_ids for a batch-approved action in resolvedSinceLastAssistantMessage', function (): void {
    PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['_batch' => true, 'records' => [['title' => 'A'], ['title' => 'B']]],
        'display_data' => ['title' => 'Create Tasks', 'summary' => 'Create 2 tasks', 'items' => []],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now(),
        'result_data' => ['ids' => ['01aa0000000000000000000000', '01bb0000000000000000000000'], 'type' => 'task', 'count' => 2],
    ]);

    $results = resolve(PendingActionService::class)->resolvedSinceLastAssistantMessage($this->convId);

    expect($results)->toHaveCount(1)
        ->and($results[0]['record_ids'])->toContain('01aa0000000000000000000000')
        ->and($results[0]['record_ids'])->toContain('01bb0000000000000000000000')
        ->and($results[0]['record_ids'])->toHaveCount(2);
});

it('includes both batch ids in the resolved block of agent instructions', function (): void {
    PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['_batch' => true, 'records' => [['title' => 'A'], ['title' => 'B']]],
        'display_data' => ['title' => 'Create Tasks', 'summary' => 'Create 2 tasks', 'items' => []],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now(),
        'result_data' => ['ids' => ['01aa0000000000000000000000', '01bb0000000000000000000000'], 'type' => 'task', 'count' => 2],
    ]);

    $resolved = resolve(PendingActionService::class)->resolvedSinceLastAssistantMessage($this->convId);

    $agent = resolve(CrmAssistant::class)->withResolvedActions($resolved);

    $instructions = $agent->instructions();

    expect($instructions)
        ->toContain('01aa0000000000000000000000')
        ->toContain('01bb0000000000000000000000');
});

it('returns an empty record_ids list and still emits record_id for a flat approval', function (): void {
    PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Solo task'],
        'display_data' => ['summary' => 'Create task "Solo task"'],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now(),
        'result_data' => ['id' => '01cc0000000000000000000000', 'type' => 'task'],
    ]);

    $results = resolve(PendingActionService::class)->resolvedSinceLastAssistantMessage($this->convId);

    expect($results)->toHaveCount(1)
        ->and($results[0]['record_id'])->toBe('01cc0000000000000000000000')
        ->and($results[0]['record_ids'])->toBe([]);

    $agent = resolve(CrmAssistant::class)->withResolvedActions($results);

    expect($agent->instructions())->toContain('01cc0000000000000000000000');
});
