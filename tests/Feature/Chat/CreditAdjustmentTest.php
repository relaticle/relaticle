<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(CreditService::class);

it('grants credits and writes a positive ledger entry', function (): void {
    $workspace = Workspace::factory()->create();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();
    AiCreditTransaction::query()->where('workspace_id', $workspace->getKey())->delete();
    AiCreditBalance::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 50,
        'credits_used' => 100,
    ]);
    $admin = SystemAdministrator::factory()->create();

    app(CreditService::class)->adjust($workspace, 25, 'support credit', $admin->getKey());

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();
    expect($balance->credits_remaining)->toBe(75)
        ->and($balance->credits_used)->toBe(100);

    $tx = AiCreditTransaction::query()->where('workspace_id', $workspace->getKey())->sole();
    expect($tx->type)->toBe(AiCreditType::Adjustment)
        ->and($tx->credits_charged)->toBe(25)
        ->and($tx->model)->toBe('sysadmin')
        ->and($tx->user_id)->toBeNull()
        ->and($tx->metadata['delta'])->toBe(25)
        ->and($tx->metadata['reason'])->toBe('support credit')
        ->and($tx->metadata['sysadmin_id'])->toBe($admin->getKey());
});

it('revokes credits without dropping balance below zero, keeping the period spend meter intact', function (): void {
    $workspace = Workspace::factory()->create();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();
    AiCreditTransaction::query()->where('workspace_id', $workspace->getKey())->delete();
    AiCreditBalance::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 10,
        'credits_used' => 90,
    ]);
    $admin = SystemAdministrator::factory()->create();

    app(CreditService::class)->adjust($workspace, -50, 'fraud chargeback', $admin->getKey());

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();
    expect($balance->credits_remaining)->toBe(0)
        ->and($balance->credits_used)->toBe(90);

    $tx = AiCreditTransaction::query()->where('workspace_id', $workspace->getKey())->sole();
    expect($tx->credits_charged)->toBe(50)
        ->and($tx->metadata['delta'])->toBe(-50);
});

it('creates a balance row if the workspace has none yet', function (): void {
    $workspace = Workspace::factory()->create();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();
    AiCreditTransaction::query()->where('workspace_id', $workspace->getKey())->delete();
    $admin = SystemAdministrator::factory()->create();

    app(CreditService::class)->adjust($workspace, 100, 'initial seed', $admin->getKey());

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();
    expect($balance->credits_remaining)->toBe(100)
        ->and($balance->credits_used)->toBe(0);
});

it('clamps purchased credits when a revoke shrinks the balance below them', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $service = app(CreditService::class);
    $service->addPurchasedCredits($workspace, 200, 'pack-adjust-clamp');

    // Balance: 300 allowance + 200 purchased = 500. Revoke 400 -> 100 remaining, purchased clamps to 100.
    $service->adjust($workspace, -400, 'clamp test', 'sysadmin-1');

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();
    expect($balance->credits_remaining)->toBe(100)
        ->and($balance->purchased_credits)->toBe(100);
});
