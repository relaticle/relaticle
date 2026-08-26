<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\EntityLinkResolver;
use Tests\Helpers\ImportExecutionFixture;

mutates(ExecuteImportJob::class, EntityLinkResolver::class);

beforeEach(function (): void {
    Event::fake()->except([TeamCreated::class]);

    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;

    Filament::setTenant($this->team);
});

afterEach(function (): void {
    if (isset($this->import)) {
        ImportStore::load($this->import->id)?->destroy();
        $this->import->delete();
    }
});

// --- Entity-Specific Importer Tests ---

it('imports company with account_owner resolved by email via entity link', function (): void {
    $owner = User::factory()->create();
    $this->team->users()->attach($owner, ['role' => 'editor']);

    $relationships = json_encode([
        ['relationship' => 'account_owner', 'action' => 'update', 'id' => (string) $owner->id, 'name' => null],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Owner Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'Test Corp', 'Owner Email' => $owner->email], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Owner Email', matcherKey: 'email', entityLinkKey: 'account_owner'),
    ], ImportEntityType::Company);

    ImportExecutionFixture::run($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'Test Corp')->first();
    expect($company)->not->toBeNull()
        ->and((string) $company->account_owner_id)->toBe((string) $owner->id);
});

it('imports company with unmatched account_owner email skipping silently', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name', 'Owner Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'Test Corp', 'Owner Email' => 'nonexistent@example.com'], [
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Owner Email', matcherKey: 'email', entityLinkKey: 'account_owner'),
    ], ImportEntityType::Company);

    ImportExecutionFixture::run($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'Test Corp')->first();
    expect($company)->not->toBeNull()
        ->and($company->account_owner_id)->toBeNull();
});

it('imports company with account_owner resolved for team owner', function (): void {
    $relationships = json_encode([
        ['relationship' => 'account_owner', 'action' => 'update', 'id' => (string) $this->user->id, 'name' => null],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Owner Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'Owner Corp', 'Owner Email' => $this->user->email], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Owner Email', matcherKey: 'email', entityLinkKey: 'account_owner'),
    ], ImportEntityType::Company);

    ImportExecutionFixture::run($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'Owner Corp')->first();
    expect($company)->not->toBeNull()
        ->and((string) $company->account_owner_id)->toBe((string) $this->user->id);
});

