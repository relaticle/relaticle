<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\SystemAdmin\Filament\Resources\CompanyResource\Pages\ListCompanies;
use Relaticle\SystemAdmin\Filament\Resources\CompanyResource\Pages\ViewCompany;
use Relaticle\SystemAdmin\Filament\Resources\ImportResource\Pages\ListImports;
use Relaticle\SystemAdmin\Filament\Resources\NoteResource\Pages\ListNotes;
use Relaticle\SystemAdmin\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource\Pages\ListPeople;
use Relaticle\SystemAdmin\Filament\Resources\TaskResource\Pages\ListTasks;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\ListUsers;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(User::class, Workspace::class, Company::class, People::class, Task::class, Note::class, Opportunity::class);

beforeEach(function () {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');

    $this->workspaceOwner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->workspaceOwner->currentWorkspace;
});

it('can render the users list page', function () {
    $users = User::factory(3)->withWorkspace()->create();

    livewire(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords($users);
});

it('can render the workspaces list page', function () {
    $workspaces = Workspace::factory(3)->create();

    livewire(ListWorkspaces::class)
        ->assertOk()
        ->assertCanSeeTableRecords($workspaces);
});

it('can render the companies list page', function () {
    $companies = Company::withoutEvents(fn () => Company::factory(3)
        ->for($this->workspace)
        ->create(['creator_id' => $this->workspaceOwner->id]));

    livewire(ListCompanies::class)
        ->assertOk()
        ->assertCanSeeTableRecords($companies);
});

it('can render the people list page', function () {
    $people = People::withoutEvents(fn () => People::factory(3)
        ->for($this->workspace)
        ->create());

    livewire(ListPeople::class)
        ->assertOk()
        ->assertCanSeeTableRecords($people);
});

it('can render the tasks list page', function () {
    $tasks = Task::withoutEvents(fn () => Task::factory(3)
        ->for($this->workspace)
        ->create(['creator_id' => $this->workspaceOwner->id]));

    livewire(ListTasks::class)
        ->assertOk()
        ->assertCanSeeTableRecords($tasks);
});

it('can render the notes list page', function () {
    $notes = Note::withoutEvents(fn () => Note::factory(3)
        ->for($this->workspace)
        ->create());

    livewire(ListNotes::class)
        ->assertOk()
        ->assertCanSeeTableRecords($notes);
});

it('can render the opportunities list page', function () {
    $opportunities = Opportunity::withoutEvents(fn () => Opportunity::factory(3)
        ->for($this->workspace)
        ->create());

    livewire(ListOpportunities::class)
        ->assertOk()
        ->assertCanSeeTableRecords($opportunities);
});

it('can render the imports list page', function () {
    $imports = collect(range(1, 3))->map(fn () => Import::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->workspaceOwner->id,
        'entity_type' => ImportEntityType::Company,
        'file_name' => 'test.csv',
        'status' => ImportStatus::Completed,
        'total_rows' => 10,
        'created_rows' => 8,
        'failed_rows' => 2,
        'headers' => ['name', 'email'],
        'column_mappings' => [],
    ]));

    livewire(ListImports::class)
        ->assertOk()
        ->assertCanSeeTableRecords($imports);
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

it('can open the view page of a soft deleted company', function () {
    $company = Company::withoutEvents(fn (): Company => Company::factory()
        ->for($this->workspace)
        ->create(['creator_id' => $this->workspaceOwner->id]));

    $company->delete();

    livewire(ViewCompany::class, ['record' => $company->id])
        ->assertOk();
});

it('links relation columns to the related record', function () {
    Company::withoutEvents(fn (): Company => Company::factory()
        ->for($this->workspace)
        ->create(['creator_id' => $this->workspaceOwner->id]));

    livewire(ListCompanies::class)
        ->assertOk()
        ->assertSee(WorkspaceResource::getUrl('view', ['record' => $this->workspace->id]), escape: false)
        ->assertSee(UserResource::getUrl('view', ['record' => $this->workspaceOwner->id]), escape: false);
});

it('does not link a relation column when the related record is missing', function () {
    Company::withoutEvents(fn (): Company => Company::factory()
        ->for($this->workspace)
        ->create(['creator_id' => null]));

    livewire(ListCompanies::class)
        ->assertOk()
        ->assertDontSee(UserResource::getUrl('view', ['record' => $this->workspaceOwner->id]), escape: false);
});
