<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AiCreditBalance;
use Tests\Helpers\ChatDocument;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

mutates(ChatController::class);

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

it('creates a conversation row and returns the new id WITHOUT dispatching a job', function (): void {
    Queue::fake();

    $response = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Hello world'),
    ])->assertOk()->assertJsonStructure(['conversation_id']);

    $conversationId = $response->json('conversation_id');
    expect($conversationId)->toBeString()->not->toBeEmpty();

    expect(DB::table('agent_conversations')->where('id', $conversationId)->exists())->toBeTrue();
    expect(AgentConversation::query()->find($conversationId)->title)->toBe('Hello world');

    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('dispatches the chat job on chat.send for the first message of a fresh conversation', function (): void {
    Queue::fake();

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Hello world'),
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    Queue::assertNotPushed(ProcessChatMessage::class);

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('Hello world'),
    ])->assertOk();

    Queue::assertPushed(
        ProcessChatMessage::class,
        fn (ProcessChatMessage $job): bool => $job->conversationId === $conversationId
            && $job->message === 'Hello world',
    );
});

it('returns 422 when document is missing', function (): void {
    $this->postJson(route('chat.conversations.create'), [])->assertStatus(422);
});

it('returns 422 when document text is empty', function (): void {
    $this->postJson(route('chat.conversations.create'), [
        'document' => ['type' => 'doc', 'content' => []],
    ])->assertStatus(422);
});

it('returns 402 on send when credit balance is insufficient', function (): void {
    Queue::fake();

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Hi'),
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    AiCreditBalance::query()->where('team_id', $this->team->getKey())->update([
        'credits_remaining' => 0,
    ]);

    $response = $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('Hi'),
    ])->assertStatus(402);

    expect($response->json('error'))->toBe('credits_exhausted');

    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('filters cross-tenant mention IDs from the document before persisting on chat.send', function (): void {
    Queue::fake();
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignCompany = Company::factory()->for($otherTeam)->create(['name' => 'Foreign']);

    $document = ChatDocument::fromText('Hi ', [
        ['type' => 'company', 'id' => $foreignCompany->getKey(), 'label' => 'Foreign'],
    ]);

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => $document,
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    Queue::assertNotPushed(ProcessChatMessage::class);

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => $document,
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class, function ($job): bool {
        return $job->mentions === [];
    });
});

it('returns 422 when document text exceeds 5000 characters', function (): void {
    $longText = str_repeat('a', 5001);

    $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText($longText),
    ])->assertStatus(422)
        ->assertJsonPath('errors.document.0', 'Message is too long.');

    expect(AgentConversation::query()->count())->toBe(0);
});

it('does not consume a credit on send when the document is empty', function (): void {
    Queue::fake();

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('seed'),
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    $balanceBefore = AiCreditBalance::query()->where('team_id', $this->team->getKey())->first();

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ['type' => 'doc', 'content' => []],
    ])->assertStatus(422);

    $balanceAfter = AiCreditBalance::query()->where('team_id', $this->team->getKey())->first();
    expect($balanceAfter->credits_remaining)->toBe($balanceBefore->credits_remaining);
});

it('does not consume a credit on send when the document text is too long', function (): void {
    Queue::fake();

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('seed'),
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    $balanceBefore = AiCreditBalance::query()->where('team_id', $this->team->getKey())->first();

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText(str_repeat('a', 5001)),
    ])->assertStatus(422);

    $balanceAfter = AiCreditBalance::query()->where('team_id', $this->team->getKey())->first();
    expect($balanceAfter->credits_remaining)->toBe($balanceBefore->credits_remaining);
});

it('does not duplicate mention labels when text already contains @Tokens on chat.send', function (): void {
    Queue::fake();
    Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Hi @Acme_Corp please'],
            ],
        ]],
    ];

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => $document,
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    Queue::assertNotPushed(ProcessChatMessage::class);

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => $document,
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class, fn ($job): bool => $job->message === 'Hi @Acme_Corp please');
});

it('returns 403 when the user has no current team', function (): void {
    $teamlessUser = User::factory()->create(['current_team_id' => null]);
    $this->actingAs($teamlessUser);

    $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Hi'),
    ])->assertStatus(403);
});

it('rate-limits createConversation per team plan', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    actingAs($user);

    AiCreditBalance::query()->updateOrCreate(['team_id' => $user->currentTeam->getKey()], [
        'team_id' => $user->currentTeam->getKey(),
        'credits_remaining' => 1000,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $limit = $user->currentTeam->plan->rateLimit();

    for ($i = 0; $i < $limit; $i++) {
        postJson(route('chat.conversations.create'), [
            'document' => ChatDocument::fromText("hi {$i}"),
        ])->assertOk();
    }

    postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('over'),
    ])->assertStatus(429);
});
