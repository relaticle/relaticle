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
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Services\PendingActionService;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    Bus::fake();
    $this->user = User::factory()->withPersonalTeam()->create();
    Auth::guard('web')->setUser($this->user);
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);

    $this->convId = '019df900-6666-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('flags a create proposal whose title matches a recently approved one', function (): void {
    $service = resolve(PendingActionService::class);

    $first = $service->createProposal(
        user: $this->user,
        conversationId: $this->convId,
        actionClass: 'App\\Actions\\Task\\CreateTask',
        operation: PendingActionOperation::Create,
        entityType: 'task',
        actionData: ['title' => 'Schedule product demo with Acme Corp'],
        displayData: ['summary' => 'Create task "Schedule product demo with Acme Corp"'],
    );
    $first->update(['status' => PendingActionStatus::Approved, 'resolved_at' => now()]);

    $second = $service->createProposal(
        user: $this->user,
        conversationId: $this->convId,
        actionClass: 'App\\Actions\\Task\\CreateTask',
        operation: PendingActionOperation::Create,
        entityType: 'task',
        actionData: ['title' => 'Schedule product demo with Acme Corp', 'description' => 'reworded variant'],
        displayData: ['summary' => 'Create task "Schedule product demo with Acme Corp"'],
    );

    expect($second->display_data['duplicate_warning'] ?? null)
        ->toContain('Schedule product demo with Acme Corp');
});

it('does not flag distinct titles', function (): void {
    $service = resolve(PendingActionService::class);

    $action = $service->createProposal(
        user: $this->user,
        conversationId: $this->convId,
        actionClass: 'App\\Actions\\Task\\CreateTask',
        operation: PendingActionOperation::Create,
        entityType: 'task',
        actionData: ['title' => 'Something unique'],
        displayData: ['summary' => 'Create task "Something unique"'],
    );

    expect($action->display_data)->not->toHaveKey('duplicate_warning');
});

it('does not warn on a byte-identical retry re-proposal', function (): void {
    $service = resolve(PendingActionService::class);

    $args = [
        'user' => $this->user,
        'conversationId' => $this->convId,
        'actionClass' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entityType' => 'task',
        'actionData' => ['title' => 'Retry me'],
        'displayData' => ['summary' => 'Create task "Retry me"'],
    ];

    $first = $service->createProposal(...$args);
    $second = $service->createProposal(...$args);

    expect($second->getKey())->toBe($first->getKey())
        ->and($second->display_data)->not->toHaveKey('duplicate_warning');
});

it('flags a batch containing a recently proposed title', function (): void {
    $service = resolve(PendingActionService::class);

    $service->createProposal(
        user: $this->user,
        conversationId: $this->convId,
        actionClass: 'App\\Actions\\Task\\CreateTask',
        operation: PendingActionOperation::Create,
        entityType: 'task',
        actionData: ['title' => 'Overlap task'],
        displayData: ['summary' => 'Create task "Overlap task"'],
    );

    $batch = $service->createProposal(
        user: $this->user,
        conversationId: $this->convId,
        actionClass: 'App\\Actions\\Task\\CreateTask',
        operation: PendingActionOperation::Create,
        entityType: 'task',
        actionData: ['_batch' => true, 'records' => [['title' => 'Fresh one'], ['title' => 'Overlap task']]],
        displayData: ['summary' => 'Create 2 tasks', 'items' => []],
    );

    expect($batch->display_data['duplicate_warning'] ?? null)->toContain('Overlap task');
});
