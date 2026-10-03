<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\Plan;
use App\Features\Billing;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\ModelAccess;
use Relaticle\Chat\Services\ModelRegistry;
use Tests\Helpers\ChatCatalog;
use Tests\Helpers\ChatDocument;

mutates(ChatController::class, ModelAccess::class);

it('rejects an Opus request from a grandfathered Free user with a 403', function (): void {
    Feature::define(Billing::class, true);

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['hosted_free_grandfathered_at' => now()])->save();
    expect($workspace->plan)->toBe(Plan::Free);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
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
        'workspace_id' => $workspace->getKey(),
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

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->plan = Plan::Pro;
    $workspace->save();

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
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
        'workspace_id' => $workspace->getKey(),
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

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    expect($workspace->plan)->toBe(Plan::Free);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
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
        'workspace_id' => $workspace->getKey(),
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

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    expect($workspace->plan)->toBe(Plan::Free);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
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
        'workspace_id' => $workspace->getKey(),
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
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
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
        'workspace_id' => $workspace->getKey(),
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

it('allows a Free user to pick Ollama when it is configured', function (): void {
    Queue::fake();
    config()->set('chat.ollama.model', 'qwen3:14b');
    app()->forgetInstance(ModelRegistry::class);

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
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
        'workspace_id' => $workspace->getKey(),
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
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
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
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ChatDocument::fromText('hi'),
        'model' => 'not-a-real-model',
    ])->assertStatus(422);
});

function startTrial(Workspace $workspace): void
{
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(14)])->save();

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
}

function seedGateConversation(User $user): string
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $conversationId;
}

function useProFirstAutoChain(): void
{
    config()->set('chat.models', [
        ChatCatalog::entry(['label' => 'Opus 5', 'model' => 'claude-opus-5', 'min_plan' => 'pro', 'credit_multiplier' => 3.0]),
        ChatCatalog::entry(),
    ]);
    app()->forgetInstance(ModelRegistry::class);
}

it('refuses a premium model during a trial while the workspace holds only sample data', function (): void {
    Queue::fake();
    $user = User::factory()->withPersonalWorkspace()->create();
    startTrial($user->currentWorkspace);
    Company::factory()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'creation_source' => CreationSource::SYSTEM,
    ]);

    $response = $this->actingAs($user)->postJson('/chat/'.seedGateConversation($user), [
        'document' => ChatDocument::fromText('hi'),
        'model' => 'claude-opus-5',
    ]);

    $response->assertStatus(403)->assertJson([
        'error' => 'model_not_allowed',
        'message' => 'Add your own records to unlock premium models during your trial.',
        'upgrade_available' => false,
        'upgrade_url' => null,
    ]);
    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('keeps the plan refusal for an Enterprise-only model during a locked trial', function (): void {
    Queue::fake();
    $user = User::factory()->withPersonalWorkspace()->create();
    config()->set('chat.models', [
        ChatCatalog::entry(),
        ChatCatalog::entry(['label' => 'Opus 5', 'model' => 'claude-opus-5', 'min_plan' => 'enterprise']),
    ]);
    app()->forgetInstance(ModelRegistry::class);
    startTrial($user->currentWorkspace);

    $response = $this->actingAs($user)->postJson('/chat/'.seedGateConversation($user), [
        'document' => ChatDocument::fromText('hi'),
        'model' => 'claude-opus-5',
    ]);

    $response->assertStatus(403)->assertJson([
        'error' => 'model_not_allowed',
        'message' => 'Opus 5 is not available on the Pro plan.',
    ]);
    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('unlocks premium models once the trial workspace creates its own record through chat', function (): void {
    Queue::fake();
    $user = User::factory()->withPersonalWorkspace()->create();
    startTrial($user->currentWorkspace);
    Company::factory()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'creation_source' => CreationSource::CHAT,
    ]);

    $this->actingAs($user)->postJson('/chat/'.seedGateConversation($user), [
        'document' => ChatDocument::fromText('hi'),
        'model' => 'claude-opus-5',
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class);
});

it('runs Auto on a free model for a locked trial even when Auto would pick a premium one', function (): void {
    Queue::fake();
    $user = User::factory()->withPersonalWorkspace()->create();
    useProFirstAutoChain();
    startTrial($user->currentWorkspace);

    $this->actingAs($user)->postJson('/chat/'.seedGateConversation($user), [
        'document' => ChatDocument::fromText('hi'),
    ])->assertOk();

    Queue::assertPushed(
        ProcessChatMessage::class,
        fn (ProcessChatMessage $job): bool => (fn (): array => $this->resolved)->call($job)['id'] === 'claude-sonnet-5',
    );
});

it('falls back to Auto when a locked trial user saved a premium model as their default', function (): void {
    Queue::fake();
    $user = User::factory()->withPersonalWorkspace()->create(['ai_preferences' => ['default_model' => 'claude-opus-5']]);
    useProFirstAutoChain();
    startTrial($user->currentWorkspace);

    $this->actingAs($user)->postJson('/chat/'.seedGateConversation($user), [
        'document' => ChatDocument::fromText('hi'),
    ])->assertOk();

    Queue::assertPushed(
        ProcessChatMessage::class,
        fn (ProcessChatMessage $job): bool => (fn (): array => $this->resolved)->call($job)['id'] === 'claude-sonnet-5',
    );
});

it('keeps Auto on the premium model for a paid Pro workspace', function (): void {
    Queue::fake();
    $user = User::factory()->withPersonalWorkspace()->create();
    useProFirstAutoChain();
    $user->currentWorkspace->forceFill(['plan' => Plan::Pro])->save();
    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $user->currentWorkspace->getKey()], [
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $this->actingAs($user)->postJson('/chat/'.seedGateConversation($user), [
        'document' => ChatDocument::fromText('hi'),
    ])->assertOk();

    Queue::assertPushed(
        ProcessChatMessage::class,
        fn (ProcessChatMessage $job): bool => (fn (): array => $this->resolved)->call($job)['id'] === 'claude-opus-5',
    );
});
