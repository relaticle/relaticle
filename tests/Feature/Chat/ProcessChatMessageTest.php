<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;

it('broadcasts a stream.failed event when the job fails', function (): void {
    Event::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

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

it('refunds the reserved credit when the job fails', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

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

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->value('credits_remaining'))->toBe(100);
});

it('binds auth context so tool classes can resolve the current user', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    Auth::guard('web')->setUser($user);
    expect(Auth::guard('web')->user()?->getKey())->toBe($user->getKey());
});
