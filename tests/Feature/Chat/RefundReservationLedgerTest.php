<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;

it('writes a Refund transaction row when refundReservation is called', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $service = app(CreditService::class);

    $service->reserveCredit($team);
    $service->refundReservation($team, idempotencyToken: 'job-abc');

    $refund = AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('type', AiCreditType::Refund)
        ->first();

    expect($refund)->not->toBeNull()
        ->and($refund->credits_charged)->toBe(1)
        ->and($refund->metadata['idempotency_token'])->toBe('job-abc');
});

it('refund is idempotent on the ledger — second call writes no extra row', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $service = app(CreditService::class);

    $service->reserveCredit($team);
    $service->refundReservation($team, idempotencyToken: 'job-xyz');
    $service->refundReservation($team, idempotencyToken: 'job-xyz');

    expect(
        AiCreditTransaction::query()
            ->where('team_id', $team->getKey())
            ->where('type', AiCreditType::Refund)
            ->count()
    )->toBe(1);
});

it('balance and ledger stay consistent after a reserve + refund cycle', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $service = app(CreditService::class);
    $startBalance = (int) AiCreditBalance::query()->where('team_id', $team->getKey())->value('credits_remaining');

    $service->reserveCredit($team);
    $service->refundReservation($team, idempotencyToken: 'job-1');

    $endBalance = (int) AiCreditBalance::query()->where('team_id', $team->getKey())->value('credits_remaining');
    expect($endBalance)->toBe($startBalance);

    $refunds = (int) AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('type', AiCreditType::Refund)
        ->sum('credits_charged');

    expect($refunds)->toBe(1);
});
