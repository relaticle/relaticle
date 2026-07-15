<?php

declare(strict_types=1);

use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\TeamScope;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages\ListActivities;
use Relaticle\SystemAdmin\Filament\Resources\ActivityResource\Pages\ViewActivity;
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

it('filters activity by team', function (): void {
    $a = seedActivity($this->teamA, $this->ownerA);
    $b = seedActivity($this->teamB, $this->ownerB);

    livewire(ListActivities::class)
        ->filterTable('team_id', $this->teamA->id)
        ->assertCanSeeTableRecords([$a])
        ->assertCanNotSeeTableRecords([$b]);
});

it('filters activity by event', function (): void {
    $created = seedActivity($this->teamA, $this->ownerA, ['event' => 'created', 'description' => 'created']);
    $deleted = seedActivity($this->teamA, $this->ownerA, ['event' => 'deleted', 'description' => 'deleted']);

    livewire(ListActivities::class)
        ->filterTable('event', 'deleted')
        ->assertCanSeeTableRecords([$deleted])
        ->assertCanNotSeeTableRecords([$created]);
});

it('filters activity by causer', function (): void {
    $otherUser = User::factory()->create();
    $mine = seedActivity($this->teamA, $this->ownerA, ['description' => 'by owner A']);
    $theirs = seedActivity($this->teamA, $otherUser, ['description' => 'by other user']);

    livewire(ListActivities::class)
        ->filterTable('causer', $this->ownerA->id)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('filters activity by date range', function (): void {
    $inRange = $this->travelTo('2026-06-15 12:00:00', fn (): Activity => seedActivity($this->teamA, $this->ownerA, ['description' => 'in range']));
    $outOfRange = $this->travelTo('2026-06-01 12:00:00', fn (): Activity => seedActivity($this->teamA, $this->ownerA, ['description' => 'out of range']));

    livewire(ListActivities::class)
        ->filterTable('created_at', ['from' => '2026-06-10', 'until' => '2026-06-20'])
        ->assertCanSeeTableRecords([$inRange])
        ->assertCanNotSeeTableRecords([$outOfRange]);
});

it('renders the view page with a standard attribute diff', function (): void {
    $activity = seedActivity($this->teamA, $this->ownerA, [
        'event' => 'updated',
        'description' => 'updated',
        'properties' => ['attributes' => ['name' => 'New Co'], 'old' => ['name' => 'Old Co']],
    ]);

    livewire(ViewActivity::class, [
        'record' => $activity->getKey(),
    ])
        ->assertOk()
        ->assertSee('Old Co')
        ->assertSee('New Co');
});

it('renders the view page for a custom-field-changes activity', function (): void {
    $activity = seedActivity($this->teamA, $this->ownerA, [
        'event' => 'custom_field_changes',
        'description' => 'custom_field_changes',
        'properties' => ['custom_field_changes' => [[
            'code' => 'priority',
            'label' => 'Priority',
            'type' => 'select',
            'old' => ['value' => null, 'label' => '—'],
            'new' => ['value' => 'high', 'label' => 'High'],
        ]]],
    ]);

    livewire(ViewActivity::class, [
        'record' => $activity->getKey(),
    ])
        ->assertOk()
        ->assertSee('Priority')
        ->assertSee('High');
});
