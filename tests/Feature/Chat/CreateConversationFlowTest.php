<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;

beforeEach(function (): void {
    Queue::fake();
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);

    AiCreditBalance::query()->updateOrCreate(['team_id' => $this->user->currentTeam->getKey()], [
        'team_id' => $this->user->currentTeam->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
});

it('two-step first-message protocol: create returns id without dispatch; send to that id dispatches', function (): void {
    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'first message']]]]];

    // Step 1: create — must NOT dispatch
    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => $doc,
    ])->assertOk()->assertJsonStructure(['conversation_id']);

    $conversationId = $createRes->json('conversation_id');

    expect(DB::table('agent_conversations')->where('id', $conversationId)->exists())->toBeTrue();
    Queue::assertNotPushed(ProcessChatMessage::class);

    // Step 2: send — must dispatch exactly once for this conversation_id
    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => $doc,
    ])->assertOk();

    Queue::assertPushed(
        ProcessChatMessage::class,
        fn (ProcessChatMessage $job): bool => $job->conversationId === $conversationId,
    );
});

it('create returns 422 for an empty document without inserting a row', function (): void {
    $emptyDoc = ['type' => 'doc', 'content' => []];

    $this->postJson(route('chat.conversations.create'), [
        'document' => $emptyDoc,
    ])->assertStatus(422);

    expect(DB::table('agent_conversations')->where('user_id', (string) $this->user->getKey())->count())->toBe(0);
    Queue::assertNotPushed(ProcessChatMessage::class);
});
