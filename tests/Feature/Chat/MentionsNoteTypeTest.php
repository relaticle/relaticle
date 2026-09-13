<?php

declare(strict_types=1);

use App\Models\Note;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Tests\Helpers\ChatDocument;

mutates(ChatController::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
    RateLimiter::clear('60|'.request()->ip());

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $this->workspace->getKey()], [
        'workspace_id' => $this->workspace->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
});

it('returns matching notes from the mentions endpoint', function (): void {
    $note = Note::factory()->for($this->workspace)->create(['title' => 'Customer feedback summary']);

    $response = $this->getJson(route('chat.mentions', ['q' => 'feedback']))->assertOk();

    $matches = collect($response->json('data'))->where('type', 'note');
    expect($matches)->toHaveCount(1);
    expect($matches->first()['id'])->toBe($note->id);
    expect($matches->first()['name'])->toBe('Customer feedback summary');
});

it('accepts a note type in the send mentions payload on chat.send', function (): void {
    Queue::fake();
    $note = Note::factory()->for($this->workspace)->create(['title' => 'Q3 retro']);

    $document = ChatDocument::fromText('Summarize ', [
        ['type' => 'note', 'id' => $note->id, 'label' => 'Q3 retro'],
    ]);

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => $document,
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    Queue::assertNotPushed(ProcessChatMessage::class);

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => $document,
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class, function (ProcessChatMessage $job) use ($note): bool {
        return count($job->mentions) === 1
            && $job->mentions[0]['type'] === 'note'
            && $job->mentions[0]['id'] === $note->id
            && $job->mentions[0]['label'] === 'Q3 retro';
    });
});

it('drops note mentions belonging to another workspace on chat.send', function (): void {
    Queue::fake();
    $otherWorkspace = Workspace::factory()->create();
    $foreignNote = Note::factory()->for($otherWorkspace)->create(['title' => 'Cross-workspace']);

    $document = ChatDocument::fromText('Tell me about ', [
        ['type' => 'note', 'id' => $foreignNote->id, 'label' => 'Cross-workspace'],
    ]);

    $createRes = $this->postJson(route('chat.conversations.create'), [
        'document' => $document,
    ])->assertOk();
    $conversationId = $createRes->json('conversation_id');

    Queue::assertNotPushed(ProcessChatMessage::class);

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => $document,
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class, function (ProcessChatMessage $job): bool {
        return $job->mentions === [];
    });
});
