<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

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

it('refunds the reservation and stops when hosted access expires in the queue', function (): void {
    Feature::define(BillingFeature::class, true);
    Event::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    seedConversation($user, 'conv-paused');

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'team_id' => $team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $credits = resolve(CreditService::class);
    expect($credits->reserveCredit(
        $team,
        reservationKey: 'reserve-turn-paused',
        conversationId: 'conv-paused',
        userId: (string) $user->getKey(),
    ))->toBeTrue();

    $job = new ProcessChatMessage(
        user: $user,
        team: $team,
        message: 'hello',
        conversationId: 'conv-paused',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
        turnId: 'turn-paused',
    );

    $job->handle($credits);

    Event::assertDispatched(ChatStreamFailed::class, fn (ChatStreamFailed $event): bool => $event->conversationId === 'conv-paused'
        && $event->message === __('billing.access.paused_chat'));

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($balance->credits_remaining)->toBe(100)
        ->and($balance->credits_used)->toBe(0);
});
