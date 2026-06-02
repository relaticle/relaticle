<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;

function seedConversation(User $user, string $conversationId): void
{
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('broadcasts a stream.failed event when the job fails', function (): void {
    Event::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    seedConversation($user, 'conv-123');

    $job = new ProcessChatMessage(
        user: $user,
        team: $team,
        message: 'hello',
        conversationId: 'conv-123',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
    );

    $job->failed(new RuntimeException('boom'));

    Event::assertDispatched(ChatStreamFailed::class, function (ChatStreamFailed $event) {
        return $event->conversationId === 'conv-123';
    });
});

it('settles the reserved minimum (not refund) when the job fails', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    seedConversation($user, 'conv-123');

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'team_id' => $team->getKey(),
        'credits_remaining' => 99,
        'credits_used' => 1,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $job = new ProcessChatMessage(
        user: $user,
        team: $team,
        message: 'hello',
        conversationId: 'conv-123',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
    );

    $job->failed(new RuntimeException('boom'));

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();
    expect($balance->credits_used)->toBe(1)
        ->and($balance->credits_remaining)->toBe(99);
});

it('binds auth context so tool classes can resolve the current user', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    Auth::guard('web')->setUser($user);
    expect(Auth::guard('web')->user()?->getKey())->toBe($user->getKey());
});
