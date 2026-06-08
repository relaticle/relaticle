<?php

declare(strict_types=1);

use App\Models\Team;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(CreditService::class);

it('grants credits and writes a positive ledger entry', function (): void {
    $team = Team::factory()->create();
    AiCreditBalance::query()->where('team_id', $team->getKey())->delete();
    AiCreditTransaction::query()->where('team_id', $team->getKey())->delete();
    AiCreditBalance::factory()->create([
        'team_id' => $team->getKey(),
        'credits_remaining' => 50,
        'credits_used' => 100,
    ]);
    $admin = SystemAdministrator::factory()->create();

    app(CreditService::class)->adjust($team, 25, 'support credit', $admin->getKey());

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->credits_remaining)->toBe(75)
        ->and($balance->credits_used)->toBe(100);

    $tx = AiCreditTransaction::query()->where('team_id', $team->getKey())->sole();
    expect($tx->type)->toBe(AiCreditType::Adjustment)
        ->and($tx->credits_charged)->toBe(25)
        ->and($tx->model)->toBe('sysadmin')
        ->and($tx->user_id)->toBeNull()
        ->and($tx->metadata['delta'])->toBe(25)
        ->and($tx->metadata['reason'])->toBe('support credit')
        ->and($tx->metadata['sysadmin_id'])->toBe($admin->getKey());
});

it('revokes credits without dropping balance below zero, keeping the period spend meter intact', function (): void {
    $team = Team::factory()->create();
    AiCreditBalance::query()->where('team_id', $team->getKey())->delete();
    AiCreditTransaction::query()->where('team_id', $team->getKey())->delete();
    AiCreditBalance::factory()->create([
        'team_id' => $team->getKey(),
        'credits_remaining' => 10,
        'credits_used' => 90,
    ]);
    $admin = SystemAdministrator::factory()->create();

    app(CreditService::class)->adjust($team, -50, 'fraud chargeback', $admin->getKey());

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->credits_remaining)->toBe(0)
        ->and($balance->credits_used)->toBe(90);

    $tx = AiCreditTransaction::query()->where('team_id', $team->getKey())->sole();
    expect($tx->credits_charged)->toBe(50)
        ->and($tx->metadata['delta'])->toBe(-50);
});

it('creates a balance row if the team has none yet', function (): void {
    $team = Team::factory()->create();
    AiCreditBalance::query()->where('team_id', $team->getKey())->delete();
    AiCreditTransaction::query()->where('team_id', $team->getKey())->delete();
    $admin = SystemAdministrator::factory()->create();

    app(CreditService::class)->adjust($team, 100, 'initial seed', $admin->getKey());

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->credits_remaining)->toBe(100)
        ->and($balance->credits_used)->toBe(0);
});
