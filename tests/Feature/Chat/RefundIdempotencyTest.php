<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

it('does not double-refund when failed() runs after a cancel-path refund', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'team_id' => $team->getKey(),
        'credits_remaining' => 10,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $service = app(CreditService::class);
    expect($service->reserveCredit($team))->toBeTrue();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();
    expect($balance->credits_remaining)->toBe(9);

    $cancelToken = 'job-test-token';

    $service->refundReservation($team, resolutionKey: $cancelToken);

    $balance->refresh();
    expect($balance->credits_remaining)->toBe(10);

    $service->refundReservation($team, resolutionKey: $cancelToken);

    $balance->refresh();
    expect($balance->credits_remaining)->toBe(10);
});
