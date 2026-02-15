<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Resources\CompanyResource\Pages\ListCompanies;
use Relaticle\SystemAdmin\Filament\Resources\NoteResource\Pages\ListNotes;
use Relaticle\SystemAdmin\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource\Pages\ListPeople;
use Relaticle\SystemAdmin\Filament\Resources\TaskResource\Pages\ListTasks;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages\ListTeams;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\ListUsers;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

beforeEach(function () {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
});

it('can render the users list page', function () {
    $users = User::factory(3)->withPersonalTeam()->create();

    livewire(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords($users);
});

it('can render the teams list page', function () {
    $teams = Team::factory(3)->create();

    livewire(ListTeams::class)
        ->assertOk()
        ->assertCanSeeTableRecords($teams);
});

it('can render the companies list page', function () {
    $companies = Company::factory(3)->create();

    livewire(ListCompanies::class)
        ->assertOk()
        ->assertCanSeeTableRecords($companies);
});

it('can render the people list page', function () {
    $people = People::factory(3)->create();

    livewire(ListPeople::class)
        ->assertOk()
        ->assertCanSeeTableRecords($people);
});

it('can render the tasks list page', function () {
    $tasks = Task::factory(3)->create();

    livewire(ListTasks::class)
        ->assertOk()
        ->assertCanSeeTableRecords($tasks);
});

it('can render the notes list page', function () {
    $notes = Note::factory(3)->create();

    livewire(ListNotes::class)
        ->assertOk()
        ->assertCanSeeTableRecords($notes);
});

it('can render the opportunities list page', function () {
    $opportunities = Opportunity::factory(3)->create();

    livewire(ListOpportunities::class)
        ->assertOk()
        ->assertCanSeeTableRecords($opportunities);
});

it('has trashed filter on soft-deletable resources', function (string $listPageClass) {
    livewire($listPageClass)
        ->assertTableFilterExists('trashed');
})->with([
    'companies' => ListCompanies::class,
    'people' => ListPeople::class,
    'tasks' => ListTasks::class,
    'notes' => ListNotes::class,
    'opportunities' => ListOpportunities::class,
]);
