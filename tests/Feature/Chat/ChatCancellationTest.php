<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Tests\Helpers\ChatDocument;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

mutates(ChatController::class);

it('marks a conversation as cancelled when cancel endpoint hit', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cacheKey = "chat:cancel:{$conversationId}";

    expect(Cache::has($cacheKey))->toBeFalse();

    actingAs($user);
    postJson(route('chat.cancel', ['conversationId' => $conversationId]))
        ->assertOk()
        ->assertJson(['cancelled' => true]);

    expect(Cache::get($cacheKey))->toBe((string) $user->getKey());
});

it('returns 404 when another user tries to cancel a conversation', function (): void {
    $userA = User::factory()->withPersonalWorkspace()->create();
    $userB = User::factory()->withPersonalWorkspace()->create();
    $workspaceA = $userA->currentWorkspace;

    AiCreditBalance::updateOrCreate(
        ['workspace_id' => $workspaceA->getKey()],
        ['credits_remaining' => 100, 'period_ends_at' => now()->addMonth()],
    );

    actingAs($userA);
    $response = postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('hi'),
    ])->assertOk();

    $conversationId = $response->json('conversation_id');

    actingAs($userB);
    postJson(route('chat.cancel', ['conversationId' => $conversationId]))
        ->assertNotFound();

    expect(Cache::has("chat:cancel:{$conversationId}"))->toBeFalse();
});

it('returns 404 when a teammate (same workspace, different user) tries to cancel another user\'s conversation', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $teammate = User::factory()->create();
    $workspace->users()->attach($teammate, ['role' => 'editor']);
    $teammate->switchWorkspace($workspace);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    actingAs($owner);
    $conversationId = postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('hi'),
    ])->assertOk()->json('conversation_id');

    actingAs($teammate);
    postJson(route('chat.cancel', ['conversationId' => $conversationId]))
        ->assertNotFound();

    expect(Cache::has("chat:cancel:{$conversationId}"))->toBeFalse();
});

it('returns 401 for unauthenticated cancel', function (): void {
    postJson(route('chat.cancel', ['conversationId' => 'x']))
        ->assertUnauthorized();
});

it('settles the reserved minimum (not refund) when a stream is cancelled mid-flight', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    actingAs($user);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    resolve(CreditService::class)->reserveCredit($workspace);

    Cache::put("chat:cancel:{$conversationId}", (string) $user->getKey());

    CrmAssistant::fake(['Some streamed answer.']);

    new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'Show me my deals',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
        turnId: '01TURNCANCELAAAAAAAAAAAAAA',
    )->handle(resolve(CreditService::class));

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();
    expect($balance->credits_used)->toBe(1)
        ->and($balance->credits_remaining)->toBe(99);
});
