<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;

mutates(CreditService::class);

it('resets a Free workspace to 300 credits via the scheduled reset', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    expect($workspace->plan)->toBe(Plan::Free);

    AiCreditBalance::query()
        ->where('workspace_id', $workspace->getKey())
        ->update([
            'credits_remaining' => 5,
            'credits_used' => 295,
            'period_starts_at' => now()->subMonth(),
            'period_ends_at' => now()->subDay(),
        ]);

    Artisan::call('chat:reset-credits');

    expect($workspace->fresh()->aiCreditBalance->credits_remaining)->toBe(Plan::Free->credits());
});

it('resets a Pro workspace to 2000 credits when the scheduled reset runs', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro->value])->save();
    $workspace->refresh();

    AiCreditBalance::query()
        ->where('workspace_id', $workspace->getKey())
        ->update([
            'credits_remaining' => 0,
            'credits_used' => 2_000,
            'period_starts_at' => now()->subMonth(),
            'period_ends_at' => now()->subDay(),
        ]);

    Artisan::call('chat:reset-credits');

    expect($workspace->fresh()->aiCreditBalance->credits_remaining)->toBe(Plan::Pro->credits());
});

it('resets an Enterprise workspace to 10000 credits when the scheduled reset runs', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Enterprise->value])->save();
    $workspace->refresh();

    AiCreditBalance::query()
        ->where('workspace_id', $workspace->getKey())
        ->update([
            'credits_remaining' => 0,
            'credits_used' => 10_000,
            'period_starts_at' => now()->subMonth(),
            'period_ends_at' => now()->subDay(),
        ]);

    Artisan::call('chat:reset-credits');

    expect($workspace->fresh()->aiCreditBalance->credits_remaining)->toBe(Plan::Enterprise->credits());
});

it('refills a past-due Pro workspace at the Free allowance, not the one it stopped paying for', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro->value])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_reset_past_due',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    AiCreditBalance::query()
        ->where('workspace_id', $workspace->getKey())
        ->update([
            'credits_remaining' => 0,
            'credits_used' => 2_000,
            'period_starts_at' => now()->subMonth(),
            'period_ends_at' => now()->subDay(),
        ]);

    Artisan::call('chat:reset-credits');

    expect($workspace->fresh()->aiCreditBalance->credits_remaining)->toBe(Plan::Free->credits());
});

it('leaves purchased packs intact when a past-due workspace refills', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro->value])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_reset_past_due_packs',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    AiCreditBalance::query()
        ->where('workspace_id', $workspace->getKey())
        ->update([
            'credits_remaining' => 500,
            'credits_used' => 2_000,
            'purchased_credits' => 500,
            'period_starts_at' => now()->subMonth(),
            'period_ends_at' => now()->subDay(),
        ]);

    Artisan::call('chat:reset-credits');

    $balance = $workspace->fresh()->aiCreditBalance;

    expect($balance->purchased_credits)->toBe(500)
        ->and($balance->credits_remaining)->toBe(Plan::Free->credits() + 500);
});

it('restores the Pro allowance once the past-due charge clears', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro->value])->save();
    $subscription = $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_reset_recovered',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    $subscription->forceFill(['stripe_status' => 'active'])->save();

    AiCreditBalance::query()
        ->where('workspace_id', $workspace->getKey())
        ->update([
            'credits_remaining' => 0,
            'credits_used' => 300,
            'period_starts_at' => now()->subMonth(),
            'period_ends_at' => now()->subDay(),
        ]);

    Artisan::call('chat:reset-credits');

    expect($workspace->fresh()->aiCreditBalance->credits_remaining)->toBe(Plan::Pro->credits());
});

it('skips workspaces whose period has not yet expired', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()
        ->where('workspace_id', $workspace->getKey())
        ->update([
            'credits_remaining' => 42,
            'credits_used' => 258,
            'period_starts_at' => now()->startOfMonth(),
            'period_ends_at' => now()->endOfMonth(),
        ]);

    Artisan::call('chat:reset-credits');

    expect($workspace->fresh()->aiCreditBalance->credits_remaining)->toBe(42);
});

it('writes plan metadata in the audit transaction', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro->value])->save();
    $workspace->refresh();

    resolve(CreditService::class)->resetPeriod($workspace, 'sysadmin-test');

    $audit = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('idempotency_key', 'like', 'sysadmin-reset-%')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->metadata['plan'])->toBe('pro');
    expect($audit->metadata['allowance_granted'])->toBe(Plan::Pro->credits());
    expect($audit->metadata['sysadmin_id'])->toBe('sysadmin-test');
});
