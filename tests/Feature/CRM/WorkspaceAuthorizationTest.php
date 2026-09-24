<?php

declare(strict_types=1);

use App\Enums\WorkspaceCapability;
use App\Enums\WorkspaceRole;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\CompanyPolicy;
use App\Policies\NotePolicy;
use App\Policies\OpportunityPolicy;
use App\Policies\PeoplePolicy;
use App\Policies\TaskPolicy;
use Filament\Facades\Filament;

mutates(CompanyPolicy::class, NotePolicy::class, OpportunityPolicy::class, PeoplePolicy::class, TaskPolicy::class, User::class);

it('authorizes :dataset by workspace membership and role', function (string $model): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $this->actingAs($owner);
    Filament::setTenant($workspace);

    $admin = User::factory()->create();
    $workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $admin->switchWorkspace($workspace);

    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->switchWorkspace($workspace);

    $viewer = User::factory()->create();
    $workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($workspace);

    $outsider = User::factory()->create();

    $record = $model::factory()->recycle([$owner, $workspace])->create();

    $matrix = [];

    foreach (['owner' => $owner, 'admin' => $admin, 'member' => $member, 'viewer' => $viewer, 'outsider' => $outsider] as $label => $user) {
        $actor = User::query()->findOrFail($user->getKey());

        foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
            $matrix["{$label}.{$ability}"] = $actor->can($ability, $record);
        }

        $matrix["{$label}.exportAny"] = $actor->can('exportAny', $model);
    }

    expect($matrix)->toBe([
        'owner.view' => true, 'owner.update' => true, 'owner.delete' => true,
        'owner.restore' => true, 'owner.forceDelete' => true, 'owner.exportAny' => true,

        'admin.view' => true, 'admin.update' => true, 'admin.delete' => true,
        'admin.restore' => true, 'admin.forceDelete' => true, 'admin.exportAny' => true,

        'member.view' => true, 'member.update' => true, 'member.delete' => true,
        'member.restore' => true, 'member.forceDelete' => false, 'member.exportAny' => true,

        'viewer.view' => true, 'viewer.update' => false, 'viewer.delete' => false,
        'viewer.restore' => false, 'viewer.forceDelete' => false, 'viewer.exportAny' => false,

        'outsider.view' => false, 'outsider.update' => false, 'outsider.delete' => false,
        'outsider.restore' => false, 'outsider.forceDelete' => false, 'outsider.exportAny' => false,
    ]);
})->with([
    'companies' => Company::class,
    'notes' => Note::class,
    'opportunities' => Opportunity::class,
    'people' => People::class,
    'tasks' => Task::class,
]);

it('holds no capability on a missing workspace', function (): void {
    $owner = User::factory()->withWorkspace()->create();

    expect($owner->hasWorkspaceCapability(null, WorkspaceCapability::RecordsView))->toBeFalse()
        ->and($owner->membershipRole(null))->toBeNull();
});

it('treats a membership carrying no role as not privileged', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $member = User::factory()->create();
    $workspace->users()->attach($member);

    $record = People::factory()->recycle([$owner, $workspace])->create();

    $actor = User::query()->findOrFail($member->getKey());

    expect($actor->can('view', $record))->toBeFalse()
        ->and($actor->can('forceDelete', $record))->toBeFalse();
});

it('denies a record belonging to another workspace', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $otherOwner = User::factory()->withWorkspace()->create();

    $foreignRecord = Company::factory()->recycle([$otherOwner, $otherOwner->currentWorkspace])->create();

    $actor = User::query()->findOrFail($owner->getKey());

    expect($actor->can('view', $foreignRecord))->toBeFalse()
        ->and($actor->can('update', $foreignRecord))->toBeFalse()
        ->and($actor->can('delete', $foreignRecord))->toBeFalse()
        ->and($actor->can('restore', $foreignRecord))->toBeFalse()
        ->and($actor->can('forceDelete', $foreignRecord))->toBeFalse();
});

it('denies a record pointing at a workspace that no longer exists', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;

    $dangling = Opportunity::factory()->recycle([$owner, $workspace])->create();
    $ghost = Workspace::factory()->create(['user_id' => $owner->getKey()]);
    $dangling->forceFill(['workspace_id' => $ghost->getKey()])->save();
    Workspace::query()->whereKey($ghost->getKey())->delete();

    $actor = User::query()->findOrFail($owner->getKey());

    expect($actor->can('view', $dangling))->toBeFalse()
        ->and($actor->can('update', $dangling))->toBeFalse();
});
