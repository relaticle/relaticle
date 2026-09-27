<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

mutates(ProcessChatMessage::class);

it('runs the turn in the conversation workspace after the user switches workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $conversationWorkspace = $user->currentWorkspace;
    $otherWorkspace = Workspace::factory()->create();
    $otherWorkspace->users()->attach($user, ['role' => 'member']);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $conversationWorkspace->getKey()], [
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = '019df800-5555-7000-8000-000000000077';

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $conversationWorkspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Queue::fake();

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $conversationWorkspace,
        message: 'list my companies',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
    );

    $user->forceFill(['current_workspace_id' => $otherWorkspace->getKey()])->save();

    $workspaceSeenByTools = null;

    CrmAssistant::fake([function () use (&$workspaceSeenByTools): string {
        $workspaceSeenByTools = auth()->user()?->currentWorkspace?->getKey();

        return 'ok';
    }]);

    unserialize(serialize($job))->handle(resolve(CreditService::class));

    expect($workspaceSeenByTools)->toBe($conversationWorkspace->getKey());
});
