<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\CurrentWorkspace;

mutates(WorkspaceScope::class, CurrentWorkspace::class);

it('leaves queries unconstrained outside a workspace request', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $otherUser = User::factory()->withWorkspace()->create();

    Company::withoutEvents(fn (): Company => Company::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
        'account_owner_id' => $user->id,
    ]));
    Company::withoutEvents(fn (): Company => Company::factory()->create([
        'workspace_id' => $otherUser->currentWorkspace->id,
        'account_owner_id' => $otherUser->id,
    ]));

    expect(Company::query()->count())->toBe(2);
});

it('scopes results to the current workspace', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $ownCompany = Company::withoutEvents(fn (): Company => Company::factory()->create([
        'workspace_id' => $workspace->id,
        'account_owner_id' => $user->id,
    ]));

    $otherUser = User::factory()->withWorkspace()->create();
    Company::withoutEvents(fn (): Company => Company::factory()->create([
        'workspace_id' => $otherUser->currentWorkspace->id,
        'account_owner_id' => $otherUser->id,
    ]));

    resolve(CurrentWorkspace::class)->set($workspace);

    $results = Company::query()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($ownCompany->id);
});
