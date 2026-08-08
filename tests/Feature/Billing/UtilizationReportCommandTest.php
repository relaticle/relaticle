<?php

declare(strict_types=1);

use App\Console\Commands\UtilizationReportCommand;
use App\Enums\Plan;
use App\Models\User;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;

mutates(UtilizationReportCommand::class);

it('prints per-plan utilization and pack metrics for a month', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-07-10 12:00:00', new DateTimeZone('UTC')));

    // chunkById() hydrates every matching balance (and its `team`) in one query,
    // which is what arms Eloquent's strict lazy-loading guard on $balance->team --
    // a single-team test can't catch a missing ->with('team') eager load.
    $teamA = User::factory()->withPersonalTeam()->create()->currentTeam;
    $teamA->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->updateOrCreate(['team_id' => $teamA->getKey()], [
        'credits_remaining' => 0,
        'credits_used' => Plan::Pro->credits(), // 100% utilization
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    AiCreditTransaction::factory()->create([
        'team_id' => $teamA->getKey(),
        'type' => 'purchase',
        'model' => 'stripe',
        'credits_charged' => 1000,
        'created_at' => now(),
    ]);

    $teamB = User::factory()->withPersonalTeam()->create()->currentTeam;
    $teamB->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->updateOrCreate(['team_id' => $teamB->getKey()], [
        'credits_remaining' => Plan::Pro->credits(),
        'credits_used' => 0, // 0% utilization
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $this->artisan('billing:utilization-report', ['--month' => '2026-07'])
        ->expectsOutputToContain('pro')
        ->expectsOutputToContain('100')
        ->assertSuccessful();
});

it('excludes a workspace whose plan period does not overlap the reported month', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-07-10 12:00:00', new DateTimeZone('UTC')));

    $teamA = User::factory()->withPersonalTeam()->create()->currentTeam;
    $teamA->forceFill(['plan' => Plan::Enterprise])->save();

    AiCreditBalance::query()->updateOrCreate(['team_id' => $teamA->getKey()], [
        'credits_remaining' => Plan::Enterprise->credits(),
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth()->subMonths(3),
        'period_ends_at' => now()->endOfMonth()->subMonths(3),
    ]);

    $teamB = User::factory()->withPersonalTeam()->create()->currentTeam;
    $teamB->forceFill(['plan' => Plan::Enterprise])->save();

    AiCreditBalance::query()->updateOrCreate(['team_id' => $teamB->getKey()], [
        'credits_remaining' => Plan::Enterprise->credits(),
        'credits_used' => (int) round(Plan::Enterprise->credits() * 0.5),
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $this->artisan('billing:utilization-report', ['--month' => '2026-07'])
        ->expectsOutputToContain('enterprise')
        ->assertSuccessful();
});

it('defaults --month to the previous month', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-07-10 12:00:00', new DateTimeZone('UTC')));

    $this->artisan('billing:utilization-report')
        ->expectsOutputToContain('June 2026')
        ->assertSuccessful();
});
