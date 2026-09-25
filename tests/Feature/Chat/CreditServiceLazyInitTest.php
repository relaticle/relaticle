<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

mutates(CreditService::class);

it('lazy-creates a free balance when hasCredits is called on a workspace with no row', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();

    $service = app(CreditService::class);

    expect($service->hasCredits($workspace))->toBeTrue()
        ->and(AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->exists())->toBeTrue();
});

it('lazy-init grants the free plan allowance when the workspace has no balance row', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();

    app(CreditService::class)->hasCredits($workspace);

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->firstOrFail();
    expect($balance->credits_remaining)->toBe(Plan::Free->credits());
});

it('lazy-creates and successfully reserves a credit when reserveCredit is called on a workspace with no row', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();

    $service = app(CreditService::class);

    expect($service->reserveCredit($workspace))->toBeTrue();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->firstOrFail();
    expect($balance->credits_remaining)->toBe(Plan::Free->credits() - 1)
        ->and($balance->credits_used)->toBe(1);
});
