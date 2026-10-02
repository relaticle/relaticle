<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Filament\Concerns\CountsRelatedRecords;
use App\Filament\Concerns\HasRecordPageLayout;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\NotesRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\PeopleRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\TasksRelationManager;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\OpportunityResource\RelationManagers\NotesRelationManager as OpportunityNotesRelationManager;
use App\Filament\Resources\OpportunityResource\RelationManagers\TasksRelationManager as OpportunityTasksRelationManager;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\NotesRelationManager as PeopleNotesRelationManager;
use App\Filament\Resources\PeopleResource\RelationManagers\TasksRelationManager as PeopleTasksRelationManager;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(HasRecordPageLayout::class, CountsRelatedRecords::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());
});

function railAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('recordActions', schema: 'infolist');
}

dataset('record pages', [
    'company' => [Company::class, ViewCompany::class, CompanyResource::class, 'Companies'],
    'person' => [People::class, ViewPeople::class, PeopleResource::class, 'People'],
    'opportunity' => [Opportunity::class, ViewOpportunity::class, OpportunityResource::class, 'Opportunities'],
]);

it('titles the :dataset page with the record name under a link back to its list', function (string $model, string $page, string $resource, string $listLabel): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Northwind Traders']);

    livewire($page, ['record' => $record->getKey()])
        ->assertSeeHtml('href="'.$resource::getUrl('index').'"')
        ->assertSeeInOrder([$listLabel, 'Northwind Traders'])
        ->assertDontSee('View Northwind Traders');
})->with('record pages');

it('marks the :dataset breadcrumb with its record type icon instead of the title', function (string $model, string $page): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();

    livewire($page, ['record' => $record->getKey()])
        ->assertSeeHtml('fi-record-breadcrumb-icon')
        ->assertDontSeeHtml('fi-topbar-page-icon');
})->with('record pages');

it('puts the record type icon before the :dataset title', function (string $page): void {
    livewire($page)
        ->assertSeeHtmlInOrder(['fi-topbar-page-icon', 'fi-topbar-page-title']);
})->with([
    'company list' => ListCompanies::class,
    'people list' => ListPeople::class,
    'opportunity list' => ListOpportunities::class,
    'opportunity board' => OpportunitiesBoard::class,
    'task list' => ManageTasks::class,
    'task board' => TasksBoard::class,
    'note list' => ManageNotes::class,
]);

it('offers edit, copy and delete from the details rail on the :dataset page', function (string $model, string $page): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();

    livewire($page, ['record' => $record->getKey()])
        ->assertActionExists(railAction('edit'))
        ->assertActionExists(railAction('copyPageUrl'))
        ->assertActionExists(railAction('copyRecordId'))
        ->assertActionExists(railAction('delete'));
})->with('record pages');

it('marks custom field labels with the same outline icon set as the native details', function (): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $iconPath = fn (string $icon): string => Str::match('/ d="([^"]+)"/', svg($icon)->contents());

    livewire(ViewOpportunity::class, ['record' => $opportunity->getKey()])
        ->assertSeeHtml($iconPath('heroicon-o-chevron-up-down'))
        ->assertDontSeeHtml($iconPath('mdi-form-select'))
        ->assertDontSeeHtml($iconPath('mdi-calendar'));
});

it('credits a system created record to the system in the record info', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create([
        'creation_source' => CreationSource::SYSTEM,
    ]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSee('⊙ System');
});

it('credits a record whose creator deleted their account to a former member in the record info', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $company->forceFill(['creator_id' => null])->saveQuietly();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSee('Former Member');
});

it('deletes the record from the rail and returns to the list', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction(railAction('delete'))
        ->assertRedirect(CompanyResource::getUrl('index'));

    expect($company->fresh()->trashed())->toBeTrue();
});

it('shows a custom field saved through the rail edit without a reload', function (): void {
    CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'opportunity',
        'type' => 'text',
        'code' => 'deal_code',
        'name' => 'Deal code',
    ]);

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create([
        'custom_fields' => ['deal_code' => 'OLD-001'],
    ]);

    livewire(ViewOpportunity::class, ['record' => $opportunity->getKey()])
        ->assertSee('OLD-001')
        ->callAction(railAction('edit'), data: ['custom_fields' => ['deal_code' => 'NEW-002']])
        ->assertHasNoActionErrors()
        ->assertSee('NEW-002')
        ->assertDontSee('OLD-001');
});

it('keeps details past the first eight behind a view all toggle', function (): void {
    foreach (range(1, 8) as $position) {
        CustomField::factory()->create([
            'tenant_id' => $this->workspace->getKey(),
            'entity_type' => 'company',
            'type' => 'text',
            'code' => "extra_{$position}",
            'name' => "Extra detail {$position}",
            'sort_order' => 100 + $position,
        ]);
    }

    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSee('View all')
        ->assertSeeHtml('x-show="showAllDetails"')
        ->assertSee('Extra detail 8');
});

it('shows no view all toggle when every detail fits', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertDontSee('View all');
});

