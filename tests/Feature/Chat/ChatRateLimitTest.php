<?php

declare(strict_types=1);

use App\Models\User;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Streaming\Events\Error;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Jobs\ProcessChatMessage;

uses(LazilyRefreshDatabase::class);

function seedRateLimitConversation(string $id, User $user): void
{
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function httpClientException(int $status): RequestException
{
    return new RequestException(new ClientResponse(new Psr7Response($status)));
}

it('computes capped exponential backoff', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $job = new ProcessChatMessage(
        user: $user, team: $user->currentTeam, message: 'hi', conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto', 'id' => null, 'source' => 'auto'], turnId: '01TURNAAAAAAAAAAAAAAAAAAAAA',
    );

    expect($job->retryDelaySeconds(1))->toBe(2)
        ->and($job->retryDelaySeconds(3))->toBe(8)
        ->and($job->retryDelaySeconds(10))->toBe(30);
});

it('honors the provider Retry-After header when it exceeds the backoff', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $job = new ProcessChatMessage(
        user: $user, team: $user->currentTeam, message: 'hi', conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto', 'id' => null, 'source' => 'auto'], turnId: '01TURNAAAAAAAAAAAAAAAAAAAAA',
    );

    $exception = new RequestException(new ClientResponse(new Psr7Response(429, ['Retry-After' => '45'])));

    expect($job->retryDelaySeconds(1, $exception))->toBe(45)
        ->and($job->retryDelaySeconds(10, $exception))->toBe(45);
});

it('caps an absurd Retry-After at 60 seconds', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $job = new ProcessChatMessage(
        user: $user, team: $user->currentTeam, message: 'hi', conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto', 'id' => null, 'source' => 'auto'], turnId: '01TURNAAAAAAAAAAAAAAAAAAAAA',
    );

    $exception = new RequestException(new ClientResponse(new Psr7Response(429, ['Retry-After' => '600'])));

    expect($job->retryDelaySeconds(1, $exception))->toBe(60);
});

it('broadcasts a rate-limit-specific message when a rate-limited job ultimately fails', function (): void {
    Event::fake([ChatStreamFailed::class]);

    $user = User::factory()->withPersonalTeam()->create();
    seedRateLimitConversation('c-1', $user);
    $job = new ProcessChatMessage(
        user: $user, team: $user->currentTeam, message: 'hi', conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto', 'id' => null, 'source' => 'auto'], turnId: '01TURNBBBBBBBBBBBBBBBBBBBBB',
    );

    $job->failed(new RateLimitedException('rate limited', 429));

    Event::assertDispatched(ChatStreamFailed::class, fn (ChatStreamFailed $e): bool => str_contains($e->message, 'rate-limited'));
});

it('treats a raw streaming 429/529/503 RequestException as rate-limited, but not a 400', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $job = new ProcessChatMessage(
        user: $user, team: $user->currentTeam, message: 'hi', conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto', 'id' => null, 'source' => 'auto'], turnId: '01TURNDDDDDDDDDDDDDDDDDDDDD',
    );

    expect($job->isRateLimited(httpClientException(429)))->toBeTrue()
        ->and($job->isRateLimited(httpClientException(529)))->toBeTrue()
        ->and($job->isRateLimited(httpClientException(503)))->toBeTrue()
        ->and($job->isRateLimited(httpClientException(400)))->toBeFalse()
        ->and($job->isRateLimited(new RuntimeException('boom')))->toBeFalse()
        ->and($job->isRateLimited(null))->toBeFalse();
});

it('broadcasts the rate-limit message for a raw 429 RequestException failure', function (): void {
    Event::fake([ChatStreamFailed::class]);

    $user = User::factory()->withPersonalTeam()->create();
    seedRateLimitConversation('c-1', $user);
    $job = new ProcessChatMessage(
        user: $user, team: $user->currentTeam, message: 'hi', conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto', 'id' => null, 'source' => 'auto'], turnId: '01TURNFFFFFFFFFFFFFFFFFFFFF',
    );

    $job->failed(httpClientException(429));

    Event::assertDispatched(ChatStreamFailed::class, fn (ChatStreamFailed $e): bool => str_contains($e->message, 'rate-limited'));
});

function streamErrorException(?string $type): StreamErrorException
{
    return new StreamErrorException(
        $type === null ? null : new Error('evt-1', $type, 'provider says no', false, time()),
    );
}

it('releases the turn for a dropped provider connection and a retryable stream error', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $job = new ProcessChatMessage(
        user: $user, team: $user->currentTeam, message: 'hi', conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto', 'id' => null, 'source' => 'auto'], turnId: '01TURNGGGGGGGGGGGGGGGGGGGGG',
    );

    expect($job->isTransient(ProviderConnectionException::forProvider('anthropic')))->toBeTrue()
        ->and($job->isTransient(streamErrorException('overloaded_error')))->toBeTrue()
        ->and($job->isTransient(streamErrorException('rate_limit_error')))->toBeTrue()
        ->and($job->isTransient(httpClientException(429)))->toBeTrue()
        ->and($job->isTransient(streamErrorException('invalid_request_error')))->toBeFalse()
        ->and($job->isTransient(streamErrorException(null)))->toBeFalse()
        ->and($job->isTransient(httpClientException(400)))->toBeFalse()
        ->and($job->isTransient(new RuntimeException('boom')))->toBeFalse()
        ->and($job->isTransient(null))->toBeFalse();
});

it('does not tell the user they were rate-limited when the provider connection dropped', function (): void {
    Event::fake([ChatStreamFailed::class]);

    $user = User::factory()->withPersonalTeam()->create();
    seedRateLimitConversation('c-1', $user);
    $job = new ProcessChatMessage(
        user: $user, team: $user->currentTeam, message: 'hi', conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto', 'id' => null, 'source' => 'auto'], turnId: '01TURNHHHHHHHHHHHHHHHHHHHHH',
    );

    $job->failed(ProviderConnectionException::forProvider('anthropic'));

    Event::assertDispatched(
        ChatStreamFailed::class,
        fn (ChatStreamFailed $e): bool => ! str_contains($e->message, 'rate-limited'),
    );
});
