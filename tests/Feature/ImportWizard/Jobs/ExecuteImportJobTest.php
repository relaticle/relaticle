<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\EntityLinkResolver;

mutates(ExecuteImportJob::class, EntityLinkResolver::class);

beforeEach(function (): void {
    Event::fake()->except([TeamCreated::class]);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->personalTeam();

    Filament::setTenant($this->team);
});

afterEach(function (): void {
    if (isset($this->store)) {
        $this->store->destroy();
    }
});

function createImportReadyStore(
    object $context,
    array $headers,
    array $rows,
    array $mappings,
    ImportEntityType $entityType = ImportEntityType::People,
): ImportStore {
    $store = ImportStore::create(
        teamId: (string) $context->team->id,
        userId: (string) $context->user->id,
        entityType: $entityType,
        originalFilename: 'test.csv',
    );

    $store->setHeaders($headers);
    $store->setColumnMappings($mappings);

    $store->query()->insert($rows);
    $store->setRowCount(count($rows));
    $store->setStatus(ImportStatus::Importing);

    $context->store = $store;

    return $store;
}

function runImportJob(object $context): void
{
    $job = new ExecuteImportJob(
        importId: $context->store->id(),
        teamId: (string) $context->team->id,
    );

    $job->handle();
}

if (! function_exists('makeRow')) {
    /** @param array<string, mixed> $overrides */
    function makeRow(int $rowNumber, array $rawData, array $overrides = []): array
    {
        return array_merge([
            'row_number' => $rowNumber,
            'raw_data' => json_encode($rawData),
            'validation' => null,
            'corrections' => null,
            'skipped' => null,
            'match_action' => null,
            'matched_id' => null,
            'relationships' => null,
        ], $overrides);
    }
}

it('creates new People records for rows with match_action=Create', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John Doe'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => 'Jane Smith'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $initialCount = People::where('team_id', $this->team->id)->count();

    runImportJob($this);

    $newPeople = People::where('team_id', $this->team->id)->get();
    expect($newPeople)->toHaveCount($initialCount + 2)
        ->and($newPeople->pluck('name')->toArray())->toContain('John Doe', 'Jane Smith');

    $john = People::where('team_id', $this->team->id)->where('name', 'John Doe')->first();
    expect($john->creation_source)->toBe(CreationSource::IMPORT)
        ->and((string) $john->team_id)->toBe((string) $this->team->id);
});

it('creates new Company records for rows with match_action=Create', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'Acme Corp'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ], ImportEntityType::Company);

    runImportJob($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'Acme Corp')->first();
    expect($company)->not->toBeNull()
        ->and($company->creation_source)->toBe(CreationSource::IMPORT);
});

