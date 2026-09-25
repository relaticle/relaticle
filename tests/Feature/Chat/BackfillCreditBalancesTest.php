<?php

declare(strict_types=1);

use App\Actions\Chat\SeedWorkspaceCreditBalance;
use App\Enums\Plan;
use App\Models\Workspace;
use Relaticle\Chat\Models\AiCreditBalance;

mutates(SeedWorkspaceCreditBalance::class);

it('backfills credit balances for workspaces that have none', function (): void {
    $t1 = Workspace::factory()->create();
    $t2 = Workspace::factory()->create();
    $t3 = Workspace::factory()->create();
    AiCreditBalance::query()->whereIn('workspace_id', [$t1->getKey(), $t2->getKey(), $t3->getKey()])->delete();

    $missingCount = Workspace::query()->whereDoesntHave('aiCreditBalance')->count();
    expect($missingCount)->toBeGreaterThanOrEqual(3);

    $action = app(SeedWorkspaceCreditBalance::class);
    Workspace::query()
        ->whereDoesntHave('aiCreditBalance')
        ->with('subscriptions')
        ->chunkById(200, function ($workspaces) use ($action): void {
            foreach ($workspaces as $workspace) {
                $action->execute($workspace);
            }
        });

    expect(Workspace::query()->whereDoesntHave('aiCreditBalance')->count())->toBe(0);
    expect($t1->fresh()->aiCreditBalance)->not->toBeNull()
        ->and($t2->fresh()->aiCreditBalance)->not->toBeNull()
        ->and($t3->fresh()->aiCreditBalance)->not->toBeNull();
});

it('falls back to Plan::default() when the workspace plan attribute is null at runtime', function (): void {
    $workspace = Workspace::factory()->create();

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->delete();

    // forceFill sets the in-memory attribute to null without touching the persisted
    // row (which still holds the NOT NULL default). This exercises the defensive
    // `?? Plan::default()` fallback in SeedWorkspaceCreditBalance::execute().
    $workspace->forceFill(['plan' => null]);

    $balance = resolve(SeedWorkspaceCreditBalance::class)->execute($workspace);

    expect($balance->credits_remaining)->toBe(Plan::default()->credits());
});
