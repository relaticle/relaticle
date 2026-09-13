<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;

it('allows the same idempotency key in two different workspaces', function (): void {
    $userA = User::factory()->withPersonalWorkspace()->create();
    $userB = User::factory()->withPersonalWorkspace()->create();

    foreach ([$userA, $userB] as $user) {
        AiCreditBalance::query()->updateOrCreate(['workspace_id' => $user->currentWorkspace->getKey()], [
            'workspace_id' => $user->currentWorkspace->getKey(),
            'credits_remaining' => 10,
            'credits_used' => 0,
            'period_starts_at' => now()->startOfMonth(),
            'period_ends_at' => now()->endOfMonth(),
        ]);
    }

    $service = app(CreditService::class);

    $service->settleReservation(
        workspace: $userA->currentWorkspace, user: $userA, type: AiCreditType::Chat,
        model: 'claude-sonnet-4-6', inputTokens: 0, outputTokens: 0,
        resolutionKey: 'shared-key',
    );
    $service->settleReservation(
        workspace: $userB->currentWorkspace, user: $userB, type: AiCreditType::Chat,
        model: 'claude-sonnet-4-6', inputTokens: 0, outputTokens: 0,
        resolutionKey: 'shared-key',
    );

    expect(AiCreditTransaction::query()->where('idempotency_key', 'shared-key')->count())->toBe(2);
});
