<?php

declare(strict_types=1);

use App\Health\ChatTurnFailureRateCheck;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Agents\CrmAssistant;
use Spatie\Health\Enums\Status;

mutates(ChatTurnFailureRateCheck::class);

function failureRateConversation(): string
{
    $user = User::factory()->withPersonalWorkspace()->create();
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => 'Failure rate',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $conversationId;
}

function seedFailureRateTurns(string $conversationId, int $count, bool $errored, CarbonImmutable $createdAt): void
{
    DB::table('agent_conversation_messages')->insert(array_map(static fn (): array => [
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'agent' => CrmAssistant::class,
        'role' => 'assistant',
        'content' => $errored ? 'The assistant encountered an error. Please try again.' : 'Your pipeline holds three deals.',
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '[]',
        'meta' => json_encode($errored ? ['error' => true] : ['model' => 'claude-sonnet-5'], JSON_THROW_ON_ERROR),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ], range(1, $count)));
}

it('fails when more than a fifth of recent assistant turns failed', function (): void {
    $conversationId = failureRateConversation();
    seedFailureRateTurns($conversationId, 4, errored: false, createdAt: now());
    seedFailureRateTurns($conversationId, 2, errored: true, createdAt: now());

    $result = ChatTurnFailureRateCheck::new()->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->meta)->toBe(['total' => 6, 'failed' => 2])
        ->and($result->notificationMessage)->toBe('2 of 6 chat turns failed in the last 10 minutes');
});

it('passes when exactly a fifth of recent assistant turns failed', function (): void {
    $conversationId = failureRateConversation();
    seedFailureRateTurns($conversationId, 4, errored: false, createdAt: now());
    seedFailureRateTurns($conversationId, 1, errored: true, createdAt: now());

    expect(ChatTurnFailureRateCheck::new()->run()->status)->toBe(Status::ok());
});

it('passes with fewer than five recent assistant turns even when all failed', function (): void {
    seedFailureRateTurns(failureRateConversation(), 4, errored: true, createdAt: now());

    expect(ChatTurnFailureRateCheck::new()->run()->status)->toBe(Status::ok());
});

it('ignores assistant turns older than ten minutes', function (): void {
    $conversationId = failureRateConversation();
    seedFailureRateTurns($conversationId, 5, errored: true, createdAt: now()->subMinutes(11));
    seedFailureRateTurns($conversationId, 5, errored: false, createdAt: now());

    $result = ChatTurnFailureRateCheck::new()->run();

    expect($result->status)->toBe(Status::ok())
        ->and($result->meta)->toBe(['total' => 5, 'failed' => 0]);
});
