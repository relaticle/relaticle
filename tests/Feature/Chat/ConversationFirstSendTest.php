<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AiCreditBalance;
use Tests\Helpers\ChatDocument;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);

    AiCreditBalance::query()->updateOrCreate(['team_id' => $this->team->getKey()], [
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
});

it('chat-interface view embeds the new chat.conversations.create route', function (): void {
    $response = $this->get("/app/{$this->team->slug}/chats");
    $response->assertOk();

    // The route is emitted via @js() inside @script @endscript, which Livewire
    // packs into a wire:effect attribute — slashes are JSON-escaped (\/) AND
    // the result lives inside an HTML attribute, so each backslash is doubled.
    $createPath = parse_url(route('chat.conversations.create'), PHP_URL_PATH);
    $escapedPath = str_replace('/', '\\\\\\/', $createPath);
    $response->assertSee($escapedPath, false);
});

it('chat-interface view does not POST {message, mentions} on subsequent sends anymore', function (): void {
    $response = $this->get("/app/{$this->team->slug}/chats");
    $response->assertOk();

    $response->assertDontSee('message: text,', false);
    $response->assertDontSee('mentions: liveMentions', false);
});

it('first-message protocol: create returns id without dispatch, send dispatches', function (): void {
    Queue::fake();

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Hello world'),
    ])->assertOk();

    $conversationId = $createRes->json('conversation_id');
    expect($conversationId)->toBeString()->not->toBeEmpty();
    expect(AgentConversation::query()->find($conversationId))->not->toBeNull();

    Queue::assertNotPushed(ProcessChatMessage::class);

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('Hello world'),
    ])->assertOk();

    Queue::assertPushed(
        ProcessChatMessage::class,
        fn (ProcessChatMessage $job): bool => $job->conversationId === $conversationId,
    );
});
