<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\View\Components\BadgeComponent;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\SystemAdmin\Actions\UpdateCustomerRecord;
use Relaticle\SystemAdmin\Filament\Pages\EditCustomerRecord;
use Relaticle\SystemAdmin\Filament\Resources\CompanyResource\Pages\EditCompany;
use Relaticle\SystemAdmin\Filament\Resources\CompanyResource\Pages\ListCompanies;
use Relaticle\SystemAdmin\Filament\Resources\CompanyResource\Pages\ViewCompany;
use Relaticle\SystemAdmin\Filament\Resources\ImportResource\Pages\ListImports;
use Relaticle\SystemAdmin\Filament\Resources\NoteResource\Pages\EditNote;
use Relaticle\SystemAdmin\Filament\Resources\NoteResource\Pages\ListNotes;
use Relaticle\SystemAdmin\Filament\Resources\NoteResource\Pages\ViewNote;
use Relaticle\SystemAdmin\Filament\Resources\OpportunityResource\Pages\EditOpportunity;
use Relaticle\SystemAdmin\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use Relaticle\SystemAdmin\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource\Pages\EditPeople;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource\Pages\ListPeople;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource\Pages\ViewPeople;
use Relaticle\SystemAdmin\Filament\Resources\TaskResource\Pages\EditTask;
use Relaticle\SystemAdmin\Filament\Resources\TaskResource\Pages\ListTasks;
use Relaticle\SystemAdmin\Filament\Resources\TaskResource\Pages\ViewTask;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\ListUsers;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(UpdateCustomerRecord::class, EditCustomerRecord::class, User::class, Workspace::class, Company::class, People::class, Task::class, Note::class, Opportunity::class);

beforeEach(function () {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');

    $this->workspaceOwner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->workspaceOwner->currentWorkspace;
});

dataset('customer record edit pages', [
    'company' => [Company::class, EditCompany::class, 'name'],
    'person' => [People::class, EditPeople::class, 'name'],
    'opportunity' => [Opportunity::class, EditOpportunity::class, 'name'],
    'task' => [Task::class, EditTask::class, 'title'],
    'note' => [Note::class, EditNote::class, 'title'],
]);

it('rejects administrator moves of customer records between workspaces', function (string $modelClass, string $pageClass): void {
    $this->actingAs(SystemAdministrator::factory()->administrator()->create(), 'sysadmin');
    $record = $modelClass::factory()->for($this->workspace)->create(['creator_id' => $this->workspaceOwner->getKey()]);
    $target = Workspace::factory()->create();

    livewire($pageClass, ['record' => $record->getKey()])
        ->set('data.workspace_id', $target->getKey())
        ->call('save')
        ->assertForbidden();

    expect($record->refresh()->workspace_id)->toBe($this->workspace->getKey());
})->with('customer record edit pages');

it('lets administrators edit ordinary customer records', function (string $modelClass, string $pageClass, string $field): void {
    $this->actingAs(SystemAdministrator::factory()->administrator()->create(), 'sysadmin');
    $record = $modelClass::factory()->for($this->workspace)->create(['creator_id' => $this->workspaceOwner->getKey()]);

    livewire($pageClass, ['record' => $record->getKey()])
        ->fillForm([$field => 'Updated Customer Record'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->refresh()->getAttribute($field))->toBe('Updated Customer Record')
        ->and($record->workspace_id)->toBe($this->workspace->getKey());
})->with('customer record edit pages');

it('lets super administrators move customer records between workspaces', function (string $modelClass, string $pageClass): void {
    $record = $modelClass::factory()->for($this->workspace)->create(['creator_id' => $this->workspaceOwner->getKey()]);
    $target = Workspace::factory()->create();

    livewire($pageClass, ['record' => $record->getKey()])
        ->fillForm(['workspace_id' => $target->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->refresh()->workspace_id)->toBe($target->getKey());
})->with('customer record edit pages');

it('does not move customer records through navigation actions', function (string $modelClass, string $listPageClass, string $viewPageClass, bool $fromTable): void {
    $this->actingAs(SystemAdministrator::factory()->administrator()->create(), 'sysadmin');
    $record = $modelClass::factory()->for($this->workspace)->create(['creator_id' => $this->workspaceOwner->getKey()]);
    $target = Workspace::factory()->create();
    $action = TestAction::make('edit');

    if ($fromTable) {
        $action->table($record);
    }

    livewire($fromTable ? $listPageClass : $viewPageClass, ['record' => $record->getKey()])
        ->callAction($action, data: ['workspace_id' => $target->getKey()])
        ->assertHasNoActionErrors();

    expect($record->refresh()->workspace_id)->toBe($this->workspace->getKey());
})->with([
    'company' => [Company::class, ListCompanies::class, ViewCompany::class],
    'person' => [People::class, ListPeople::class, ViewPeople::class],
    'opportunity' => [Opportunity::class, ListOpportunities::class, ViewOpportunity::class],
    'task' => [Task::class, ListTasks::class, ViewTask::class],
    'note' => [Note::class, ListNotes::class, ViewNote::class],
])->with(['list' => true, 'view' => false]);

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

it('renders every source badge with real shade classes on the companies list', function () {
    $this->get(ListCompanies::getUrl())->assertSuccessful();

    foreach (CreationSource::cases() as $source) {
        $classes = FilamentColor::getComponentClasses(BadgeComponent::class, $source->getColor());

        if ($source->getColor() === 'gray') {
            expect($classes)->toBe([]);

            continue;
        }

        expect(array_filter($classes, fn (string $class): bool => str_starts_with($class, 'fi-text-color-')))
            ->not->toBeEmpty();
    }
});
