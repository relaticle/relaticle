<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;

mutates(CreditService::class);

it('resets a Free team to 300 credits via the scheduled reset', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    expect($team->plan)->toBe(Plan::Free);

    AiCreditBalance::query()
        ->where('team_id', $team->getKey())
        ->update([
            'credits_remaining' => 5,
            'credits_used' => 295,
            'period_starts_at' => now()->subMonth(),
            'period_ends_at' => now()->subDay(),
        ]);

    Artisan::call('chat:reset-credits');

    expect($team->fresh()->aiCreditBalance->credits_remaining)->toBe(Plan::Free->credits());
});

it('resets a Pro team to 2000 credits when the scheduled reset runs', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro->value])->save();
    $team->refresh();

    AiCreditBalance::query()
        ->where('team_id', $team->getKey())
        ->update([
            'credits_remaining' => 0,
            'credits_used' => 2_000,
            'period_starts_at' => now()->subMonth(),
            'period_ends_at' => now()->subDay(),
        ]);

    Artisan::call('chat:reset-credits');

    expect($team->fresh()->aiCreditBalance->credits_remaining)->toBe(Plan::Pro->credits());
});

it('resets an Enterprise team to 10000 credits when the scheduled reset runs', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Enterprise->value])->save();
    $team->refresh();

    AiCreditBalance::query()
        ->where('team_id', $team->getKey())
        ->update([
            'credits_remaining' => 0,
            'credits_used' => 10_000,
            'period_starts_at' => now()->subMonth(),
            'period_ends_at' => now()->subDay(),
        ]);

    Artisan::call('chat:reset-credits');

    expect($team->fresh()->aiCreditBalance->credits_remaining)->toBe(Plan::Enterprise->credits());
});

it('skips teams whose period has not yet expired', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()
        ->where('team_id', $team->getKey())
        ->update([
            'credits_remaining' => 42,
            'credits_used' => 258,
            'period_starts_at' => now()->startOfMonth(),
            'period_ends_at' => now()->endOfMonth(),
        ]);

    Artisan::call('chat:reset-credits');

    expect($team->fresh()->aiCreditBalance->credits_remaining)->toBe(42);
});

it('writes plan metadata in the audit transaction', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro->value])->save();
    $team->refresh();

    resolve(CreditService::class)->resetPeriod($team, 'sysadmin-test');

    $audit = AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('idempotency_key', 'like', 'sysadmin-reset-%')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->metadata['plan'])->toBe('pro');
    expect($audit->metadata['allowance_granted'])->toBe(Plan::Pro->credits());
    expect($audit->metadata['sysadmin_id'])->toBe('sysadmin-test');
});
