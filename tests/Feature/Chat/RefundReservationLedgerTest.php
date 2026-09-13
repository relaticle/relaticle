<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;

it('writes a Refund transaction row when refundReservation is called', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $service = app(CreditService::class);

    $service->reserveCredit($workspace);
    $service->refundReservation($workspace, resolutionKey: 'job-abc');

    $refund = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('type', AiCreditType::Refund)
        ->first();

    expect($refund)->not->toBeNull()
        ->and($refund->credits_charged)->toBe(1)
        ->and($refund->idempotency_key)->toBe('job-abc')
        ->and($refund->metadata['reason'])->toBe('reservation_refund');
});

it('refund is idempotent on the ledger: a second call writes no extra row', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $service = app(CreditService::class);

    $service->reserveCredit($workspace);
    $service->refundReservation($workspace, resolutionKey: 'job-xyz');
    $service->refundReservation($workspace, resolutionKey: 'job-xyz');

    expect(
        AiCreditTransaction::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('type', AiCreditType::Refund)
            ->count()
    )->toBe(1);
});

it('balance and ledger stay consistent after a reserve + refund cycle', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $service = app(CreditService::class);
    $startBalance = (int) AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->value('credits_remaining');

    $service->reserveCredit($workspace);
    $service->refundReservation($workspace, resolutionKey: 'job-1');

    $endBalance = (int) AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->value('credits_remaining');
    expect($endBalance)->toBe($startBalance);

    $refunds = (int) AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('type', AiCreditType::Refund)
        ->sum('credits_charged');

    expect($refunds)->toBe(1);
});
