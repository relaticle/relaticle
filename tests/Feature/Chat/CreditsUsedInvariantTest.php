<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(CreditService::class);

/**
 * `credits_used` on `ai_credit_balances` is the period's spend meter.
 *
 * Only consumption paths (reserveCredit/settleReservation/deduct) may bump it;
 * only refundReservation and resetPeriod may roll it back. Sysadmin `adjust`
 * is an accounting event — granting credits or clawing them back must NOT
 * touch the spend meter, otherwise revocations would let sysadmins silently
 * rewrite a tenant's consumption history.
 */
it('grant adjustments do not touch credits_used', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    AiCreditBalance::query()->where('team_id', $team->getKey())->update([
        'credits_remaining' => 100,
        'credits_used' => 42,
    ]);
    $admin = SystemAdministrator::factory()->create();

    app(CreditService::class)->adjust($team, 250, 'goodwill', $admin->getKey());

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->credits_remaining)->toBe(350)
        ->and($balance->credits_used)->toBe(42);
});

it('revocation adjustments do not touch credits_used', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    AiCreditBalance::query()->where('team_id', $team->getKey())->update([
        'credits_remaining' => 100,
        'credits_used' => 75,
    ]);
    $admin = SystemAdministrator::factory()->create();

    // Revoke more than the liquid balance — credits_remaining floors at 0,
    // credits_used stays put because the period's spend is immutable history.
    app(CreditService::class)->adjust($team, -200, 'wipe', $admin->getKey());

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->credits_remaining)->toBe(0)
        ->and($balance->credits_used)->toBe(75);
});

it('reserve + settle + refund cycles move credits_used in lockstep with the chat ledger', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    AiCreditTransaction::query()->where('team_id', $team->getKey())->delete();
    AiCreditBalance::query()->where('team_id', $team->getKey())->update([
        'credits_remaining' => 100,
        'credits_used' => 0,
    ]);

    $service = app(CreditService::class);

    // Successful 3-credit Opus turn: reserve(1) + settle(+2) = +3 to used.
    $service->reserveCredit($team);
    $service->settleReservation(
        team: $team,
        user: $user,
        type: AiCreditType::Chat,
        model: 'claude-opus-4-7',
        inputTokens: 100,
        outputTokens: 200,
        idempotencyKey: 'turn-success',
    );

    // Failed turn: reserve(1) + refund(-1) = net 0 to used.
    $service->reserveCredit($team);
    $service->refundReservation($team, idempotencyToken: 'turn-failed');

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->credits_used)->toBe(3)
        ->and($balance->credits_remaining)->toBe(97);

    // Sysadmin grants 50 — spend meter frozen.
    $admin = SystemAdministrator::factory()->create();
    $service->adjust($team, 50, 'support', $admin->getKey());

    // Sysadmin claws back 20 — spend meter still frozen.
    $service->adjust($team, -20, 'fraud', $admin->getKey());

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->credits_used)->toBe(3)
        ->and($balance->credits_remaining)->toBe(127);
});

it('chat-style ledger rows are the only ones the spend widget should sum', function (): void {
    // The ledger has rows of every type; the spend widget allowlist is what
    // keeps "Credits this month" honest. Pin the contract via the enum cases
    // so a future Refund/Adjustment subtype rename won't silently leak in.
    expect([
        AiCreditType::Chat->value,
        AiCreditType::Summary->value,
        AiCreditType::Embedding->value,
    ])->toBe(['chat', 'summary', 'embedding'])
        ->and([
            AiCreditType::Refund->value,
            AiCreditType::Adjustment->value,
        ])->toBe(['refund', 'adjustment']);
});
