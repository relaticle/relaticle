<?php

declare(strict_types=1);

use App\Console\Commands\UtilizationReportCommand;
use App\Enums\Plan;
use App\Models\User;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;

mutates(UtilizationReportCommand::class);

it('rejects a malformed --month value without a stack trace', function (): void {
    $this->artisan('billing:utilization-report', ['--month' => 'garbage'])
        ->expectsOutputToContain('Invalid --month')
        ->assertFailed();
});

it('rejects an out-of-range --month value', function (): void {
    $this->artisan('billing:utilization-report', ['--month' => '2026-13'])
        ->expectsOutputToContain('Invalid --month')
        ->assertFailed();
});

it('rejects --month=2026-00 instead of silently reporting the wrong month', function (): void {
    $this->artisan('billing:utilization-report', ['--month' => '2026-00'])
        ->expectsOutputToContain('Invalid --month')
        ->assertFailed();
});

it('defaults --month to the previous month', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-07-10 12:00:00', new DateTimeZone('UTC')));

    $this->artisan('billing:utilization-report')
        ->expectsOutputToContain('June 2026')
        ->assertSuccessful();
});

it('prints per-plan utilization and pack-purchase metrics sourced from the credit ledger', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-07-10 12:00:00', new DateTimeZone('UTC')));

    // Two teams so the bulk team/reset-row lookups hydrate 2+ rows in one
    // query -- the shape that arms Eloquent's strict lazy-loading guard.
    $teamA = User::factory()->withPersonalTeam()->create()->currentTeam;
    $teamA->forceFill(['plan' => Plan::Pro])->save();
    app(CreditService::class)->resetPeriod($teamA->refresh());

    AiCreditTransaction::factory()->create([
        'team_id' => $teamA->getKey(),
        'type' => AiCreditType::Chat,
        'credits_charged' => 2000, // 100% of the Pro allowance
        'created_at' => now(),
    ]);

    AiCreditTransaction::factory()->create([
        'team_id' => $teamA->getKey(),
        'type' => AiCreditType::Purchase,
        'model' => 'stripe',
        'credits_charged' => 1000,
        'created_at' => now(),
    ]);

    $teamB = User::factory()->withPersonalTeam()->create()->currentTeam;
    $teamB->forceFill(['plan' => Plan::Pro])->save();
    app(CreditService::class)->resetPeriod($teamB->refresh());

    AiCreditTransaction::factory()->create([
        'team_id' => $teamB->getKey(),
        'type' => AiCreditType::Chat,
        'credits_charged' => 20, // 1% of the Pro allowance
        'created_at' => now(),
    ]);

    $this->artisan('billing:utilization-report', ['--month' => '2026-07'])
        ->expectsOutputToContain('rows below cover only workspaces with recorded usage')
        ->expectsTable(
            ['Plan', 'Workspaces', 'p50', 'p90', 'p99', 'At 100%'],
            [['pro', 2, '1%', '100%', '100%', 1]],
        )
        ->expectsTable(
            ['Pack buyers', 'Purchases', 'Credits sold', 'Repeat buyers'],
            [[1, 1, 1000, 0]],
        )
        ->assertSuccessful();
});

it('reports historical utilization for a past month after the balance row has rolled forward to the current period', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-04-10 12:00:00', new DateTimeZone('UTC')));

    $credits = app(CreditService::class);

    // teamA was Free in April; upgraded to Pro afterwards. Its April
    // reset-period ledger row (plan=free, allowance=300) must be what the
    // April report reads -- not teamA's current Pro plan.
    $teamA = User::factory()->withPersonalTeam()->create()->currentTeam;
    $credits->resetPeriod($teamA->refresh());

    AiCreditTransaction::factory()->create([
        'team_id' => $teamA->getKey(),
        'type' => AiCreditType::Chat,
        'credits_charged' => 150, // 50% of the Free allowance (300)
        'created_at' => now(),
    ]);

    $teamB = User::factory()->withPersonalTeam()->create()->currentTeam;
    $teamB->forceFill(['plan' => Plan::Enterprise])->save();
    $credits->resetPeriod($teamB->refresh());

    AiCreditTransaction::factory()->create([
        'team_id' => $teamB->getKey(),
        'type' => AiCreditType::Chat,
        'credits_charged' => 2000, // 20% of the Enterprise allowance (10,000)
        'created_at' => now(),
    ]);

    // Roll both balance rows forward to the current period, mimicking the
    // nightly `chat:reset-credits` runs that have happened since April --
    // this wipes any trace of April from ai_credit_balances.
    $this->travelTo(new DateTimeImmutable('2026-07-10 12:00:00', new DateTimeZone('UTC')));
    $teamA->forceFill(['plan' => Plan::Pro])->save();
    $credits->resetPeriod($teamA->refresh());
    $credits->resetPeriod($teamB->refresh());

    $this->artisan('billing:utilization-report', ['--month' => '2026-04'])
        ->expectsTable(
            ['Plan', 'Workspaces', 'p50', 'p90', 'p99', 'At 100%'],
            [
                ['enterprise', 1, '20%', '20%', '20%', 0],
                ['free', 1, '50%', '50%', '50%', 0],
            ],
        )
        ->assertSuccessful();
});

it('excludes usage recorded outside the reported month and falls back to the current plan when no historical reset record exists', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-07-10 12:00:00', new DateTimeZone('UTC')));

    // teamA's usage falls in June, one day before the July window opens --
    // it must not leak into the July report.
    $teamA = User::factory()->withPersonalTeam()->create()->currentTeam;
    $teamA->forceFill(['plan' => Plan::Enterprise])->save();

    AiCreditTransaction::factory()->create([
        'team_id' => $teamA->getKey(),
        'type' => AiCreditType::Chat,
        'credits_charged' => 10_000, // would read as 100% if it leaked in
        'created_at' => now()->startOfMonth()->subDay(),
    ]);

    // teamB has usage inside July but was never reset (created mid-window),
    // so it exercises the current-plan-allowance fallback.
    $teamB = User::factory()->withPersonalTeam()->create()->currentTeam;
    $teamB->forceFill(['plan' => Plan::Enterprise])->save();

    AiCreditTransaction::factory()->create([
        'team_id' => $teamB->getKey(),
        'type' => AiCreditType::Chat,
        'credits_charged' => 1_000, // 10% of the Enterprise allowance
        'created_at' => now(),
    ]);

    $this->artisan('billing:utilization-report', ['--month' => '2026-07'])
        ->expectsTable(
            ['Plan', 'Workspaces', 'p50', 'p90', 'p99', 'At 100%'],
            [['enterprise', 1, '10%', '10%', '10%', 0]],
        )
        ->expectsOutputToContain('1 workspace(s) had no historical period-reset record')
        ->assertSuccessful();
});