it('imports task with assignee resolved by email via entity link', function (): void {
    $assignee = User::factory()->create();
    $this->team->users()->attach($assignee, ['role' => 'editor']);

    $relationships = json_encode([
        ['relationship' => 'assignees', 'action' => 'update', 'id' => (string) $assignee->id, 'name' => null],
    ]);

    ImportExecutionFixture::readyStore($this, ['Title', 'Assignee Email'], [
        ImportExecutionFixture::row(2, ['Title' => 'Test Task', 'Assignee Email' => $assignee->email], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Assignee Email', matcherKey: 'email', entityLinkKey: 'assignees'),
    ], ImportEntityType::Task);

    ImportExecutionFixture::run($this);

    $task = Task::where('team_id', $this->team->id)->where('title', 'Test Task')->first();
    expect($task)->not->toBeNull();

    $assigneeIds = $task->assignees()->pluck('users.id')->map(fn ($id) => (string) $id)->all();
    expect($assigneeIds)->toContain((string) $assignee->id);
});

it('imports task with unmatched assignee email skipping silently', function (): void {
    ImportExecutionFixture::readyStore($this, ['Title', 'Assignee Email'], [
        ImportExecutionFixture::row(2, ['Title' => 'Orphan Task', 'Assignee Email' => 'ghost@nowhere.com'], [
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Assignee Email', matcherKey: 'email', entityLinkKey: 'assignees'),
    ], ImportEntityType::Task);

    ImportExecutionFixture::run($this);

    $task = Task::where('team_id', $this->team->id)->where('title', 'Orphan Task')->first();
    expect($task)->not->toBeNull()
        ->and($task->assignees()->count())->toBe(0);
});

it('imports opportunity with company and contact entity links', function (): void {
    $company = Company::factory()->create(['name' => 'Deal Corp', 'team_id' => $this->team->id]);
    $contact = People::factory()->create(['name' => 'Deal Contact', 'team_id' => $this->team->id]);

    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'update', 'id' => (string) $company->id, 'name' => null],
        ['relationship' => 'contact', 'action' => 'update', 'id' => (string) $contact->id, 'name' => null],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Company', 'Contact'], [
        ImportExecutionFixture::row(2, ['Name' => 'Big Deal', 'Company' => 'Deal Corp', 'Contact' => 'Deal Contact'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
        ColumnData::toEntityLink(source: 'Contact', matcherKey: 'name', entityLinkKey: 'contact'),
    ], ImportEntityType::Opportunity);

    ImportExecutionFixture::run($this);

    $opportunity = Opportunity::where('team_id', $this->team->id)->where('name', 'Big Deal')->first();
    expect($opportunity)->not->toBeNull()
        ->and((string) $opportunity->company_id)->toBe((string) $company->id)
        ->and((string) $opportunity->contact_id)->toBe((string) $contact->id);
});

it('imports note with polymorphic entity links to company and person', function (): void {
    $company = Company::factory()->create(['name' => 'Note Corp', 'team_id' => $this->team->id]);
    $person = People::factory()->create(['name' => 'Note Person', 'team_id' => $this->team->id]);

    $relationships = json_encode([
        ['relationship' => 'companies', 'action' => 'update', 'id' => (string) $company->id, 'name' => null],
        ['relationship' => 'people', 'action' => 'update', 'id' => (string) $person->id, 'name' => null],
    ]);

    ImportExecutionFixture::readyStore($this, ['Title', 'Company', 'Person'], [
        ImportExecutionFixture::row(2, ['Title' => 'Meeting Notes', 'Company' => 'Note Corp', 'Person' => 'Note Person'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'companies'),
        ColumnData::toEntityLink(source: 'Person', matcherKey: 'name', entityLinkKey: 'people'),
    ], ImportEntityType::Note);

    ImportExecutionFixture::run($this);

    $note = Note::where('team_id', $this->team->id)->where('title', 'Meeting Notes')->first();
    expect($note)->not->toBeNull();

    expect($note->companies()->pluck('companies.id')->map(fn ($id) => (string) $id)->all())
        ->toContain((string) $company->id);

    expect($note->people()->pluck('people.id')->map(fn ($id) => (string) $id)->all())
        ->toContain((string) $person->id);
});

it('imports note with title field only', function (): void {
    ImportExecutionFixture::readyStore($this, ['Title'], [
        ImportExecutionFixture::row(2, ['Title' => 'Quick note'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
    ], ImportEntityType::Note);

    ImportExecutionFixture::run($this);

    $note = Note::where('team_id', $this->team->id)->where('title', 'Quick note')->first();
    expect($note)->not->toBeNull()
        ->and($note->creation_source)->toBe(CreationSource::IMPORT);
});

it('imports task with custom field values for select fields', function (): void {
    $statusCf = ImportExecutionFixture::customField($this, 'task_status', 'select', 'task', ['To do', 'In progress', 'Done']);
    $priorityCf = ImportExecutionFixture::customField($this, 'task_priority', 'select', 'task', ['Low', 'Medium', 'High']);
    $inProgressOption = $statusCf->options->firstWhere('name', 'In progress');
    $highOption = $priorityCf->options->firstWhere('name', 'High');

    ImportExecutionFixture::readyStore($this, ['Title', 'Status', 'Priority'], [
        ImportExecutionFixture::row(2, ['Title' => 'Urgent Task', 'Status' => 'In progress', 'Priority' => 'High'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toField(source: 'Status', target: "custom_fields_{$statusCf->code}"),
        ColumnData::toField(source: 'Priority', target: "custom_fields_{$priorityCf->code}"),
    ], ImportEntityType::Task);

    ImportExecutionFixture::run($this);

    $task = Task::where('team_id', $this->team->id)->where('title', 'Urgent Task')->first();
    expect($task)->not->toBeNull();

    $statusCfv = ImportExecutionFixture::customFieldValue($this, (string) $task->id, (string) $statusCf->id);
    expect($statusCfv)->not->toBeNull()
        ->and($statusCfv->string_value)->toBe((string) $inProgressOption->id);

    $priorityCfv = ImportExecutionFixture::customFieldValue($this, (string) $task->id, (string) $priorityCf->id);
    expect($priorityCfv)->not->toBeNull()
        ->and($priorityCfv->string_value)->toBe((string) $highOption->id);
});

it('imports company with custom field values for toggle and link', function (): void {
    $icpCf = ImportExecutionFixture::customField($this, 'is_icp', 'toggle', 'company');
    $linkedinCf = ImportExecutionFixture::customField($this, 'linkedin_url', 'link', 'company');

    ImportExecutionFixture::readyStore($this, ['Name', 'ICP', 'LinkedIn'], [
        ImportExecutionFixture::row(2, ['Name' => 'Great Corp', 'ICP' => 'true', 'LinkedIn' => 'https://linkedin.com/company/great'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'ICP', target: "custom_fields_{$icpCf->code}"),
        ColumnData::toField(source: 'LinkedIn', target: "custom_fields_{$linkedinCf->code}"),
    ], ImportEntityType::Company);

    ImportExecutionFixture::run($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'Great Corp')->first();
    expect($company)->not->toBeNull();

    $icpCfv = ImportExecutionFixture::customFieldValue($this, (string) $company->id, (string) $icpCf->id);
    expect($icpCfv)->not->toBeNull()
        ->and($icpCfv->boolean_value)->toBeTrue();

    $linkedinCfv = ImportExecutionFixture::customFieldValue($this, (string) $company->id, (string) $linkedinCf->id);
    expect($linkedinCfv)->not->toBeNull()
        ->and(collect($linkedinCfv->json_value)->all())->toContain('https://linkedin.com/company/great');
});
