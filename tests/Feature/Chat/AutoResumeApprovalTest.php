<?php

declare(strict_types=1);

use App\Actions\People\CreatePeople;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\ApprovalContinuationService;
use Relaticle\Chat\Services\PendingActionService;
use Tests\Helpers\ChatDocument;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->convId = '019df800-1111-7000-8000-000000000001';

    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('dispatches ContinueChatMessage with an [approval] prompt on approval', function (): void {
    Bus::fake();

    $action = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => 'App\\Actions\\People\\CreatePeople',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'people',
        'action_data' => ['name' => 'Angel'],
        'display_data' => ['title' => 'Create Person'],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now(),
        'result_data' => ['id' => '01abc000000000000000000000', 'type' => 'people'],
    ]);

    resolve(ApprovalContinuationService::class)->dispatchAfterApproval($action, 'approved');

    Bus::assertDispatched(ContinueChatMessage::class, function (ContinueChatMessage $job): bool {
        return $job->conversationId === $this->convId
            && str_starts_with($job->prompt, '[approval]')
            && str_contains($job->prompt, 'status: approved')
            && str_contains($job->prompt, 'record_id: 01abc000000000000000000000')
            && str_contains($job->prompt, 'entity_type: people');
    });
});

it('uses status=rejected and omits record_id when rejecting', function (): void {
    Bus::fake();

    $action = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'X'],
        'display_data' => ['title' => 'Create Task'],
        'status' => PendingActionStatus::Rejected,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now(),
    ]);

    resolve(ApprovalContinuationService::class)->dispatchAfterApproval($action, 'rejected');

    Bus::assertDispatched(ContinueChatMessage::class, function (ContinueChatMessage $job): bool {
        return str_contains($job->prompt, 'status: rejected')
            && ! str_contains($job->prompt, 'record_id:');
    });
});

it('skips dispatch after 5 consecutive [approval] continuations without real user input', function (): void {
    Bus::fake();

    for ($i = 0; $i < 5; $i++) {
        DB::table('agent_conversation_messages')->insert([
            'id' => '019df800-1111-7000-8000-00000000020'.$i,
            'conversation_id' => $this->convId,
            'user_id' => (string) $this->user->getKey(),
            'agent' => 'crm',
            'role' => 'user',
            'content' => "[approval]\nstatus: approved\nentity_type: people\n",
            'document' => ChatDocument::emptyJson(),
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now()->addSeconds($i),
            'updated_at' => now()->addSeconds($i),
        ]);
    }

    $action = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'X'],
        'display_data' => ['title' => 'Create Task'],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now(),
        'result_data' => ['id' => '01zzz000000000000000000000', 'type' => 'task'],
    ]);

    resolve(ApprovalContinuationService::class)->dispatchAfterApproval($action, 'approved');

    Bus::assertNotDispatched(ContinueChatMessage::class);
});

it('approving a pending action via the service dispatches a continuation job', function (): void {
    Bus::fake();
    $this->actingAs($this->user);

    $action = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => CreatePeople::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'people',
        'action_data' => ['name' => 'Angel'],
        'display_data' => ['title' => 'Create Person'],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    resolve(PendingActionService::class)->approve($action, $this->user);

    Bus::assertDispatched(ContinueChatMessage::class);
});

it('rejecting a pending action also dispatches a continuation', function (): void {
    Bus::fake();

    $action = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => CreatePeople::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'people',
        'action_data' => ['name' => 'Angel'],
        'display_data' => ['title' => 'Create Person'],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    resolve(PendingActionService::class)->reject($action);

    Bus::assertDispatched(ContinueChatMessage::class, function (ContinueChatMessage $job): bool {
        return str_contains($job->prompt, 'status: rejected');
    });
});
