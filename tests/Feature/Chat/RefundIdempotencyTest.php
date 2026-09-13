<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

it('does not double-refund when failed() runs after a cancel-path refund', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 10,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $service = app(CreditService::class);
    expect($service->reserveCredit($workspace))->toBeTrue();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();
    expect($balance->credits_remaining)->toBe(9);

    $cancelToken = 'job-test-token';

    $service->refundReservation($workspace, resolutionKey: $cancelToken);

    $balance->refresh();
    expect($balance->credits_remaining)->toBe(10);

    $service->refundReservation($workspace, resolutionKey: $cancelToken);

    $balance->refresh();
    expect($balance->credits_remaining)->toBe(10);
});
