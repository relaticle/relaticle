<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing;
use App\Models\User;
use App\Services\SelfHostedEntitlements;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Entitlements\PlanEntitlements;

mutates(SelfHostedEntitlements::class, PlanEntitlements::class);

beforeEach(function (): void {
    Queue::fake();
    Cache::flush();
});

/** @return Collection<int, int> */
function sendChatMessages(User $user, int $count): Collection
{
    $workspace = $user->currentWorkspace;
    RateLimiter::clear('chat-send:'.$workspace->getKey());

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ];

    return collect(range(1, $count))
        ->map(fn (): int => test()->actingAs($user)->postJson("/chat/{$conversationId}", $payload)->status());
}

it('rejects an 11th request from a Free user within a minute with a 429', function (): void {
    Feature::define(Billing::class, true);
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['hosted_free_grandfathered_at' => now()])->save();
    expect($workspace->plan)->toBe(Plan::Free);
    RateLimiter::clear('chat-send:'.$workspace->getKey());

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ];

    for ($i = 0; $i < 10; $i++) {
        $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", $payload);
        expect($response->status())->not->toBe(429);
    }

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", $payload);
    $response->assertStatus(429);
    $response->assertJsonStructure(['error', 'message', 'retry_after_seconds', 'plan']);
    expect($response->json('error'))->toBe('rate_limited');
    expect($response->json('plan'))->toBe('free');
    expect($response->json('message'))->toContain('send again in');
    expect($response->json('retry_after_seconds'))->toBeGreaterThan(0);
});

it('isolates rate limits per workspace: different workspaces do not share the bucket', function (): void {
    Feature::define(Billing::class, true);
    // Workspace A
    $userA = User::factory()->withPersonalWorkspace()->create();
    $workspaceA = $userA->currentWorkspace;
    $workspaceA->forceFill(['hosted_free_grandfathered_at' => now()])->save();
    RateLimiter::clear('chat-send:'.$workspaceA->getKey());

    $convA = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $convA,
        'participant_type' => 'user',
        'participant_id' => (string) $userA->getKey(),
        'workspace_id' => $workspaceA->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ];

    // Burn workspace A's free-tier limit (10/min)
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($userA)->postJson("/chat/{$convA}", $payload);
    }

    // 11th request, rate limited
    expect(
        $this->actingAs($userA)->postJson("/chat/{$convA}", $payload)->status()
    )->toBe(429);

    // Workspace B (separate user, separate workspace)
    $userB = User::factory()->withPersonalWorkspace()->create();
    $workspaceB = $userB->currentWorkspace;
    $workspaceB->forceFill(['hosted_free_grandfathered_at' => now()])->save();
    RateLimiter::clear('chat-send:'.$workspaceB->getKey());

    $convB = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $convB,
        'participant_type' => 'user',
        'participant_id' => (string) $userB->getKey(),
        'workspace_id' => $workspaceB->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Workspace B's first request, which should NOT be rate-limited
    expect(
        $this->actingAs($userB)->postJson("/chat/{$convB}", $payload)->status()
    )->not->toBe(429);
});

it('allows Pro users 30 requests per minute', function (): void {
    Feature::define(Billing::class, true);
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->plan = Plan::Pro;
    $workspace->hosted_free_grandfathered_at = now();
    $workspace->save();
    RateLimiter::clear('chat-send:'.$workspace->getKey());

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ];

    // Pro plan = 30/min, so the 11th request should NOT be 429
    for ($i = 0; $i < 11; $i++) {
        $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", $payload);
        expect($response->status())->not->toBe(429);
    }
});

it('accepts 60 sends a minute on a self-hosted install and rejects the 61st', function (): void {
    Feature::define(Billing::class, false);
    $user = User::factory()->withPersonalWorkspace()->create();

    $statuses = sendChatMessages($user, 61);

    expect($statuses->take(60)->unique()->all())->not->toContain(429)
        ->and($statuses->last())->toBe(429);
});

it('applies no limit on a self-hosted install when the configured limit is zero', function (): void {
    Feature::define(Billing::class, false);
    config()->set('chat.rate_limit_per_minute', 0);
    $user = User::factory()->withPersonalWorkspace()->create();

    $statuses = sendChatMessages($user, 15);

    expect($statuses->every(fn (int $status): bool => $status < 300))->toBeTrue();
});

it('reads the self-hosted limit from config', function (): void {
    Feature::define(Billing::class, false);
    config()->set('chat.rate_limit_per_minute', 3);
    $user = User::factory()->withPersonalWorkspace()->create();

    $statuses = sendChatMessages($user, 4);

    expect($statuses->take(3)->every(fn (int $status): bool => $status < 300))->toBeTrue()
        ->and($statuses->last())->toBe(429);
});

function chatRateLimitFromEnv(string $value): mixed
{
    $previous = $_SERVER['CHAT_RATE_LIMIT_PER_MINUTE'] ?? null;
    $_SERVER['CHAT_RATE_LIMIT_PER_MINUTE'] = $value;

    try {
        return (require base_path('packages/Chat/config/chat.php'))['rate_limit_per_minute'];
    } finally {
        if ($previous === null) {
            unset($_SERVER['CHAT_RATE_LIMIT_PER_MINUTE']);
        } else {
            $_SERVER['CHAT_RATE_LIMIT_PER_MINUTE'] = $previous;
        }
    }
}

it('keeps 60 sends a minute on a self-hosted install when the configured limit is not a number', function (string $value): void {
    Feature::define(Billing::class, false);
    config()->set('chat.rate_limit_per_minute', chatRateLimitFromEnv($value));
    $user = User::factory()->withPersonalWorkspace()->create();

    $statuses = sendChatMessages($user, 61);

    expect($statuses->take(60)->unique()->all())->not->toContain(429)
        ->and($statuses->last())->toBe(429);
})->with(['empty' => '', 'misspelled' => 'sixty']);

it('reads an explicit zero or a number from the environment as the limit', function (string $value, int $expected): void {
    expect(chatRateLimitFromEnv($value))->toBe($expected);
})->with(['zero' => ['0', 0], 'thirty' => ['30', 30]]);
