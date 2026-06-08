<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\People\CreatePersonTool;
use Relaticle\Chat\Tools\Task\CreateTaskTool;

it('multi-step workflow: create person -> approve -> continuation creates linked task', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Auth::guard('web')->setUser($user);
    Filament::setTenant($user->currentTeam);

    $convId = '019df800-5555-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $convId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $personTool = resolve(CreatePersonTool::class);
    $personTool->setConversationId($convId);
    $personTool->handle(new Request(['name' => 'Angel']));

    $personPending = PendingAction::query()
        ->where('conversation_id', $convId)
        ->where('entity_type', 'people')
        ->latest()
        ->firstOrFail();

    expect($personPending->status)->toBe(PendingActionStatus::Pending);

    Bus::fake();
    $approved = resolve(PendingActionService::class)->approve($personPending, $user);
    Bus::assertDispatched(ContinueChatMessage::class);

    expect($approved->status)->toBe(PendingActionStatus::Approved);
    $angelId = $approved->result_data['id'] ?? null;
    expect($angelId)->toBeString();

    $taskTool = resolve(CreateTaskTool::class);
    $taskTool->setConversationId($convId);
    $taskTool->handle(new Request([
        'title' => 'Follow up call for tomorrow',
        'people_ids' => [(string) $angelId],
    ]));

    $taskPending = PendingAction::query()
        ->where('conversation_id', $convId)
        ->where('entity_type', 'task')
        ->latest()
        ->firstOrFail();

    expect($taskPending->action_data)->toHaveKey('people_ids', [(string) $angelId]);

    $approvedTask = resolve(PendingActionService::class)->approve($taskPending, $user);

    expect($approvedTask->status)->toBe(PendingActionStatus::Approved);
    $taskId = $approvedTask->result_data['id'] ?? null;
    expect($taskId)->toBeString();

    $task = Task::query()->findOrFail($taskId);
    expect($task->people()->pluck('people.id')->all())->toContain((string) $angelId);
});
