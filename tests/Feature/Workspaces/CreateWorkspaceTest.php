<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Jetstream\Http\Livewire\CreateTeamForm;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

mutates(Workspace::class);

test('workspaces can be created', function () {
    $this->actingAs($user = User::factory()->withPersonalWorkspace()->create());

    Livewire::test(CreateTeamForm::class)
        ->set(['state' => ['name' => 'Test Workspace', 'slug' => 'test-workspace']])
        ->call('createTeam');

    expect($user->fresh()->ownedWorkspaces)->toHaveCount(2);
    expect($user->fresh()->ownedWorkspaces()->where('name', 'Test Workspace')->exists())->toBeTrue();
});

test('reserved slug is rejected on workspace creation', function () {
    $this->actingAs(User::factory()->withPersonalWorkspace()->create());

    Livewire::test(CreateTeamForm::class)
        ->set(['state' => ['name' => 'Admin Workspace', 'slug' => 'admin']])
        ->call('createTeam')
        ->assertHasErrors('slug');
});

test('non-personal workspaces do not get demo data seeded', function (): void {
    Feature::define(OnboardSeed::class, true);

    $user = User::factory()->withPersonalWorkspace()->create();

    $workWorkspace = onboardWorkspace($user, ['name' => 'Work Workspace']);

    expect($workWorkspace->personal_workspace)->toBeFalse()
        ->and(Company::where('workspace_id', $workWorkspace->id)->count())->toBe(0)
        ->and(People::where('workspace_id', $workWorkspace->id)->count())->toBe(0)
        ->and(Opportunity::where('workspace_id', $workWorkspace->id)->count())->toBe(0)
        ->and(Task::where('workspace_id', $workWorkspace->id)->count())->toBe(0)
        ->and(Note::where('workspace_id', $workWorkspace->id)->count())->toBe(0);
});
