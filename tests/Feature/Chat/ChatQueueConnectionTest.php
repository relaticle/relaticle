<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Relaticle\Chat\Jobs\ProcessChatMessage;

it('rides the chat connection whose retry_after clears the worker timeout', function (): void {
    $retryAfter = (int) config('queue.connections.redis-chat.retry_after');
    $workerTimeouts = collect(config('horizon.environments'))
        ->map(fn (array $supervisors): array => $supervisors['chat-supervisor'] ?? [])
        ->filter();

    expect($retryAfter)->toBeGreaterThanOrEqual(150)
        ->and($workerTimeouts)->not->toBeEmpty();

    foreach ($workerTimeouts as $supervisor) {
        expect($supervisor['connection'])->toBe('redis-chat')
            ->and($retryAfter)->toBeGreaterThan((int) ($supervisor['timeout'] ?? 0));
    }
});

it('dispatches the chat turn on the redis-chat connection', function (): void {
    Queue::fake();
    $user = User::factory()->withPersonalTeam()->create();

    dispatch(new ProcessChatMessage(
        user: $user,
        team: $user->currentTeam,
        message: 'hello',
        conversationId: (string) Str::uuid7(),
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet', 'source' => 'auto'],
    ));

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => $job->connection === 'redis-chat' && $job->queue === 'chat');
});
