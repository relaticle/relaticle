<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Listeners\SeedWorkspaceCreditBalanceListener;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\Chat\Models\AiCreditBalance;

mutates(SeedWorkspaceCreditBalanceListener::class);

it('seeds a free-plan balance when a Workspace is created via the normal flow', function (): void {
    $user = User::factory()->create();

    $workspace = Workspace::forceCreate([
        'user_id' => $user->getKey(),
        'name' => 'New Workspace',
        'personal_workspace' => false,
        'slug' => 'new-workspace-'.now()->timestamp,
        'plan' => Plan::default()->value,
    ]);

    expect(AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->exists())->toBeTrue();
});

it('seeds a balance for the personal workspace created during user signup', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    expect($workspace)->not->toBeNull()
        ->and(AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->exists())->toBeTrue();
});
