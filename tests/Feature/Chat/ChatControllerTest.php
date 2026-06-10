<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Tests\Helpers\ChatDocument;

mutates(ChatController::class);

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);

    AiCreditBalance::query()->updateOrCreate(['team_id' => $this->team->getKey()], [
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
});

it('rejects unauthenticated requests', function (): void {
    auth()->logout();

    $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('hello'),
    ])->assertUnauthorized();
});

it('returns 402 when credits are exhausted on send', function (): void {
    Queue::fake();

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('hello'),
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    AiCreditBalance::query()
        ->where('team_id', $this->team->getKey())
        ->update(['credits_remaining' => 0]);

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('hello'),
    ])
        ->assertStatus(402)
        ->assertJsonPath('error', 'credits_exhausted');

    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('validates document is required', function (): void {
    $this->postJson(route('chat.conversations.create'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('document');
});

it('rejects empty documents', function (): void {
    $this->postJson(route('chat.conversations.create'), [
        'document' => ['type' => 'doc', 'content' => []],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('document');
});

it('creates a conversation row without dispatching a chat job', function (): void {
    Queue::fake();

    $response = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('hello'),
    ]);

    $response->assertOk();
    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('dispatches a chat job when credits are available and chat.send is called', function (): void {
    Queue::fake();

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('hello'),
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    Queue::assertNotPushed(ProcessChatMessage::class);

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('hello'),
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class);
});

it('continues an existing conversation', function (): void {
    Queue::fake();

    DB::table('agent_conversations')->insert([
        'id' => 'conv-existing',
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Existing conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson(route('chat.send', ['conversation' => 'conv-existing']), [
        'document' => ChatDocument::fromText('continue please'),
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'processing']);
    Queue::assertPushed(ProcessChatMessage::class);
});

it('lists conversations for current user', function (): void {
    DB::table('agent_conversations')->insert([
        'id' => 'conv-1',
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson(route('chat.conversations'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Test conversation');
});

it('does not list conversations of other users', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();

    DB::table('agent_conversations')->insert([
        'id' => 'conv-other',
        'user_id' => $otherUser->getKey(),
        'team_id' => $otherUser->currentTeam->getKey(),
        'title' => 'Not mine',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson(route('chat.conversations'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('deletes own conversation', function (): void {
    DB::table('agent_conversations')->insert([
        'id' => 'conv-to-delete',
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Delete me',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->deleteJson(route('chat.conversations.destroy', 'conv-to-delete'))
        ->assertOk();

    $this->assertDatabaseMissing('agent_conversations', ['id' => 'conv-to-delete']);
});

it('cannot delete another user conversation', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();

    DB::table('agent_conversations')->insert([
        'id' => 'conv-other',
        'user_id' => $otherUser->getKey(),
        'team_id' => $otherUser->currentTeam->getKey(),
        'title' => 'Not yours',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->deleteJson(route('chat.conversations.destroy', 'conv-other'))
        ->assertNotFound();
});

it('rejects unknown model overrides with 422', function (): void {
    Queue::fake();

    $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('hello'),
        'model' => 'not-a-real-model',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('model');

    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('accepts known model override values on chat.send', function (): void {
    Queue::fake();

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('hello'),
        'model' => 'claude-sonnet',
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    Queue::assertNotPushed(ProcessChatMessage::class);

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('hello'),
        'model' => 'claude-sonnet',
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class);
});

it('returns the conversation id so the client can subscribe', function (): void {
    Queue::fake();

    $response = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('hello'),
    ]);

    $response->assertOk()
        ->assertJsonStructure(['conversation_id']);

    expect($response->json('conversation_id'))->toBeString();
});

it('atomically reserves a credit on send so concurrent sends cannot overspend', function (): void {
    AiCreditBalance::query()
        ->where('team_id', $this->team->getKey())
        ->update(['credits_remaining' => 1, 'credits_used' => 99]);

    Queue::fake();

    $firstConv = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('first'),
    ])->assertOk()->json('conversation_id');

    $secondConv = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('second'),
    ])->assertOk()->json('conversation_id');

    $first = $this->postJson(route('chat.send', ['conversation' => $firstConv]), [
        'document' => ChatDocument::fromText('first'),
    ]);
    $second = $this->postJson(route('chat.send', ['conversation' => $secondConv]), [
        'document' => ChatDocument::fromText('second'),
    ]);

    $first->assertOk();
    $second->assertStatus(402);

    expect(AiCreditBalance::query()->where('team_id', $this->team->getKey())->value('credits_remaining'))->toBe(0);
});

it('does not reserve a credit on send when the request fails validation', function (): void {
    Queue::fake();

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('hello'),
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ['type' => 'doc', 'content' => []],
    ])->assertUnprocessable();

    expect(AiCreditBalance::query()->where('team_id', $this->team->getKey())->value('credits_remaining'))->toBe(100);
});

it('returns reset_at and upgrade_url on 402 from send', function (): void {
    Queue::fake();

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('hi'),
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    AiCreditBalance::query()
        ->where('team_id', $this->team->getKey())
        ->update(['credits_remaining' => 0, 'period_ends_at' => now()->endOfMonth()]);

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('hi'),
    ])
        ->assertStatus(402)
        ->assertJsonStructure(['error', 'message', 'reset_at', 'upgrade_url'])
        ->assertJsonPath('error', 'credits_exhausted');
});

it('dispatches a chat job on the chat queue when chat.send is called', function (): void {
    Queue::fake();

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('hello'),
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    Queue::assertNotPushed(ProcessChatMessage::class);

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('hello'),
    ])->assertOk();

    Queue::assertPushedOn('chat', ProcessChatMessage::class);
});

it('cleans up messages when deleting a conversation', function (): void {
    DB::table('agent_conversations')->insert([
        'id' => 'conv-cleanup',
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Cleanup test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => 'msg-1',
        'conversation_id' => 'conv-cleanup',
        'user_id' => $this->user->getKey(),
        'agent' => CrmAssistant::class,
        'role' => 'user',
        'content' => 'Hello',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->deleteJson(route('chat.conversations.destroy', 'conv-cleanup'))
        ->assertOk();

    $this->assertDatabaseMissing('agent_conversation_messages', ['conversation_id' => 'conv-cleanup']);
});