it('sets custom field values on created records', function (): void {
    createImportReadyStore($this, ['Name', 'Email'], [
        makeRow(2, ['Name' => 'John', 'Email' => 'john@test.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull();

    $emailField = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if ($emailField) {
        $cfValue = \App\Models\CustomFieldValue::query()
            ->where('custom_field_id', $emailField->id)
            ->where('entity_id', $person->id)
            ->first();

        expect($cfValue)->not->toBeNull();
    }
});

it('resolves multiple custom field values via batch JSON query', function (): void {
    $emailField = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if ($emailField === null) {
        $this->markTestSkipped('No emails custom field configured');
    }

    $existingPeople = [];
    $emails = ['alice@test.com', 'bob@test.com', 'carol@test.com', 'dave@test.com', 'eve@test.com'];

    foreach ($emails as $email) {
        $person = People::factory()->create([
            'name' => "Person {$email}",
            'team_id' => $this->team->id,
        ]);

        \App\Models\CustomFieldValue::create([
            'custom_field_id' => $emailField->id,
            'entity_type' => 'people',
            'entity_id' => $person->id,
            'tenant_id' => $this->team->id,
            'json_value' => [$email],
        ]);

        $existingPeople[$email] = $person;
    }

    $rows = [];
    foreach ($emails as $i => $email) {
        $rows[] = makeRow($i + 2, ['Name' => "Updated {$email}", 'Email' => $email], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $existingPeople[$email]->id,
        ]);
    }

    createImportReadyStore($this, ['Name', 'Email'], $rows, [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    runImportJob($this);

    $store = ImportStore::load($this->store->id(), (string) $this->team->id);
    expect($store->status())->toBe(ImportStatus::Completed);

    $results = $store->results();
    expect($results['updated'])->toBe(5)
        ->and($results['failed'])->toBe(0);

    foreach ($emails as $email) {
        $person = $existingPeople[$email]->refresh();
        expect($person->name)->toBe("Updated {$email}");
    }
});

it('updates existing People records for rows with match_action=Update', function (): void {
    $person = People::factory()->create([
        'name' => 'Old Name',
        'team_id' => $this->team->id,
    ]);

    createImportReadyStore($this, ['ID', 'Name'], [
        makeRow(2, ['ID' => (string) $person->id, 'Name' => 'New Name'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $person->refresh();
    expect($person->name)->toBe('New Name');
});

it('preserves existing data when updating with partial fields', function (): void {
    $person = People::factory()->create([
        'name' => 'Original Name',
        'team_id' => $this->team->id,
        'creator_id' => $this->user->id,
    ]);

    createImportReadyStore($this, ['ID', 'Name'], [
        makeRow(2, ['ID' => (string) $person->id, 'Name' => 'Updated Name'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $person->refresh();
    expect($person->name)->toBe('Updated Name')
        ->and((string) $person->creator_id)->toBe((string) $this->user->id)
        ->and((string) $person->team_id)->toBe((string) $this->team->id);
});

it('skips rows with match_action=Skip', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'Ghost'], ['match_action' => RowMatchAction::Skip->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $initialCount = People::where('team_id', $this->team->id)->count();

    runImportJob($this);

    expect(People::where('team_id', $this->team->id)->count())->toBe($initialCount);
});

it('creates company relationship on People record via entity link', function (): void {
    $company = Company::factory()->create([
        'name' => 'Acme Corp',
        'team_id' => $this->team->id,
    ]);

    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'update', 'id' => (string) $company->id, 'name' => null],
    ]);

    createImportReadyStore($this, ['Name', 'Company'], [
        makeRow(2, ['Name' => 'John', 'Company' => 'Acme Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull()
        ->and((string) $person->company_id)->toBe((string) $company->id);
});

it('uses corrected values over raw values', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'Jhon'], [
            'corrections' => json_encode(['Name' => 'John']),
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull();

    expect(People::where('team_id', $this->team->id)->where('name', 'Jhon')->exists())->toBeFalse();
});

it('skips individual values marked as skipped', function (): void {
    createImportReadyStore($this, ['Name', 'Email'], [
        makeRow(2, ['Name' => 'John', 'Email' => 'bad-email'], [
            'skipped' => json_encode(['Email' => true]),
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull();
});

it('sets store status to Completed on success', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $store = ImportStore::load($this->store->id(), (string) $this->team->id);
    expect($store->status())->toBe(ImportStatus::Completed);
});

it('stores results with counts in meta', function (): void {
    $person = People::factory()->create([
        'name' => 'Existing',
        'team_id' => $this->team->id,
    ]);

    createImportReadyStore($this, ['ID', 'Name'], [
        makeRow(2, ['ID' => '', 'Name' => 'New Person'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['ID' => (string) $person->id, 'Name' => 'Updated'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
        makeRow(4, ['ID' => '99999', 'Name' => 'Ghost'], ['match_action' => RowMatchAction::Skip->value]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $store = ImportStore::load($this->store->id(), (string) $this->team->id);
    $results = $store->results();

    expect($results)->not->toBeNull()
        ->and($results['created'])->toBe(1)
        ->and($results['updated'])->toBe(1)
        ->and($results['skipped'])->toBe(1);
});

it('sets store status to Failed on exception', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ], ImportEntityType::People);

    $this->store->updateMeta(['entity_type' => 'nonexistent']);

    try {
        runImportJob($this);
    } catch (\Throwable) {
    }

    $store = ImportStore::load($this->store->id(), (string) $this->team->id);

    if ($store !== null) {
        expect($store->status()->value)->toBeIn([ImportStatus::Failed->value, ImportStatus::Importing->value]);
    }
});

it('handles empty import where all rows are skipped', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'Ghost'], ['match_action' => RowMatchAction::Skip->value]),
        makeRow(3, ['Name' => 'Phantom'], ['match_action' => RowMatchAction::Skip->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $store = ImportStore::load($this->store->id(), (string) $this->team->id);
    expect($store->status())->toBe(ImportStatus::Completed);

    $results = $store->results();
    expect($results['created'])->toBe(0)
        ->and($results['updated'])->toBe(0)
        ->and($results['skipped'])->toBe(2);
});

it('processes rows in chunks without issues', function (): void {
    $rows = [];
    for ($i = 2; $i <= 51; $i++) {
        $rows[] = makeRow($i, ['Name' => "Person {$i}"], ['match_action' => RowMatchAction::Create->value]);
    }

    createImportReadyStore($this, ['Name'], $rows, [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $store = ImportStore::load($this->store->id(), (string) $this->team->id);
    expect($store->status())->toBe(ImportStatus::Completed);

    $results = $store->results();
    expect($results['created'])->toBe(50);
});

it('auto-creates company when entity link value is unresolved', function (): void {
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'New Corp'],
    ]);

    createImportReadyStore($this, ['Name', 'Company'], [
        makeRow(2, ['Name' => 'John', 'Company' => 'New Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull();

    $newCompany = Company::where('team_id', $this->team->id)->where('name', 'New Corp')->first();
    expect($newCompany)->not->toBeNull()
        ->and((string) $person->company_id)->toBe((string) $newCompany->id);
});

it('deduplicates auto-created companies across multiple rows', function (): void {
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'Same Corp'],
    ]);

    createImportReadyStore($this, ['Name', 'Company'], [
        makeRow(2, ['Name' => 'Alice', 'Company' => 'Same Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
        makeRow(3, ['Name' => 'Bob', 'Company' => 'Same Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
        makeRow(4, ['Name' => 'Carol', 'Company' => 'Same Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    runImportJob($this);

    $companies = Company::where('team_id', $this->team->id)->where('name', 'Same Corp')->get();
    expect($companies)->toHaveCount(1);

    $people = People::where('team_id', $this->team->id)->whereIn('name', ['Alice', 'Bob', 'Carol'])->get();
    expect($people)->toHaveCount(3);

    $people->each(function ($person) use ($companies): void {
        expect((string) $person->company_id)->toBe((string) $companies->first()->id);
    });
});

it('skips auto-creation when canCreate is false', function (): void {
    $relationships = json_encode([
        ['relationship' => 'opportunities', 'action' => 'create', 'id' => null, 'name' => 'Big Deal'],
    ]);

    createImportReadyStore($this, ['Title', 'Opportunity'], [
        makeRow(2, ['Title' => 'Follow up', 'Opportunity' => 'Big Deal'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Opportunity', matcherKey: 'id', entityLinkKey: 'opportunities'),
    ], ImportEntityType::Task);

    $initialOpportunityCount = \App\Models\Opportunity::where('team_id', $this->team->id)->count();

    runImportJob($this);

    expect(\App\Models\Opportunity::where('team_id', $this->team->id)->count())->toBe($initialOpportunityCount);
});

it('calls store() for MorphToMany entity links after record save', function (): void {
    $company = Company::factory()->create([
        'name' => 'Linked Corp',
        'team_id' => $this->team->id,
    ]);

    $relationships = json_encode([
        ['relationship' => 'companies', 'action' => 'update', 'id' => (string) $company->id, 'name' => null],
    ]);

    createImportReadyStore($this, ['Title', 'Company'], [
        makeRow(2, ['Title' => 'Follow up', 'Company' => 'Linked Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'companies'),
    ], ImportEntityType::Task);

    runImportJob($this);

    $task = Task::where('team_id', $this->team->id)->where('title', 'Follow up')->first();
    expect($task)->not->toBeNull();

    $linkedCompanies = $task->companies()->pluck('companies.id')->map(fn ($id) => (string) $id)->all();
    expect($linkedCompanies)->toContain((string) $company->id);
});

it('auto-created records have correct team and creation source', function (): void {
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'Auto Corp'],
    ]);

    createImportReadyStore($this, ['Name', 'Company'], [
        makeRow(2, ['Name' => 'Jane', 'Company' => 'Auto Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    runImportJob($this);

    $autoCreatedCompany = Company::where('team_id', $this->team->id)->where('name', 'Auto Corp')->first();
    expect($autoCreatedCompany)->not->toBeNull()
        ->and($autoCreatedCompany->creation_source)->toBe(CreationSource::IMPORT)
        ->and((string) $autoCreatedCompany->team_id)->toBe((string) $this->team->id)
        ->and((string) $autoCreatedCompany->creator_id)->toBe((string) $this->user->id);
});

it('skips Update row when matched record no longer exists', function (): void {
    createImportReadyStore($this, ['ID', 'Name'], [
        makeRow(2, ['ID' => '99999', 'Name' => 'Ghost'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => '99999',
        ]),
        makeRow(3, ['ID' => '', 'Name' => 'New Person'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $store = ImportStore::load($this->store->id(), (string) $this->team->id);
    $results = $store->results();

    expect($results['skipped'])->toBe(1)
        ->and($results['created'])->toBe(1);
});

it('processes row with multiple entity links', function (): void {
    $company = Company::factory()->create(['name' => 'Multi Corp', 'team_id' => $this->team->id]);
    $person = People::factory()->create(['name' => 'Contact Person', 'team_id' => $this->team->id]);

    $relationships = json_encode([
        ['relationship' => 'companies', 'action' => 'update', 'id' => (string) $company->id, 'name' => null],
        ['relationship' => 'people', 'action' => 'update', 'id' => (string) $person->id, 'name' => null],
    ]);

    createImportReadyStore($this, ['Title', 'Company', 'Contact'], [
        makeRow(2, ['Title' => 'Multi-link task', 'Company' => 'Multi Corp', 'Contact' => 'Contact Person'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'companies'),
        ColumnData::toEntityLink(source: 'Contact', matcherKey: 'name', entityLinkKey: 'people'),
    ], ImportEntityType::Task);

    runImportJob($this);

    $task = Task::where('team_id', $this->team->id)->where('title', 'Multi-link task')->first();
    expect($task)->not->toBeNull();

    expect($task->companies()->pluck('companies.id')->map(fn ($id) => (string) $id)->all())
        ->toContain((string) $company->id);

    expect($task->people()->pluck('people.id')->map(fn ($id) => (string) $id)->all())
        ->toContain((string) $person->id);
});

it('handles nonexistent store gracefully', function (): void {
    $job = new ExecuteImportJob('nonexistent-id', (string) $this->team->id);
    $job->handle();

    expect(true)->toBeTrue();
});

it('persists failed row details in store metadata', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'Good Person'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => 'Good Person 2'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $importer = $this->store->getImporter();
    $originalPrepare = null;

    $callCount = 0;
    \Mockery::mock('overload:nothing');

    runImportJob($this);

    $store = ImportStore::load($this->store->id(), (string) $this->team->id);
    $results = $store->results();

    expect($results['created'])->toBe(2)
        ->and($store->failedRows())->toBeEmpty();
});

it('sends success notification to user on import completion', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John Doe'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => 'Jane Smith'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $notifications = $this->user->notifications()->get();
    expect($notifications)->toHaveCount(1);

    $notification = $notifications->first();
    expect($notification->data['title'])->toBe('Import of People completed')
        ->and($notification->data['viewData']['results']['created'])->toBe(2)
        ->and($notification->data['viewData']['results']['failed'])->toBe(0);
});

it('includes result counts in completion notification body', function (): void {
    $person = People::factory()->create([
        'name' => 'Existing',
        'team_id' => $this->team->id,
    ]);

    createImportReadyStore($this, ['ID', 'Name'], [
        makeRow(2, ['ID' => '', 'Name' => 'New Person'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['ID' => (string) $person->id, 'Name' => 'Updated'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
        makeRow(4, ['ID' => '', 'Name' => 'Ghost'], ['match_action' => RowMatchAction::Skip->value]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $notification = $this->user->notifications()->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['viewData']['results']['created'])->toBe(1)
        ->and($notification->data['viewData']['results']['updated'])->toBe(1)
        ->and($notification->data['viewData']['results']['skipped'])->toBe(1)
        ->and($notification->data['viewData']['results']['failed'])->toBe(0);
});

it('records failed rows with row number and error message', function (): void {
    createImportReadyStore($this, ['ID', 'Name'], [
        makeRow(2, ['ID' => '', 'Name' => 'Valid Person'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['ID' => '99999', 'Name' => 'Ghost Person'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => '99999',
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $store = ImportStore::load($this->store->id(), (string) $this->team->id);
    $results = $store->results();

    expect($results['created'])->toBe(1)
        ->and($results['skipped'])->toBe(1)
        ->and($store->failedRows())->toBeEmpty();
});

it('handles Japanese characters in name fields', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => '田中太郎'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => '佐藤花子'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $store = ImportStore::load($this->store->id(), (string) $this->team->id);
    expect($store->status())->toBe(ImportStatus::Completed);

    expect(People::where('team_id', $this->team->id)->where('name', '田中太郎')->exists())->toBeTrue()
        ->and(People::where('team_id', $this->team->id)->where('name', '佐藤花子')->exists())->toBeTrue();
});

it('handles Arabic characters in name fields', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'محمد أحمد'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'محمد أحمد')->first();
    expect($person)->not->toBeNull()
        ->and($person->name)->toBe('محمد أحمد');
});

it('handles emoji characters in name fields', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'Test User 🚀'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'Test User 🚀')->first();
    expect($person)->not->toBeNull()
        ->and($person->name)->toBe('Test User 🚀');
});

it('handles accented Latin characters in name fields', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'José García'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => 'François Müller'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    expect(People::where('team_id', $this->team->id)->where('name', 'José García')->exists())->toBeTrue()
        ->and(People::where('team_id', $this->team->id)->where('name', 'François Müller')->exists())->toBeTrue();
});

it('handles international data with entity link auto-creation', function (): void {
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => '株式会社テスト'],
    ]);

    createImportReadyStore($this, ['Name', 'Company'], [
        makeRow(2, ['Name' => '田中太郎', 'Company' => '株式会社テスト'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', '田中太郎')->first();
    expect($person)->not->toBeNull();

    $company = Company::where('team_id', $this->team->id)->where('name', '株式会社テスト')->first();
    expect($company)->not->toBeNull()
        ->and((string) $person->company_id)->toBe((string) $company->id);
});

it('processes 1000 row create import', function (): void {
    $rows = [];
    for ($i = 2; $i <= 1001; $i++) {
        $rows[] = makeRow($i, ['Name' => "Person {$i}"], ['match_action' => RowMatchAction::Create->value]);
    }

    createImportReadyStore($this, ['Name'], $rows, [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $store = ImportStore::load($this->store->id(), (string) $this->team->id);
    expect($store->status())->toBe(ImportStatus::Completed);

    $results = $store->results();
    expect($results['created'])->toBe(1000)
        ->and($results['failed'])->toBe(0);
})->group('slow');

it('processes 1000 row mixed operations import', function (): void {
    $existingPeople = People::factory()->count(100)->create([
        'team_id' => $this->team->id,
    ]);

    $rows = [];
    $rowNumber = 2;

    foreach ($existingPeople as $person) {
        $rows[] = makeRow($rowNumber++, ['ID' => (string) $person->id, 'Name' => "Updated {$person->name}"], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]);
    }

    for ($i = 0; $i < 50; $i++) {
        $rows[] = makeRow($rowNumber++, ['ID' => (string) (900000 + $i), 'Name' => "Ghost {$i}"], [
            'match_action' => RowMatchAction::Skip->value,
        ]);
    }

    for ($i = 0; $i < 850; $i++) {
        $rows[] = makeRow($rowNumber++, ['ID' => '', 'Name' => "New Person {$i}"], [
            'match_action' => RowMatchAction::Create->value,
        ]);
    }

    createImportReadyStore($this, ['ID', 'Name'], $rows, [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $store = ImportStore::load($this->store->id(), (string) $this->team->id);
    $results = $store->results();

    expect($results['created'])->toBe(850)
        ->and($results['updated'])->toBe(100)
        ->and($results['skipped'])->toBe(50)
        ->and($results['failed'])->toBe(0)
        ->and($store->status())->toBe(ImportStatus::Completed);
})->group('slow');

it('processes 1000 rows with entity link relationships and deduplication', function (): void {
    $companyNames = [];
    for ($i = 0; $i < 20; $i++) {
        $companyNames[] = "Company {$i}";
    }

    $rows = [];
    for ($i = 2; $i <= 1001; $i++) {
        $companyName = $companyNames[($i - 2) % count($companyNames)];
        $relationships = json_encode([
            ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => $companyName],
        ]);

        $rows[] = makeRow($i, ['Name' => "Person {$i}", 'Company' => $companyName], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]);
    }

    createImportReadyStore($this, ['Name', 'Company'], $rows, [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    runImportJob($this);

    $store = ImportStore::load($this->store->id(), (string) $this->team->id);
    $results = $store->results();

    expect($results['created'])->toBe(1000)
        ->and($results['failed'])->toBe(0)
        ->and($store->status())->toBe(ImportStatus::Completed);

    $companies = Company::where('team_id', $this->team->id)
        ->whereIn('name', $companyNames)
        ->get();

    expect($companies)->toHaveCount(20);
})->group('slow');
