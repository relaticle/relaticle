<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Queue::fake();
    Cache::flush();
});

it('rejects an 11th request from a Free user within a minute with a 429', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
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
    // Workspace A
    $userA = User::factory()->withPersonalWorkspace()->create();
    $workspaceA = $userA->currentWorkspace;
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
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->plan = Plan::Pro;
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
