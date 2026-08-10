<?php

declare(strict_types=1);

use App\Actions\Chat\SeedTeamCreditBalance;
use App\Enums\Plan;
use App\Models\User;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;

mutates(SeedTeamCreditBalance::class);

it('seeds a credit balance with the allowance from the teams plan', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->where('team_id', $team->getKey())->delete();
    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->exists())->toBeFalse();

    resolve(SeedTeamCreditBalance::class)->execute($team);

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();
    expect($balance)->not->toBeNull();
    expect($balance->credits_remaining)->toBe(Plan::Free->credits());
});

it('does not double-seed when called twice', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $first = resolve(SeedTeamCreditBalance::class)->execute($team);
    $second = resolve(SeedTeamCreditBalance::class)->execute($team);

    expect($first->getKey())->toBe($second->getKey());
    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->count())->toBe(1);

    $seedAudits = AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('metadata->action', 'seed_initial_balance')
        ->count();
    expect($seedAudits)->toBe(1);
});

it('seeds a Pro allowance when the team is on Pro at creation time', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->where('team_id', $team->getKey())->delete();
    $team->plan = Plan::Pro;
    $team->save();

    resolve(SeedTeamCreditBalance::class)->execute($team);

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();
    expect($balance->credits_remaining)->toBe(Plan::Pro->credits());
});

it('writes plan metadata in the seed audit transaction', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->where('team_id', $team->getKey())->delete();
    $team->plan = Plan::Enterprise;
    $team->save();

    resolve(SeedTeamCreditBalance::class)->execute($team);

    $audit = AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('idempotency_key', 'like', 'seed-initial-%')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->metadata['plan'])->toBe('enterprise');
    expect($audit->metadata['allowance_granted'])->toBe(Plan::Enterprise->credits());
});

it('seeds trial teams with the trial span as the credit period', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-06-25 12:00:00', new DateTimeZone('UTC')));

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(10)])->save();

    AiCreditBalance::query()->where('team_id', $team->getKey())->delete();

    resolve(SeedTeamCreditBalance::class)->execute($team->refresh());

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();
    expect($balance->period_ends_at->toDateTimeString())->toBe(now()->addDays(10)->toDateTimeString())
        ->and($balance->period_starts_at->toDateTimeString())->toBe(now()->addDays(10)->subDays(14)->toDateTimeString());
});
