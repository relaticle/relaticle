<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Relaticle\Chat\Commands\ResetCreditsCommand;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditPeriodResolver;

mutates(ResetCreditsCommand::class, CreditPeriodResolver::class);

it('resets credits for teams whose billing period has ended', function (): void {
    // Pinned mid-month: on the 31st, now()->subMonth() overflows forward (31 Jul -> 1 Jul),
    // so ->endOfMonth() lands on the CURRENT month end -- a period that has not ended yet.
    $this->travelTo(new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC')));

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'team_id' => $team->getKey(),
        'credits_remaining' => 0,
        'credits_used' => 100,
        'period_starts_at' => now()->subMonths(2)->startOfMonth(),
        'period_ends_at' => now()->subMonth()->endOfMonth(),
    ]);

    $this->artisan('chat:reset-credits')->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();
    expect($balance->credits_remaining)->toBe(Plan::Free->credits());
    expect($balance->credits_used)->toBe(0);
    expect($balance->period_ends_at->greaterThan(now()))->toBeTrue();
});

it('does not reset credits for teams whose period has not yet ended', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC')));

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'team_id' => $team->getKey(),
        'credits_remaining' => 42,
        'credits_used' => 58,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $this->artisan('chat:reset-credits')->assertSuccessful();

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->value('credits_remaining'))->toBe(42);
});

it('anchors the reset period to the subscription anniversary, not the calendar month', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-06-25 12:00:00', new DateTimeZone('UTC')));

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro, 'stripe_id' => 'cus_anchor_test'])->save();

    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_anchor_test',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'created_at' => now()->subMonths(2)->subDays(5), // anchor: 2026-04-20 12:00
    ]);

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'credits_remaining' => 0,
        'credits_used' => 100,
        'period_starts_at' => now()->subMonth()->subDays(5),
        'period_ends_at' => now()->subDays(5), // ended 2026-06-20 — sweep must fire
    ]);

    $this->artisan('chat:reset-credits')->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->credits_remaining)->toBe(Plan::Pro->credits())
        ->and($balance->period_starts_at->toDateTimeString())->toBe('2026-06-20 12:00:00')
        ->and($balance->period_ends_at->toDateTimeString())->toBe('2026-07-20 12:00:00');
});

it('clamps anniversary cycles for month-end anchors without drifting', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-03-05 09:00:00', new DateTimeZone('UTC')));

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro, 'stripe_id' => 'cus_clamp_test'])->save();

    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_clamp_test',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'created_at' => new DateTimeImmutable('2026-01-31 10:00:00', new DateTimeZone('UTC')),
    ]);

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'credits_remaining' => 0,
        'credits_used' => 50,
        'period_starts_at' => now()->subMonths(2),
        'period_ends_at' => now()->subDay(),
    ]);

    $this->artisan('chat:reset-credits')->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    // Cycle containing Mar 5: [Feb 28 10:00, Mar 31 10:00) — both computed from the Jan 31 anchor.
    expect($balance->period_starts_at->toDateTimeString())->toBe('2026-02-28 10:00:00')
        ->and($balance->period_ends_at->toDateTimeString())->toBe('2026-03-31 10:00:00');
});

it('resolves the correct cycle inside the diffInMonths under-estimate window', function (): void {
    // diffInMonths(anchor=Jan 31 10:00, now=Feb 28 16:00) truncates to 0 elapsed
    // months, which would place a naive period_ends_at at Feb 28 10:00 --
    // already in the past relative to now(). This is the ~14-hour window
    // (Feb 28 10:00-23:59:59) where the fractional/overflow-style estimate
    // Carbon's diffInMonths() returns disagrees with the clamped
    // addMonthsNoOverflow() cycle boundaries.
    $this->travelTo(new DateTimeImmutable('2026-02-28 16:00:00', new DateTimeZone('UTC')));

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro, 'stripe_id' => 'cus_underestimate_test'])->save();

    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_underestimate_test',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'created_at' => new DateTimeImmutable('2026-01-31 10:00:00', new DateTimeZone('UTC')),
    ]);

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'credits_remaining' => 0,
        'credits_used' => 20,
        'period_starts_at' => now()->subMonths(2),
        'period_ends_at' => now()->subHour(),
    ]);

    $this->artisan('chat:reset-credits')->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->period_starts_at->toDateTimeString())->toBe('2026-02-28 10:00:00')
        ->and($balance->period_ends_at->toDateTimeString())->toBe('2026-03-31 10:00:00');
});

it('holds the anniversary-cycle invariant across a two-year sweep for a month-end anchor', function (): void {
    $anchor = new DateTimeImmutable('2026-01-31 10:00:00', new DateTimeZone('UTC'));

    $this->travelTo($anchor);

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro, 'stripe_id' => 'cus_invariant_sweep_test'])->save();

    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_invariant_sweep_test',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'created_at' => $anchor,
    ]);

    $resolver = resolve(CreditPeriodResolver::class);
    $cursor = Carbon::instance($anchor);
    $sweepEnd = $cursor->copy()->addYears(2);

    while ($cursor->lessThan($sweepEnd)) {
        $this->travelTo($cursor);

        $bounds = $resolver->boundsFor($team);

        expect($bounds['start']->lessThanOrEqualTo($cursor))->toBeTrue()
            ->and($cursor->lessThan($bounds['end']))->toBeTrue();

        $cursor = $cursor->copy()->addHours(6);
    }
});

it('keeps calendar-month periods for teams with no subscription and no trial', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC')));

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'credits_remaining' => 0,
        'credits_used' => 10,
        'period_starts_at' => now()->subMonths(2)->startOfMonth(),
        'period_ends_at' => now()->subMonth()->endOfMonth(),
    ]);

    $this->artisan('chat:reset-credits')->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->period_starts_at->toDateTimeString())->toBe(now()->startOfMonth()->toDateTimeString())
        ->and($balance->period_ends_at->toDateTimeString())->toBe(now()->endOfMonth()->toDateTimeString());
});
