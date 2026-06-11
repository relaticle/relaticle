<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Models\PendingAction;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->convId = '019df800-3333-7000-8000-000000000001';

    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function makeResolvedAction(string $convId, User $user): PendingAction
{
    return PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $convId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Follow up'],
        'display_data' => ['title' => 'Create Task', 'summary' => 'Create task "Follow up"'],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now(),
        'result_data' => ['id' => '01abc000000000000000000000', 'type' => 'task'],
    ]);
}

it('re-dispatches a continuation for the latest unjournaled resolved action', function (): void {
    Bus::fake();
    makeResolvedAction($this->convId, $this->user);

    $this->actingAs($this->user)
        ->postJson("/chat/conversations/{$this->convId}/resume")
        ->assertOk();

    Bus::assertDispatched(ContinueChatMessage::class, fn (ContinueChatMessage $job): bool => $job->conversationId === $this->convId);
});

it('returns 409 when there is nothing to resume', function (): void {
    Bus::fake();

    $this->actingAs($this->user)
        ->postJson("/chat/conversations/{$this->convId}/resume")
        ->assertStatus(409);

    Bus::assertNotDispatched(ContinueChatMessage::class);
});

it('hides a foreign conversation behind a 404 instead of confirming it exists', function (): void {
    Bus::fake();
    $other = User::factory()->withPersonalTeam()->create();

    $this->actingAs($other)
        ->postJson("/chat/conversations/{$this->convId}/resume")
        ->assertNotFound();

    Bus::assertNotDispatched(ContinueChatMessage::class);
});

it('dispatches only one continuation when resume is double-posted', function (): void {
    Bus::fake();
    makeResolvedAction($this->convId, $this->user);

    $this->actingAs($this->user)
        ->postJson("/chat/conversations/{$this->convId}/resume")
        ->assertOk();

    $this->actingAs($this->user)
        ->postJson("/chat/conversations/{$this->convId}/resume")
        ->assertStatus(409);

    Bus::assertDispatchedTimes(ContinueChatMessage::class, 1);
});
