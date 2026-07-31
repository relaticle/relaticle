<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Relaticle\Chat\Commands\ResetCreditsCommand;
use Relaticle\Chat\Models\AiCreditBalance;

mutates(ResetCreditsCommand::class);

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
