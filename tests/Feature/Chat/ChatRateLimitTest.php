<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Exceptions\RateLimitedException;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Jobs\ProcessChatMessage;

uses(LazilyRefreshDatabase::class);

function seedRateLimitConversation(string $id, User $user): void
{
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('computes capped exponential backoff', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $job = new ProcessChatMessage(
        user: $user, team: $user->currentTeam, message: 'hi', conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto'], turnId: '01TURNAAAAAAAAAAAAAAAAAAAAA',
    );

    expect($job->retryDelaySeconds(1))->toBe(2)
        ->and($job->retryDelaySeconds(3))->toBe(8)
        ->and($job->retryDelaySeconds(10))->toBe(30);
});

it('broadcasts a rate-limit-specific message when a rate-limited job ultimately fails', function (): void {
    Event::fake([ChatStreamFailed::class]);

    $user = User::factory()->withPersonalTeam()->create();
    seedRateLimitConversation('c-1', $user);
    $job = new ProcessChatMessage(
        user: $user, team: $user->currentTeam, message: 'hi', conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto'], turnId: '01TURNBBBBBBBBBBBBBBBBBBBBB',
    );

    $job->failed(new RateLimitedException('rate limited', 429));

    Event::assertDispatched(ChatStreamFailed::class, fn (ChatStreamFailed $e): bool => str_contains($e->message, 'rate-limited'));
});

it('broadcasts a rate-limit message when a rate-limited continuation ultimately fails', function (): void {
    Event::fake([ChatStreamFailed::class]);

    $user = User::factory()->withPersonalTeam()->create();
    seedRateLimitConversation('c-1', $user);
    $job = new ContinueChatMessage(
        user: $user, team: $user->currentTeam, conversationId: 'c-1',
        prompt: '[approval]', turnId: '01TURNCCCCCCCCCCCCCCCCCCCCC',
    );

    $job->failed(new RateLimitedException('rate limited', 429));

    Event::assertDispatched(ChatStreamFailed::class, fn (ChatStreamFailed $e): bool => str_contains($e->message, 'rate-limited'));
});
