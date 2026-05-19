<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;

it('allows the same idempotency key in two different teams', function (): void {
    $userA = User::factory()->withPersonalTeam()->create();
    $userB = User::factory()->withPersonalTeam()->create();

    foreach ([$userA, $userB] as $user) {
        AiCreditBalance::query()->updateOrCreate(['team_id' => $user->currentTeam->getKey()], [
            'team_id' => $user->currentTeam->getKey(),
            'credits_remaining' => 10,
            'credits_used' => 0,
            'period_starts_at' => now()->startOfMonth(),
            'period_ends_at' => now()->endOfMonth(),
        ]);
    }

    $service = app(CreditService::class);

    $service->settleReservation(
        team: $userA->currentTeam, user: $userA, type: AiCreditType::Chat,
        model: 'claude-sonnet-4-6', inputTokens: 0, outputTokens: 0,
        idempotencyKey: 'shared-key',
    );
    $service->settleReservation(
        team: $userB->currentTeam, user: $userB, type: AiCreditType::Chat,
        model: 'claude-sonnet-4-6', inputTokens: 0, outputTokens: 0,
        idempotencyKey: 'shared-key',
    );

    expect(AiCreditTransaction::query()->where('idempotency_key', 'shared-key')->count())->toBe(2);
});
