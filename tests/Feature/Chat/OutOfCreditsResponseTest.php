<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\CreditService;

beforeEach(function (): void {
    Queue::fake();
});

it('returns plan-aware copy when a Free user is out of credits', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'team_id' => $team->getKey(),
        'credits_remaining' => 0,
        'credits_used' => Plan::Free->credits(),
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ]);

    $response->assertStatus(402);
    $response->assertJsonStructure([
        'error',
        'message',
        'plan',
        'allowance',
        'reset_at',
        'upgrade_available',
    ]);
    expect($response->json('error'))->toBe('credits_exhausted');
    expect($response->json('plan'))->toBe('free');
    expect($response->json('allowance'))->toBe(Plan::Free->credits());
    expect($response->json('upgrade_available'))->toBeTrue();
});

it('marks upgrade_available false for Pro users', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->plan = Plan::Pro;
    $team->save();

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'team_id' => $team->getKey(),
        'credits_remaining' => 0,
        'credits_used' => Plan::Pro->credits(),
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ]);

    $response->assertStatus(402);
    expect($response->json('plan'))->toBe('pro');
    expect($response->json('allowance'))->toBe(Plan::Pro->credits());
    expect($response->json('upgrade_available'))->toBeFalse();
});

it('marks upgrade_available false for Enterprise users', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->plan = Plan::Enterprise;
    $team->save();

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'team_id' => $team->getKey(),
        'credits_remaining' => 0,
        'credits_used' => Plan::Enterprise->credits(),
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ]);

    $response->assertStatus(402);
    expect($response->json('plan'))->toBe('enterprise');
    expect($response->json('upgrade_available'))->toBeFalse();
});

it('mentions the plan name in the message', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'team_id' => $team->getKey(),
        'credits_remaining' => 0,
        'credits_used' => Plan::Free->credits(),
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ]],
    ]);

    $response->assertStatus(402);
    expect($response->json('message'))->toContain('Free');
    expect($response->json('message'))->toContain((string) Plan::Free->credits());
});

it('broadcasts a stream.failed terminal event when a continuation is out of credits', function (): void {
    Event::fake([ChatStreamFailed::class]);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'team_id' => $team->getKey(),
        'credits_remaining' => 0,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new ContinueChatMessage(
        user: $user,
        team: $team,
        conversationId: $conversationId,
        prompt: '[approval]',
        turnId: '01T',
    ))->handle(
        resolve(CreditService::class),
        resolve(AiModelResolver::class),
    );

    Event::assertDispatched(ChatStreamFailed::class, fn (ChatStreamFailed $e): bool => $e->conversationId === $conversationId
        && str_contains(strtolower($e->message), 'credit')
        && str_contains(strtolower($e->message), 'saved'));
});