it('orders the :dataset page tabs as tasks, notes, emails, meetings, then the activity log', function (string $model, string $page): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();

    $tabs = collect(livewire($page, ['record' => $record->getKey()])->instance()->getRelationManagers())
        ->map(fn (string $manager): string => class_basename($manager))
        ->reject(fn (string $manager): bool => $manager === 'PeopleRelationManager')
        ->values()
        ->all();

    expect($tabs)->toBe([
        'TasksRelationManager',
        'NotesRelationManager',
        'EmailsRelationManager',
        'MeetingsRelationManager',
        'ActivityLogRelationManager',
    ]);
})->with('record pages');

it('puts the people tab first on the company page', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    $tabs = livewire(ViewCompany::class, ['record' => $company->getKey()])->instance()->getRelationManagers();

    expect(class_basename(reset($tabs)))->toBe('PeopleRelationManager');
});

it('puts a keyboard reachable resize handle between the details rail and the work pane on the :dataset page', function (string $model, string $page): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();

    livewire($page, ['record' => $record->getKey()])
        ->assertSeeInOrder(['fi-record-rail', 'fi-record-rail-resize-handle', 'fi-record-pane'])
        ->assertSeeHtml('role="separator"')
        ->assertSeeHtml('aria-label="'.__('filament/record-page.resize_details').'"');
})->with('record pages');

it('counts related records on the work pane tabs and omits empty counts', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    People::factory()->count(2)->recycle([$this->user, $this->workspace])->create(['company_id' => $company->getKey()]);

    expect(PeopleRelationManager::getBadge($company, ViewCompany::class))->toBe('2')
        ->and(TasksRelationManager::getBadge($company, ViewCompany::class))->toBeNull();
});

it('tells the record page to refresh its tab counts after a related record changes', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(NotesRelationManager::class, ['ownerRecord' => $company, 'pageClass' => ViewCompany::class])
        ->callAction(TestAction::make('create')->table(), data: ['title' => 'Kickoff notes'])
        ->assertHasNoActionErrors()
        ->assertDispatched('related-records-changed');

    expect(NotesRelationManager::getBadge($company, ViewCompany::class))->toBe('1');
});

it('lists people on the company page by name, job title and email only', function (): void {
    CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'people',
        'type' => 'text',
        'code' => 'nickname',
        'name' => 'Nickname',
    ]);

    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(PeopleRelationManager::class, ['ownerRecord' => $company, 'pageClass' => ViewCompany::class])
        ->assertTableColumnExists('name')
        ->assertTableColumnExists('custom_fields.job_title')
        ->assertTableColumnExists('custom_fields.emails')
        ->assertTableColumnDoesNotExist('custom_fields.phone_number')
        ->assertTableColumnDoesNotExist('custom_fields.linkedin')
        ->assertTableColumnDoesNotExist('custom_fields.nickname');
});

it('lists tasks on the :dataset page by title, status, due date and assignee only', function (string $model, string $page, string $relationManager): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();

    livewire($relationManager, ['ownerRecord' => $record, 'pageClass' => $page])
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('custom_fields.status')
        ->assertTableColumnExists('custom_fields.due_date')
        ->assertTableColumnExists('assignees.name')
        ->assertTableColumnDoesNotExist('custom_fields.priority')
        ->assertTableColumnDoesNotExist('custom_fields.description')
        ->assertTableColumnDoesNotExist('people.name');
})->with([
    'company' => [Company::class, ViewCompany::class, TasksRelationManager::class],
    'person' => [People::class, ViewPeople::class, PeopleTasksRelationManager::class],
    'opportunity' => [Opportunity::class, ViewOpportunity::class, OpportunityTasksRelationManager::class],
]);

it('lists notes on the :dataset page by title and creation date only', function (string $model, string $page, string $relationManager): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();

    livewire($relationManager, ['ownerRecord' => $record, 'pageClass' => $page])
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('created_at')
        ->assertTableColumnDoesNotExist('custom_fields.body')
        ->assertTableColumnDoesNotExist('people.name');
})->with([
    'company' => [Company::class, ViewCompany::class, NotesRelationManager::class],
    'person' => [People::class, ViewPeople::class, PeopleNotesRelationManager::class],
    'opportunity' => [Opportunity::class, ViewOpportunity::class, OpportunityNotesRelationManager::class],
]);

it('shows a related list field even when its list column is hidden by default', function (): void {
    CustomField::query()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'people')
        ->where('code', 'job_title')
        ->firstOrFail()
        ->update(['settings->list_toggleable_hidden' => true]);

    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    People::factory()->recycle([$this->user, $this->workspace])->create([
        'company_id' => $company->getKey(),
        'custom_fields' => ['job_title' => 'Head of Partnerships'],
    ]);

    livewire(PeopleRelationManager::class, ['ownerRecord' => $company, 'pageClass' => ViewCompany::class])
        ->assertSee('Head of Partnerships');
});

it('offers no column picker on the :dataset page tabs', function (string $model, string $page, string $relationManager): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();

    livewire($relationManager, ['ownerRecord' => $record, 'pageClass' => $page])
        ->assertDontSeeHtml('fi-ta-col-manager');
})->with([
    'company people' => [Company::class, ViewCompany::class, PeopleRelationManager::class],
    'company tasks' => [Company::class, ViewCompany::class, TasksRelationManager::class],
    'person notes' => [People::class, ViewPeople::class, PeopleNotesRelationManager::class],
    'opportunity tasks' => [Opportunity::class, ViewOpportunity::class, OpportunityTasksRelationManager::class],
]);
