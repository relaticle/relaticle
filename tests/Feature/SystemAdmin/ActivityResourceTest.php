<?php

declare(strict_types=1);

use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\TeamScope;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages\ListActivities;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

/**
 * @param  array<string, mixed>  $attributes
 */
function seedActivity(Team $team, User $causer, array $attributes = []): Activity
{
    return Activity::withoutGlobalScope(TeamScope::class)->create(array_merge([
        'log_name' => 'crm',
        'description' => 'created',
        'event' => 'created',
        'subject_type' => 'company',
        'subject_id' => Company::withoutEvents(fn (): Company => Company::factory()->create())->id,
        'causer_type' => 'user',
        'causer_id' => $causer->id,
        'team_id' => $team->id,
        'properties' => [],
    ], $attributes));
}

mutates(Activity::class);

beforeEach(function (): void {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');

    $this->ownerA = User::factory()->withTeam()->create();
    $this->teamA = $this->ownerA->currentTeam;
    $this->ownerB = User::factory()->withTeam()->create();
    $this->teamB = $this->ownerB->currentTeam;
});

it('shows activity across all tenants on the list page', function (): void {
    $a = seedActivity($this->teamA, $this->ownerA, ['description' => 'created company A']);
    $b = seedActivity($this->teamB, $this->ownerB, ['description' => 'created company B']);

    livewire(ListActivities::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$a, $b]);
});
