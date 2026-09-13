<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;

mutates(WorkspaceScope::class);

afterEach(function (): void {
    Company::clearBootedModels();
});

it('returns zero results when no user is authenticated', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    Company::withoutEvents(fn () => Company::factory()->create([
        'workspace_id' => $workspace->id,
        'account_owner_id' => $user->id,
    ]));

    Company::addGlobalScope(new WorkspaceScope);

    expect(Company::query()->count())->toBe(0);
});

it('scopes results to the authenticated user current workspace', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $ownCompany = Company::withoutEvents(fn () => Company::factory()->create([
        'workspace_id' => $workspace->id,
        'account_owner_id' => $user->id,
    ]));

    $otherUser = User::factory()->withWorkspace()->create();
    Company::withoutEvents(fn () => Company::factory()->create([
        'workspace_id' => $otherUser->currentWorkspace->id,
        'account_owner_id' => $otherUser->id,
    ]));

    $this->actingAs($user);
    Company::addGlobalScope(new WorkspaceScope);

    $results = Company::query()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($ownCompany->id);
});
