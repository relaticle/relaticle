<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Queue;
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

it('the title set at conversation creation time persists across re-fetches', function (): void {
    Queue::fake();

    $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Show me my recent companies please'),
    ])->assertOk();

    $conversation = AgentConversation::query()->latest('created_at')->first();
    expect($conversation->title)->toBe('Show me my recent companies please');

    $refetched = AgentConversation::query()->find($conversation->id);
    expect($refetched->title)->toBe('Show me my recent companies please');
});
