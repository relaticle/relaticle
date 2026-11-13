<?php

declare(strict_types=1);

use App\Data\ListQuery;
use App\Enums\CrmEntity;
use App\Models\Company;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

mutates(WorkspaceScope::class, CurrentWorkspace::class);

it('returns no records when no workspace is bound', function (): void {
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

    expect(Company::query()->count())->toBe(0)
        ->and(Company::query()->withoutGlobalScope(WorkspaceScope::class)->count())->toBe(2);
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

it('binds a workspace only for the duration of within and restores the previous one', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $otherUser = User::factory()->withWorkspace()->create();
    $company = Company::withoutEvents(fn (): Company => Company::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
        'account_owner_id' => $user->id,
    ]));
    $currentWorkspace = resolve(CurrentWorkspace::class);
    $currentWorkspace->set($otherUser->currentWorkspace);

    $ids = $currentWorkspace->within($user->currentWorkspace, fn (): array => Company::query()->pluck('id')->all());

    expect($ids)->toBe([$company->id])
        ->and($currentWorkspace->get()?->is($otherUser->currentWorkspace))->toBeTrue();
});

it('restores the previous binding when the callback throws', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $currentWorkspace = resolve(CurrentWorkspace::class);

    expect(fn (): mixed => $currentWorkspace->within($user->currentWorkspace, fn (): never => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    expect($currentWorkspace->get())->toBeNull();
});

it('carries the bound workspace into a job through the context', function (): void {
    $user = User::factory()->withWorkspace()->create();
    resolve(CurrentWorkspace::class)->set($user->currentWorkspace);

    $dehydrated = Context::dehydrate();
    resolve(CurrentWorkspace::class)->forget();
    app()->forgetScopedInstances();
    Context::hydrate($dehydrated);

    expect(resolve(CurrentWorkspace::class)->get()?->is($user->currentWorkspace))->toBeTrue();
});

it('reads its own workspace records through the workspace relations with nothing bound', function (): void {
    $user = User::factory()->withWorkspace()->create();
    Company::withoutEvents(fn (): Company => Company::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
        'account_owner_id' => $user->id,
    ]));

    expect(resolve(CurrentWorkspace::class)->get())->toBeNull()
        ->and($user->currentWorkspace->companies()->count())->toBe(1);
});

it('reads every workspace once reading across workspaces is switched on, and one workspace when one is bound', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $otherUser = User::factory()->withWorkspace()->create();
    Company::withoutEvents(function () use ($user, $otherUser): void {
        Company::factory()->create(['workspace_id' => $user->currentWorkspace->id, 'account_owner_id' => $user->id]);
        Company::factory()->create(['workspace_id' => $otherUser->currentWorkspace->id, 'account_owner_id' => $otherUser->id]);
    });
    $currentWorkspace = resolve(CurrentWorkspace::class);
    $currentWorkspace->forget();

    $currentWorkspace->readAcrossWorkspaces();

    expect(Company::query()->count())->toBe(2)
        ->and($currentWorkspace->within($user->currentWorkspace, fn (): int => Company::query()->count()))->toBe(1);
});

it('does not carry reading across workspaces into a job', function (): void {
    $user = User::factory()->withWorkspace()->create();
    Company::withoutEvents(fn (): Company => Company::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
        'account_owner_id' => $user->id,
    ]));
    resolve(CurrentWorkspace::class)->forget();
    resolve(CurrentWorkspace::class)->readAcrossWorkspaces();

    $dehydrated = Context::dehydrate();
    app()->forgetScopedInstances();
    Context::hydrate($dehydrated);

    expect(Company::query()->count())->toBe(0);
});

it('binds a workspace by id without querying it again when it is already bound', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $currentWorkspace = resolve(CurrentWorkspace::class);
    $workspaceId = $user->currentWorkspace->getKey();

    $queries = $currentWorkspace->within($workspaceId, function () use ($currentWorkspace, $workspaceId): int {
        DB::enableQueryLog();
        $currentWorkspace->within($workspaceId, fn (): null => null);

        return count(DB::getQueryLog());
    });

    expect($queries)->toBe(0);
});

it('lists the acting user\'s companies whatever workspace is bound', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $otherUser = User::factory()->withWorkspace()->create();
    $company = Company::withoutEvents(fn (): Company => Company::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
        'account_owner_id' => $user->id,
    ]));
    resolve(CurrentWorkspace::class)->set($otherUser->currentWorkspace);

    $page = resolve(CrmEntity::Company->query())->paginate($user, new ListQuery);

    expect(collect($page->items())->pluck('id')->all())->toBe([$company->id]);
});
