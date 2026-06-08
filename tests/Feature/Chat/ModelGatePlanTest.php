<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Models\AiCreditBalance;
use Tests\Helpers\ChatDocument;

mutates(ChatController::class);

it('rejects an Opus request from a Free user with a 403', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    expect($team->plan)->toBe(Plan::Free);

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'credits_remaining' => 100,
        'credits_used' => 0,
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
        'document' => ChatDocument::fromText('hi'),
        'model' => 'claude-opus',
    ]);

    $response->assertStatus(403);
    $response->assertJson([
        'error' => 'model_not_allowed',
        'plan' => 'free',
    ]);
    expect($response->json('upgrade_available'))->toBeTrue();
    expect($response->json('upgrade_url'))->toBeString();
});

it('allows an Opus request from a Pro user', function (): void {
    Queue::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->plan = Plan::Pro;
    $team->save();

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'credits_remaining' => 100,
        'credits_used' => 0,
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
        'document' => ChatDocument::fromText('hi'),
        'model' => 'claude-opus',
    ]);

    $response->assertStatus(200);
});

it('allows a Free user to send with no explicit model (defaults to Auto)', function (): void {
    Queue::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    expect($team->plan)->toBe(Plan::Free);

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'credits_remaining' => 100,
        'credits_used' => 0,
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
        'document' => ChatDocument::fromText('hi'),
        // no model — defaults to Auto via resolver
    ]);

    $response->assertStatus(200);
});

it('allows a Free user to explicitly pick Sonnet', function (): void {
    Queue::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    expect($team->plan)->toBe(Plan::Free);

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'credits_remaining' => 100,
        'credits_used' => 0,
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
        'document' => ChatDocument::fromText('hi'),
        'model' => 'claude-sonnet',
    ]);

    $response->assertStatus(200);
});

it('rejects a GPT-5 request from a Free user with a 403', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'credits_remaining' => 100,
        'credits_used' => 0,
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
        'document' => ChatDocument::fromText('hi'),
        'model' => 'gpt-5-5',
    ]);

    $response->assertStatus(403);
    expect($response->json('error'))->toBe('model_not_allowed');
    expect($response->json('requested_model'))->toBe('gpt-5-5');
});
