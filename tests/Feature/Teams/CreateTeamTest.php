<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Http\Livewire\CreateTeamForm;
use Livewire\Livewire;

test('teams can be created', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    Livewire::test(CreateTeamForm::class)
        ->set(['state' => ['name' => 'Test Team']])
        ->call('createTeam');

    expect($user->fresh()->ownedTeams)->toHaveCount(2);
    expect($user->fresh()->ownedTeams()->where('name', 'Test Team')->exists())->toBeTrue();
});

test('non-personal teams do not get demo data seeded', function (): void {
    Event::fake()->except([
        'eloquent.creating: App\\Models\\Team',
        TeamCreated::class,
    ]);

    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

    Livewire::test(CreateTeamForm::class)
        ->set(['state' => ['name' => 'Work Team']])
        ->call('createTeam');

    $workTeam = $user->fresh()->ownedTeams()->where('name', 'Work Team')->first();
    expect($workTeam)->not->toBeNull()
        ->and($workTeam->personal_team)->toBeFalse();

    expect(Company::where('team_id', $workTeam->id)->count())->toBe(0)
        ->and(People::where('team_id', $workTeam->id)->count())->toBe(0)
        ->and(Opportunity::where('team_id', $workTeam->id)->count())->toBe(0)
        ->and(Task::where('team_id', $workTeam->id)->count())->toBe(0)
        ->and(Note::where('team_id', $workTeam->id)->count())->toBe(0);
});
