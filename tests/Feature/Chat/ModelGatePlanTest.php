<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing;
use App\Models\User;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Support\ChatLocale;
use Tests\Helpers\ChatDocument;

mutates(ChatController::class, ChatLocale::class);

it('rejects an Opus request from a grandfathered Free user with a 403', function (): void {
    Feature::define(Billing::class, true);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['hosted_free_grandfathered_at' => now()])->save();
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
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ChatDocument::fromText('hi'),
        'model' => 'claude-opus-5',
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
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ChatDocument::fromText('hi'),
        'model' => 'claude-opus-5',
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
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ChatDocument::fromText('hi'),
        // no model, so it defaults to Auto via resolver
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
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ChatDocument::fromText('hi'),
        'model' => 'claude-sonnet-5',
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
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ChatDocument::fromText('hi'),
        'model' => 'gpt-5.5',
    ]);

    $response->assertStatus(403);
    expect($response->json('error'))->toBe('model_not_allowed');
    expect($response->json('requested_model'))->toBe('gpt-5.5');
});

it('renders the model-gate message in the user\'s locale and restores English after', function (): void {
    $directory = sys_get_temp_dir().'/chat-locale-test-'.Str::random(8);
    mkdir($directory);
    file_put_contents($directory.'/da.json', json_encode([
        ':model is not available on the :plan plan.' => ':model er ikke tilgaengelig paa :plan planen.',
    ], JSON_THROW_ON_ERROR));
    resolve(Translator::class)->addJsonPath($directory);

    $user = User::factory()->withPersonalTeam()->create(['locale' => 'da']);
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
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ChatDocument::fromText('hi'),
        'model' => 'gpt-5.5',
    ]);

    $response->assertStatus(403);
    expect($response->json('message'))->toContain('ikke tilgaengelig')
        ->and(app()->getLocale())->toBe('en');
});

it('allows a Free user to pick Ollama when it is configured', function (): void {
    Queue::fake();
    config()->set('chat.ollama.model', 'qwen3:14b');
    app()->forgetInstance(ModelRegistry::class);

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
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ChatDocument::fromText('hi'),
        'model' => 'ollama',
    ]);

    $response->assertStatus(200);
});

it('rejects an unknown model id with a 422', function (): void {
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
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ChatDocument::fromText('hi'),
        'model' => 'not-a-real-model',
    ])->assertStatus(422);
});
