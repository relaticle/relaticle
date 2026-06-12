<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AiCreditBalance;
use Tests\Helpers\ChatDocument;

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

    $this->conversationId = (string) Str::uuid7();
    AgentConversation::query()->insert([
        'id' => $this->conversationId,
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('accepts a document field and dispatches with derived text and mentions', function (): void {
    Queue::fake();
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Tell me about '],
                ['type' => 'mention', 'attrs' => [
                    'type' => 'company',
                    'id' => $company->getKey(),
                    'label' => 'Acme',
                ]],
            ],
        ]],
    ];

    $this->postJson(route('chat.send'), [
        'conversation_id' => $this->conversationId,
        'document' => $document,
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => $job->message === 'Tell me about Acme'
        && count($job->mentions) === 1
        && $job->mentions[0]['id'] === $company->getKey()
        && $job->document === $document);
});

it('returns 422 when the document field is missing', function (): void {
    $this->postJson(route('chat.send'), [
        'conversation_id' => $this->conversationId,
    ])->assertStatus(422);
});

it('returns 422 when the document is empty (no text content)', function (): void {
    $this->postJson(route('chat.send'), [
        'conversation_id' => $this->conversationId,
        'document' => ['type' => 'doc', 'content' => []],
    ])->assertStatus(422);
});

it('returns 422 when the parsed text exceeds 5000 characters', function (): void {
    $longText = str_repeat('a', 5001);

    $this->postJson(route('chat.send'), [
        'conversation_id' => $this->conversationId,
        'document' => ChatDocument::fromText($longText),
    ])->assertStatus(422)
        ->assertJsonPath('errors.document.0', 'Message is too long.');
});

it('silently drops mention nodes whose ID does not belong to the current team', function (): void {
    Queue::fake();
    $foreignTeam = User::factory()->withPersonalTeam()->create()->currentTeam;
    $foreignCompany = Company::factory()->for($foreignTeam)->create(['name' => 'Foreign']);

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Hi '],
                ['type' => 'mention', 'attrs' => [
                    'type' => 'company',
                    'id' => $foreignCompany->getKey(),
                    'label' => 'Foreign',
                ]],
            ],
        ]],
    ];

    $this->postJson(route('chat.send'), [
        'conversation_id' => $this->conversationId,
        'document' => $document,
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => $job->mentions === []);
});
