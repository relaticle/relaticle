<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\Team;
use App\Models\User;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;

mutates(CreditService::class);

/** @return array{0: User, 1: Team} */
function packOwnerAndTeam(): array
{
    $user = User::factory()->withPersonalTeam()->create();

    /** @var Team $team */
    $team = $user->currentTeam;

    return [$user, $team];
}

it('grants purchased credits idempotently on the session id', function (): void {
    [, $team] = packOwnerAndTeam();
    $service = app(CreditService::class);

    expect($service->addPurchasedCredits($team, 1000, 'pack-cs_test_1', ['price_id' => 'price_x']))->toBeTrue()
        ->and($service->addPurchasedCredits($team, 1000, 'pack-cs_test_1', ['price_id' => 'price_x']))->toBeFalse();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->purchased_credits)->toBe(1000)
        ->and($balance->credits_remaining)->toBe(Plan::Free->credits() + 1000)
        ->and(AiCreditTransaction::query()->where('team_id', $team->getKey())->where('type', AiCreditType::Purchase)->count())->toBe(1);
});

it('spends the allowance before purchased credits', function (): void {
    [$user, $team] = packOwnerAndTeam();
    $service = app(CreditService::class);
    $service->addPurchasedCredits($team, 1000, 'pack-cs_test_2');

    // Free allowance is 300; burn 350 credits => 300 allowance + 50 purchased.
    foreach (range(1, 350) as $i) {
        expect($service->reserveCredit($team))->toBeTrue();
    }

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->credits_remaining)->toBe(950)
        ->and($balance->purchased_credits)->toBe(950); // all remaining balance is purchased
});

it('preserves purchased credits across a period reset', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC')));

    [, $team] = packOwnerAndTeam();
    $service = app(CreditService::class);
    $service->addPurchasedCredits($team, 500, 'pack-cs_test_3');

    AiCreditBalance::query()->where('team_id', $team->getKey())->update([
        'period_starts_at' => now()->subMonths(2)->startOfMonth(),
        'period_ends_at' => now()->subMonth()->endOfMonth(),
    ]);

    $this->artisan('chat:reset-credits')->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->purchased_credits)->toBe(500)
        ->and($balance->credits_remaining)->toBe(Plan::Free->credits() + 500)
        ->and($balance->credits_used)->toBe(0);
});

it('keeps purchased credits when the plan changes', function (): void {
    [, $team] = packOwnerAndTeam();
    $service = app(CreditService::class);
    $service->addPurchasedCredits($team, 500, 'pack-cs_test_4');

    $team->forceFill(['plan' => Plan::Pro])->save();
    $service->resetPeriod($team->refresh());

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->purchased_credits)->toBe(500)
        ->and($balance->credits_remaining)->toBe(Plan::Pro->credits() + 500);
});

it('returns a refunded reservation to the purchased bucket once the allowance is spent', function (): void {
    [, $team] = packOwnerAndTeam();
    $service = app(CreditService::class);
    $service->addPurchasedCredits($team, 10, 'pack-refund-restore');

    // Burn the whole Free allowance so every further spend eats purchased credits.
    foreach (range(1, Plan::Free->credits()) as $ignored) {
        expect($service->reserveCredit($team))->toBeTrue();
    }

    expect($service->reserveCredit($team, 'reserve-turn-1'))->toBeTrue();
    $service->refundReservation($team, 1, 'resolve-turn-1');

    // Without restoring the prepaid bucket the credit survives as an allowance
    // credit and resetPeriod() wipes it — the customer silently loses a credit
    // they paid for on every crashed turn.
    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->credits_remaining)->toBe(10)
        ->and($balance->purchased_credits)->toBe(10);

    $service->resetPeriod($team);

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->value('credits_remaining'))
        ->toBe(Plan::Free->credits() + 10);
});

it('credits a refund to the allowance while allowance credits remain', function (): void {
    [, $team] = packOwnerAndTeam();
    $service = app(CreditService::class);
    $service->addPurchasedCredits($team, 10, 'pack-refund-allowance');

    expect($service->reserveCredit($team, 'reserve-turn-2'))->toBeTrue();
    $service->refundReservation($team, 1, 'resolve-turn-2');

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->credits_remaining)->toBe(Plan::Free->credits() + 10)
        ->and($balance->purchased_credits)->toBe(10);
});

it('does not invent purchased credits when a refund lands on an empty balance', function (): void {
    [, $team] = packOwnerAndTeam();
    $service = app(CreditService::class);

    foreach (range(1, Plan::Free->credits()) as $ignored) {
        expect($service->reserveCredit($team))->toBeTrue();
    }

    $service->refundReservation($team, 1, 'resolve-turn-3');

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->credits_remaining)->toBe(1)
        ->and($balance->purchased_credits)->toBe(0);
});
