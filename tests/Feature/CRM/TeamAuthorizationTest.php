<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\NotePolicy;
use App\Policies\OpportunityPolicy;
use App\Policies\PeoplePolicy;
use App\Policies\TaskPolicy;

mutates(CompanyPolicy::class, NotePolicy::class, OpportunityPolicy::class, PeoplePolicy::class, TaskPolicy::class, User::class);

it('authorizes :dataset by team membership and role', function (string $model): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $admin = User::factory()->create();
    $team->users()->attach($admin, ['role' => TeamRole::Admin->value]);

    $editor = User::factory()->create();
    $team->users()->attach($editor, ['role' => TeamRole::Editor->value]);

    $outsider = User::factory()->withTeam()->create();

    $record = $model::factory()->recycle([$owner, $team])->create();

    $matrix = [];

    foreach (['owner' => $owner, 'admin' => $admin, 'editor' => $editor, 'outsider' => $outsider] as $label => $user) {
        $actor = User::query()->findOrFail($user->getKey());

        foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
            $matrix["{$label}.{$ability}"] = $actor->can($ability, $record);
        }
    }

    expect($matrix)->toBe([
        'owner.view' => true,
        'owner.update' => true,
        'owner.delete' => true,
        'owner.restore' => true,
        'owner.forceDelete' => true,

        'admin.view' => true,
        'admin.update' => true,
        'admin.delete' => true,
        'admin.restore' => true,
        'admin.forceDelete' => true,

        'editor.view' => true,
        'editor.update' => true,
        'editor.delete' => true,
        'editor.restore' => true,
        'editor.forceDelete' => false,

        'outsider.view' => false,
        'outsider.update' => false,
        'outsider.delete' => false,
        'outsider.restore' => false,
        'outsider.forceDelete' => false,
    ]);
})->with([
    'people' => People::class,
    'tasks' => Task::class,
]);

it('treats a membership carrying no role as not privileged', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $member = User::factory()->create();
    $team->users()->attach($member);

    $record = People::factory()->recycle([$owner, $team])->create();

    $actor = User::query()->findOrFail($member->getKey());

    expect($actor->can('view', $record))->toBeTrue()
        ->and($actor->can('forceDelete', $record))->toBeFalse();
});

it('denies a record belonging to another team', function (): void {
    $owner = User::factory()->withTeam()->create();
    $otherOwner = User::factory()->withTeam()->create();

    $foreignRecord = Company::factory()->recycle([$otherOwner, $otherOwner->currentTeam])->create();

    $actor = User::query()->findOrFail($owner->getKey());

    expect($actor->can('view', $foreignRecord))->toBeFalse()
        ->and($actor->can('update', $foreignRecord))->toBeFalse()
        ->and($actor->can('delete', $foreignRecord))->toBeFalse();
});

it('denies a record pointing at a team that no longer exists', function (): void {
    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $dangling = Opportunity::factory()->recycle([$owner, $team])->create();
    $ghost = Team::factory()->create(['user_id' => $owner->getKey()]);
    $dangling->forceFill(['team_id' => $ghost->getKey()])->save();
    Team::query()->whereKey($ghost->getKey())->delete();

    $actor = User::query()->findOrFail($owner->getKey());

    expect($actor->can('view', $dangling))->toBeFalse()
        ->and($actor->can('update', $dangling))->toBeFalse();
});
