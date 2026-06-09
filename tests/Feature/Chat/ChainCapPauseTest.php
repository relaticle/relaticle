<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Events\ChatPaused;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\ApprovalContinuationService;

uses(LazilyRefreshDatabase::class);

it('broadcasts ChatPaused and dispatches no continuation when the chain cap is hit', function (): void {
    Bus::fake();
    Event::fake([ChatPaused::class]);

    $user = User::factory()->withPersonalTeam()->create();
    DB::table('agent_conversations')->insert([
        'id' => 'conv-cap', 'user_id' => $user->getKey(), 'team_id' => $user->currentTeam->getKey(),
        'title' => 'T', 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (range(1, 5) as $i) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::ulid(), 'conversation_id' => 'conv-cap', 'user_id' => $user->getKey(),
            'agent' => 'crm', 'role' => 'user', 'content' => '[approval]', 'attachments' => '[]',
            'tool_calls' => '[]', 'tool_results' => '[]', 'usage' => '{}', 'meta' => '{}',
            'created_at' => now()->addSeconds($i), 'updated_at' => now()->addSeconds($i),
        ]);
    }

    $action = PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(), 'user_id' => $user->getKey(),
        'conversation_id' => 'conv-cap', 'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create, 'entity_type' => 'task',
        'action_data' => ['title' => 'Sixth'], 'display_data' => [],
        'status' => PendingActionStatus::Approved, 'expires_at' => now(), 'resolved_at' => now(),
        'result_data' => ['id' => 'six'],
    ]);

    resolve(ApprovalContinuationService::class)->dispatchAfterApproval($action, 'approved');

    Bus::assertNotDispatched(ContinueChatMessage::class);
    Event::assertDispatched(ChatPaused::class, fn (ChatPaused $e): bool => $e->conversationId === 'conv-cap');
});
