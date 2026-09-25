<?php

declare(strict_types=1);

use App\Actions\Chat\SeedWorkspaceCreditBalance;
use App\Enums\Plan;
use App\Models\User;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;

mutates(SeedWorkspaceCreditBalance::class);

it('seeds a credit balance with the allowance from the workspaces plan', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();
    expect(AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->exists())->toBeFalse();

    resolve(SeedWorkspaceCreditBalance::class)->execute($workspace);

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();
    expect($balance)->not->toBeNull();
    expect($balance->credits_remaining)->toBe(Plan::Free->credits());
});

it('does not double-seed when called twice', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $first = resolve(SeedWorkspaceCreditBalance::class)->execute($workspace);
    $second = resolve(SeedWorkspaceCreditBalance::class)->execute($workspace);

    expect($first->getKey())->toBe($second->getKey());
    expect(AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->count())->toBe(1);

    $seedAudits = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('metadata->action', 'seed_initial_balance')
        ->count();
    expect($seedAudits)->toBe(1);
});

it('seeds a Pro allowance when the workspace is on Pro at creation time', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();
    $workspace->plan = Plan::Pro;
    $workspace->save();

    resolve(SeedWorkspaceCreditBalance::class)->execute($workspace);

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();
    expect($balance->credits_remaining)->toBe(Plan::Pro->credits());
});

it('writes plan metadata in the seed audit transaction', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();
    $workspace->plan = Plan::Enterprise;
    $workspace->save();

    resolve(SeedWorkspaceCreditBalance::class)->execute($workspace);

    $audit = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('idempotency_key', 'like', 'seed-initial-%')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->metadata['plan'])->toBe('enterprise');
    expect($audit->metadata['allowance_granted'])->toBe(Plan::Enterprise->credits());
});

it('seeds trial workspaces with the trial span as the credit period', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-06-25 12:00:00', new DateTimeZone('UTC')));

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(10)])->save();

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();

    resolve(SeedWorkspaceCreditBalance::class)->execute($workspace->refresh());

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();
    expect($balance->period_ends_at->toDateTimeString())->toBe(now()->addDays(10)->toDateTimeString())
        ->and($balance->period_starts_at->toDateTimeString())->toBe(now()->addDays(10)->subDays(14)->toDateTimeString());
});
