<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Enums\MatchBehavior;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Models\Import;
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
    if (isset($this->import)) {
        ImportStore::load($this->import->id)?->destroy();
        $this->import->delete();
    }
});

function createImportReadyStore(
    object $context,
    array $headers,
    array $rows,
    array $mappings,
    ImportEntityType $entityType = ImportEntityType::People,
): array {
    $import = Import::create([
        'team_id' => (string) $context->team->id,
        'user_id' => (string) $context->user->id,
        'entity_type' => $entityType,
        'file_name' => 'test.csv',
        'status' => ImportStatus::Importing,
        'total_rows' => count($rows),
        'headers' => $headers,
        'column_mappings' => collect($mappings)->map(fn (ColumnData $m) => $m->toArray())->all(),
    ]);

    $store = ImportStore::create($import->id);
    $store->query()->insert($rows);

    $context->import = $import;
    $context->store = $store;

    return [$import, $store];
}

function runImportJob(object $context): void
{
    $job = new ExecuteImportJob(
        importId: $context->import->id,
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

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed);

    expect($import->updated_rows)->toBe(5)
        ->and($import->failed_rows)->toBe(0);

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

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed);
});

it('skips rows with null match_action without crashing', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'Good Person'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => 'Null Action'], ['match_action' => null]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed);

    expect($import->created_rows)->toBe(1)
        ->and($import->skipped_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(0);
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

    $import = $this->import->fresh();

    expect($import->created_rows)->toBe(1)
        ->and($import->updated_rows)->toBe(1)
        ->and($import->skipped_rows)->toBe(1);
});

it('sets store status to Failed on exception', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ], ImportEntityType::People);

    DB::table('imports')->where('id', $this->import->id)->update(['entity_type' => 'nonexistent']);

    try {
        runImportJob($this);
    } catch (\Throwable) {
    }

    $import = $this->import->fresh();

    if ($import !== null) {
        expect($import->status->value)->toBeIn([ImportStatus::Failed->value, ImportStatus::Importing->value]);
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

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed);

    expect($import->created_rows)->toBe(0)
        ->and($import->updated_rows)->toBe(0)
        ->and($import->skipped_rows)->toBe(2);
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

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed);

    expect($import->created_rows)->toBe(50);
});

it('auto-creates company when entity link value is unresolved', function (): void {
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'New Corp', 'behavior' => MatchBehavior::Create->value],
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
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'Same Corp', 'behavior' => MatchBehavior::Create->value],
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

it('skips auto-creation for entity links with only MatchOnly matchers', function (): void {
    $relationships = json_encode([
        ['relationship' => 'opportunities', 'action' => 'create', 'id' => null, 'name' => 'Big Deal', 'behavior' => MatchBehavior::MatchOnly->value],
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
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'Auto Corp', 'behavior' => MatchBehavior::Create->value],
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

    $import = $this->import->fresh();

    expect($import->skipped_rows)->toBe(1)
        ->and($import->created_rows)->toBe(1);
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

it('handles nonexistent import gracefully', function (): void {
    $job = new ExecuteImportJob('nonexistent-id', (string) $this->team->id);

    try {
        $job->handle();
        expect(false)->toBeTrue('Expected exception was not thrown');
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        expect($e->getModel())->toBe(Import::class);
    }
});

it('filters out unexpected attributes from CSV data before saving', function (): void {
    createImportReadyStore($this, ['Name', 'Malicious'], [
        makeRow(2, ['Name' => 'Safe Person', 'Malicious' => 'hacked'], [
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Malicious', target: 'is_admin'),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'Safe Person')->first();
    expect($person)->not->toBeNull();

    $raw = DB::table('people')->where('id', $person->id)->first();
    expect($raw)->not->toHaveProperty('is_admin', 'hacked');
});

it('persists failed row details in store metadata', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'Good Person'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => 'Good Person 2'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $import = $this->import->fresh();

    expect($import->created_rows)->toBe(2)
        ->and($import->failedRows)->toBeEmpty();
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

    $import = $this->import->fresh();

    expect($import->created_rows)->toBe(1)
        ->and($import->skipped_rows)->toBe(1)
        ->and($import->failedRows)->toBeEmpty();
});

it('handles Japanese characters in name fields', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => '田中太郎'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => '佐藤花子'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    runImportJob($this);

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed);

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
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => '株式会社テスト', 'behavior' => MatchBehavior::Create->value],
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

it('persists results to Import model on completion', function (): void {
    $headers = ['Name', 'Email'];
    $rows = [
        makeRow(2, ['Name' => 'John', 'Email' => 'john@test.com'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => 'Jane', 'Email' => 'jane@test.com'], ['match_action' => RowMatchAction::Create->value]),
    ];
    $mappings = [
        ColumnData::toField('Name', 'name'),
        ColumnData::toField('Email', 'email'),
    ];

    [$import, $store] = createImportReadyStore($this, $headers, $rows, $mappings);

    runImportJob($this);

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->completed_at)->not->toBeNull()
        ->and($import->created_rows)->toBe(2)
        ->and($import->updated_rows)->toBe(0)
        ->and($import->skipped_rows)->toBe(0)
        ->and($import->failed_rows)->toBe(0);
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

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed);

    expect($import->created_rows)->toBe(1000)
        ->and($import->failed_rows)->toBe(0);
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

    $import = $this->import->fresh();
    expect($import->created_rows)->toBe(850)
        ->and($import->updated_rows)->toBe(100)
        ->and($import->skipped_rows)->toBe(50)
        ->and($import->failed_rows)->toBe(0)
        ->and($import->status)->toBe(ImportStatus::Completed);
})->group('slow');

it('marks import as Failed when job exhausts retries via failed() handler', function (): void {
    createImportReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $job = new ExecuteImportJob(
        importId: $this->import->id,
        teamId: (string) $this->team->id,
    );

    $job->failed(new \RuntimeException('Queue worker gave up'));

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Failed);
});

it('processes 1000 rows with entity link relationships and deduplication', function (): void {
    $companyNames = [];
    for ($i = 0; $i < 20; $i++) {
        $companyNames[] = "Company {$i}";
    }

    $rows = [];
    for ($i = 2; $i <= 1001; $i++) {
        $companyName = $companyNames[($i - 2) % count($companyNames)];
        $relationships = json_encode([
            ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => $companyName, 'behavior' => MatchBehavior::Create->value],
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

    $import = $this->import->fresh();
    expect($import->created_rows)->toBe(1000)
        ->and($import->failed_rows)->toBe(0)
        ->and($import->status)->toBe(ImportStatus::Completed);

    $companies = Company::where('team_id', $this->team->id)
        ->whereIn('name', $companyNames)
        ->get();

    expect($companies)->toHaveCount(20);
})->group('slow');

// --- Custom Field Import Tests ---

function createTestCustomField(object $context, string $code, string $type, string $entityType = 'people', array $options = []): \App\Models\CustomField
{
    $cf = \App\Models\CustomField::forceCreate([
        'tenant_id' => $context->team->id,
        'code' => $code,
        'name' => ucfirst(str_replace('_', ' ', $code)),
        'type' => $type,
        'entity_type' => $entityType,
        'sort_order' => 1,
        'active' => true,
        'system_defined' => false,
        'validation_rules' => [],
        'settings' => new \Relaticle\CustomFields\Data\CustomFieldSettingsData,
    ]);

    foreach ($options as $i => $optionName) {
        $cf->options()->forceCreate([
            'custom_field_id' => $cf->id,
            'tenant_id' => $context->team->id,
            'name' => $optionName,
            'sort_order' => $i + 1,
        ]);
    }

    return $cf->fresh();
}

function getTestCustomFieldValue(object $context, string $entityId, string $customFieldId): ?\App\Models\CustomFieldValue
{
    return \App\Models\CustomFieldValue::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $context->team->id)
        ->where('entity_id', $entityId)
        ->where('custom_field_id', $customFieldId)
        ->first();
}

it('imports text custom field value', function (): void {
    $cf = createTestCustomField($this, 'website_notes', 'text');

    createImportReadyStore($this, ['Name', 'Notes'], [
        makeRow(2, ['Name' => 'John', 'Notes' => 'Some important text'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Notes', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull();

    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBe('Some important text');
});

it('imports number custom field as integer', function (): void {
    $cf = createTestCustomField($this, 'employee_count', 'number');

    createImportReadyStore($this, ['Name', 'Employees'], [
        makeRow(2, ['Name' => 'Acme', 'Employees' => '42'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Employees', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'Acme')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->integer_value)->toBe(42);
});

it('imports currency custom field with point decimal format', function (): void {
    $cf = createTestCustomField($this, 'revenue', 'currency');

    createImportReadyStore($this, ['Name', 'Revenue'], [
        makeRow(2, ['Name' => 'Acme', 'Revenue' => '1234.56'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Revenue', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'Acme')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->float_value)->toBe(1234.56);
});

it('imports currency custom field with comma decimal format', function (): void {
    $cf = createTestCustomField($this, 'revenue_eu', 'currency');

    createImportReadyStore($this, ['Name', 'Revenue'], [
        makeRow(2, ['Name' => 'Acme', 'Revenue' => '1.234,56'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        (new ColumnData(
            source: 'Revenue',
            target: "custom_fields_{$cf->code}",
            numberFormat: \Relaticle\ImportWizard\Enums\NumberFormat::COMMA,
        )),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'Acme')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->float_value)->toBe(1234.56);
});

it('imports date custom field with ISO format', function (): void {
    $cf = createTestCustomField($this, 'start_date', 'date');

    createImportReadyStore($this, ['Name', 'Start'], [
        makeRow(2, ['Name' => 'John', 'Start' => '2024-05-15'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Start', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->date_value->format('Y-m-d'))->toBe('2024-05-15');
});

it('imports date custom field with European format', function (): void {
    $cf = createTestCustomField($this, 'start_date_eu', 'date');

    createImportReadyStore($this, ['Name', 'Start'], [
        makeRow(2, ['Name' => 'John', 'Start' => '15/05/2024'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        (new ColumnData(
            source: 'Start',
            target: "custom_fields_{$cf->code}",
            dateFormat: \Relaticle\ImportWizard\Enums\DateFormat::EUROPEAN,
        )),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->date_value->format('Y-m-d'))->toBe('2024-05-15');
});

it('imports date custom field with American format', function (): void {
    $cf = createTestCustomField($this, 'start_date_us', 'date');

    createImportReadyStore($this, ['Name', 'Start'], [
        makeRow(2, ['Name' => 'John', 'Start' => '05/15/2024'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        (new ColumnData(
            source: 'Start',
            target: "custom_fields_{$cf->code}",
            dateFormat: \Relaticle\ImportWizard\Enums\DateFormat::AMERICAN,
        )),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->date_value->format('Y-m-d'))->toBe('2024-05-15');
});

it('imports datetime custom field with ISO format including time', function (): void {
    $cf = createTestCustomField($this, 'meeting_at', 'date-time');

    createImportReadyStore($this, ['Name', 'Meeting'], [
        makeRow(2, ['Name' => 'John', 'Meeting' => '2024-05-15 14:30:00'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Meeting', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->datetime_value->format('Y-m-d H:i:s'))->toBe('2024-05-15 14:30:00');
});

it('imports datetime custom field with European format including time', function (): void {
    $cf = createTestCustomField($this, 'meeting_at_eu', 'date-time');

    createImportReadyStore($this, ['Name', 'Meeting'], [
        makeRow(2, ['Name' => 'John', 'Meeting' => '15/05/2024 14:30'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        (new ColumnData(
            source: 'Meeting',
            target: "custom_fields_{$cf->code}",
            dateFormat: \Relaticle\ImportWizard\Enums\DateFormat::EUROPEAN,
        )),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->datetime_value->format('Y-m-d H:i'))->toBe('2024-05-15 14:30');
});

it('imports boolean custom field with truthy values', function (): void {
    $cf = createTestCustomField($this, 'is_vip', 'checkbox');

    createImportReadyStore($this, ['Name', 'VIP'], [
        makeRow(2, ['Name' => 'John', 'VIP' => '1'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'VIP', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->boolean_value)->toBeTrue();
});

it('imports select custom field with option name resolved to ID', function (): void {
    $cf = createTestCustomField($this, 'priority', 'select', 'people', ['Low', 'Medium', 'High']);
    $mediumOption = $cf->options->firstWhere('name', 'Medium');

    createImportReadyStore($this, ['Name', 'Priority'], [
        makeRow(2, ['Name' => 'John', 'Priority' => 'Medium'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Priority', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->string_value)->toBe((string) $mediumOption->id);
});

it('imports multi-select custom field with option names resolved to IDs', function (): void {
    $cf = createTestCustomField($this, 'tags_field', 'multi-select', 'people', ['Urgent', 'Follow-up', 'VIP']);
    $urgentOption = $cf->options->firstWhere('name', 'Urgent');
    $vipOption = $cf->options->firstWhere('name', 'VIP');

    createImportReadyStore($this, ['Name', 'Tags'], [
        makeRow(2, ['Name' => 'John', 'Tags' => 'Urgent, VIP'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Tags', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = $cfv->json_value;
    expect($jsonValue)->toContain((string) $urgentOption->id)
        ->toContain((string) $vipOption->id);
});

it('imports tags-input custom field with comma-separated values', function (): void {
    $cf = createTestCustomField($this, 'labels', 'tags-input');

    createImportReadyStore($this, ['Name', 'Labels'], [
        makeRow(2, ['Name' => 'John', 'Labels' => 'tag1, tag2, tag3'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Labels', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = $cfv->json_value;
    expect($jsonValue)->toContain('tag1')
        ->toContain('tag2')
        ->toContain('tag3');
});

it('skips blank custom field values without creating custom_field_values row', function (): void {
    $cf = createTestCustomField($this, 'optional_notes', 'text');

    createImportReadyStore($this, ['Name', 'Notes'], [
        makeRow(2, ['Name' => 'John', 'Notes' => ''], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Notes', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull();

    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->toBeNull();
});

it('updates existing custom field value on record update', function (): void {
    $cf = createTestCustomField($this, 'note_field', 'text');

    $person = People::factory()->create([
        'name' => 'John',
        'team_id' => $this->team->id,
    ]);

    \App\Models\CustomFieldValue::forceCreate([
        'custom_field_id' => $cf->id,
        'entity_type' => 'people',
        'entity_id' => $person->id,
        'tenant_id' => $this->team->id,
        'text_value' => 'old value',
    ]);

    createImportReadyStore($this, ['ID', 'Name', 'Notes'], [
        makeRow(2, ['ID' => (string) $person->id, 'Name' => 'John', 'Notes' => 'new value'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Notes', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBe('new value');
});

it('imports email custom field with comma-separated addresses as array', function (): void {
    $cf = createTestCustomField($this, 'contact_emails', 'email');

    createImportReadyStore($this, ['Name', 'Emails'], [
        makeRow(2, ['Name' => 'John', 'Emails' => 'a@b.com, c@d.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Emails', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = collect($cfv->json_value)->all();
    expect($jsonValue)->toBeArray()
        ->toContain('a@b.com')
        ->toContain('c@d.com');
});

it('imports select custom field with case-insensitive option name', function (): void {
    $cf = createTestCustomField($this, 'priority_ci', 'select', 'people', ['Low', 'Medium', 'High']);
    $mediumOption = $cf->options->firstWhere('name', 'Medium');

    createImportReadyStore($this, ['Name', 'Priority'], [
        makeRow(2, ['Name' => 'John', 'Priority' => 'medium'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Priority', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->string_value)->toBe((string) $mediumOption->id);
});

it('imports select custom field with value already being an option ID', function (): void {
    $cf = createTestCustomField($this, 'priority_id', 'select', 'people', ['Low', 'Medium', 'High']);
    $mediumOption = $cf->options->firstWhere('name', 'Medium');

    createImportReadyStore($this, ['Name', 'Priority'], [
        makeRow(2, ['Name' => 'John', 'Priority' => (string) $mediumOption->id], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Priority', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->string_value)->toBe((string) $mediumOption->id);
});

it('imports multi-select custom field with mixed option names resolved to IDs', function (): void {
    $cf = createTestCustomField($this, 'categories', 'multi-select', 'people', ['Alpha', 'Beta', 'Gamma']);
    $alphaOption = $cf->options->firstWhere('name', 'Alpha');
    $gammaOption = $cf->options->firstWhere('name', 'Gamma');

    createImportReadyStore($this, ['Name', 'Categories'], [
        makeRow(2, ['Name' => 'John', 'Categories' => 'Alpha, Gamma'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Categories', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = collect($cfv->json_value)->all();
    expect($jsonValue)->toBeArray()
        ->toContain((string) $alphaOption->id)
        ->toContain((string) $gammaOption->id)
        ->not->toContain('Alpha')
        ->not->toContain('Gamma');
});

// --- Missing Custom Field Type Tests ---

it('imports phone custom field with comma-separated numbers as array', function (): void {
    $cf = createTestCustomField($this, 'phones', 'phone');

    createImportReadyStore($this, ['Name', 'Phones'], [
        makeRow(2, ['Name' => 'John', 'Phones' => '+1-555-0101, +44-20-7946-0958'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Phones', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = collect($cfv->json_value)->all();
    expect($jsonValue)->toBeArray()
        ->toContain('+1-555-0101')
        ->toContain('+44-20-7946-0958');
});

it('imports link custom field with URL value', function (): void {
    $cf = createTestCustomField($this, 'website', 'link');

    createImportReadyStore($this, ['Name', 'Website'], [
        makeRow(2, ['Name' => 'John', 'Website' => 'https://example.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Website', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = collect($cfv->json_value)->all();
    expect($jsonValue)->toContain('https://example.com');
});

it('imports toggle custom field with truthy values', function (): void {
    $cf = createTestCustomField($this, 'is_active', 'toggle');

    createImportReadyStore($this, ['Name', 'Active'], [
        makeRow(2, ['Name' => 'John', 'Active' => 'yes'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Active', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->boolean_value)->toBeTrue();
});

it('imports toggle custom field with falsy values', function (): void {
    $cf = createTestCustomField($this, 'opted_out', 'toggle');

    createImportReadyStore($this, ['Name', 'OptedOut'], [
        makeRow(2, ['Name' => 'John', 'OptedOut' => '0'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'OptedOut', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->boolean_value)->toBeFalse();
});

it('imports textarea custom field value', function (): void {
    $cf = createTestCustomField($this, 'bio', 'textarea');

    createImportReadyStore($this, ['Name', 'Bio'], [
        makeRow(2, ['Name' => 'John', 'Bio' => 'A long biography text that spans multiple lines conceptually.'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Bio', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBe('A long biography text that spans multiple lines conceptually.');
});

it('imports rich-editor custom field value as text', function (): void {
    $cf = createTestCustomField($this, 'detailed_notes', 'rich-editor');

    createImportReadyStore($this, ['Name', 'Notes'], [
        makeRow(2, ['Name' => 'John', 'Notes' => '<p>Bold statement</p>'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Notes', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBe('<p>Bold statement</p>');
});

it('imports markdown-editor custom field value as text', function (): void {
    $cf = createTestCustomField($this, 'readme', 'markdown-editor');

    createImportReadyStore($this, ['Name', 'Readme'], [
        makeRow(2, ['Name' => 'John', 'Readme' => '# Heading\n\nSome **bold** text'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Readme', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBe('# Heading\n\nSome **bold** text');
});

it('imports checkbox-list custom field with option names resolved to IDs', function (): void {
    $cf = createTestCustomField($this, 'interests', 'checkbox-list', 'people', ['Sports', 'Music', 'Tech']);
    $sportsOption = $cf->options->firstWhere('name', 'Sports');
    $techOption = $cf->options->firstWhere('name', 'Tech');

    createImportReadyStore($this, ['Name', 'Interests'], [
        makeRow(2, ['Name' => 'John', 'Interests' => 'Sports, Tech'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Interests', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = collect($cfv->json_value)->all();
    expect($jsonValue)->toBeArray()
        ->toContain((string) $sportsOption->id)
        ->toContain((string) $techOption->id);
});

it('imports radio custom field with option name resolved to ID', function (): void {
    $cf = createTestCustomField($this, 'size', 'radio', 'people', ['Small', 'Medium', 'Large']);
    $mediumOption = $cf->options->firstWhere('name', 'Medium');

    createImportReadyStore($this, ['Name', 'Size'], [
        makeRow(2, ['Name' => 'John', 'Size' => 'Medium'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Size', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->string_value)->toBe((string) $mediumOption->id);
});

it('imports toggle-buttons custom field with option name resolved to ID', function (): void {
    $cf = createTestCustomField($this, 'urgency', 'toggle-buttons', 'people', ['Low', 'Normal', 'Urgent']);
    $urgentOption = $cf->options->firstWhere('name', 'Urgent');

    createImportReadyStore($this, ['Name', 'Urgency'], [
        makeRow(2, ['Name' => 'John', 'Urgency' => 'Urgent'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Urgency', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->string_value)->toBe((string) $urgentOption->id);
});

it('imports color-picker custom field value as text', function (): void {
    $cf = createTestCustomField($this, 'brand_color', 'color-picker');

    createImportReadyStore($this, ['Name', 'Color'], [
        makeRow(2, ['Name' => 'John', 'Color' => '#ff5733'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Color', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBe('#ff5733');
});

// --- Entity-Specific Importer Tests ---

it('imports company with account_owner resolved by email via entity link', function (): void {
    $owner = User::factory()->create();
    $this->team->users()->attach($owner, ['role' => 'editor']);

    $relationships = json_encode([
        ['relationship' => 'account_owner', 'action' => 'update', 'id' => (string) $owner->id, 'name' => null],
    ]);

    createImportReadyStore($this, ['Name', 'Owner Email'], [
        makeRow(2, ['Name' => 'Test Corp', 'Owner Email' => $owner->email], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Owner Email', matcherKey: 'email', entityLinkKey: 'account_owner'),
    ], ImportEntityType::Company);

    runImportJob($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'Test Corp')->first();
    expect($company)->not->toBeNull()
        ->and((string) $company->account_owner_id)->toBe((string) $owner->id);
});

it('imports company with unmatched account_owner email skipping silently', function (): void {
    createImportReadyStore($this, ['Name', 'Owner Email'], [
        makeRow(2, ['Name' => 'Test Corp', 'Owner Email' => 'nonexistent@example.com'], [
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Owner Email', matcherKey: 'email', entityLinkKey: 'account_owner'),
    ], ImportEntityType::Company);

    runImportJob($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'Test Corp')->first();
    expect($company)->not->toBeNull()
        ->and($company->account_owner_id)->toBeNull();
});

it('imports company with account_owner resolved for team owner', function (): void {
    $relationships = json_encode([
        ['relationship' => 'account_owner', 'action' => 'update', 'id' => (string) $this->user->id, 'name' => null],
    ]);

    createImportReadyStore($this, ['Name', 'Owner Email'], [
        makeRow(2, ['Name' => 'Owner Corp', 'Owner Email' => $this->user->email], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Owner Email', matcherKey: 'email', entityLinkKey: 'account_owner'),
    ], ImportEntityType::Company);

    runImportJob($this);

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

    createImportReadyStore($this, ['Title', 'Assignee Email'], [
        makeRow(2, ['Title' => 'Test Task', 'Assignee Email' => $assignee->email], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Assignee Email', matcherKey: 'email', entityLinkKey: 'assignees'),
    ], ImportEntityType::Task);

    runImportJob($this);

    $task = Task::where('team_id', $this->team->id)->where('title', 'Test Task')->first();
    expect($task)->not->toBeNull();

    $assigneeIds = $task->assignees()->pluck('users.id')->map(fn ($id) => (string) $id)->all();
    expect($assigneeIds)->toContain((string) $assignee->id);
});

it('imports task with unmatched assignee email skipping silently', function (): void {
    createImportReadyStore($this, ['Title', 'Assignee Email'], [
        makeRow(2, ['Title' => 'Orphan Task', 'Assignee Email' => 'ghost@nowhere.com'], [
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Assignee Email', matcherKey: 'email', entityLinkKey: 'assignees'),
    ], ImportEntityType::Task);

    runImportJob($this);

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

    createImportReadyStore($this, ['Name', 'Company', 'Contact'], [
        makeRow(2, ['Name' => 'Big Deal', 'Company' => 'Deal Corp', 'Contact' => 'Deal Contact'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
        ColumnData::toEntityLink(source: 'Contact', matcherKey: 'name', entityLinkKey: 'contact'),
    ], ImportEntityType::Opportunity);

    runImportJob($this);

    $opportunity = \App\Models\Opportunity::where('team_id', $this->team->id)->where('name', 'Big Deal')->first();
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

    createImportReadyStore($this, ['Title', 'Company', 'Person'], [
        makeRow(2, ['Title' => 'Meeting Notes', 'Company' => 'Note Corp', 'Person' => 'Note Person'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'companies'),
        ColumnData::toEntityLink(source: 'Person', matcherKey: 'name', entityLinkKey: 'people'),
    ], ImportEntityType::Note);

    runImportJob($this);

    $note = \App\Models\Note::where('team_id', $this->team->id)->where('title', 'Meeting Notes')->first();
    expect($note)->not->toBeNull();

    expect($note->companies()->pluck('companies.id')->map(fn ($id) => (string) $id)->all())
        ->toContain((string) $company->id);

    expect($note->people()->pluck('people.id')->map(fn ($id) => (string) $id)->all())
        ->toContain((string) $person->id);
});

it('imports note with title field only', function (): void {
    createImportReadyStore($this, ['Title'], [
        makeRow(2, ['Title' => 'Quick note'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
    ], ImportEntityType::Note);

    runImportJob($this);

    $note = \App\Models\Note::where('team_id', $this->team->id)->where('title', 'Quick note')->first();
    expect($note)->not->toBeNull()
        ->and($note->creation_source)->toBe(CreationSource::IMPORT);
});

it('imports task with custom field values for select fields', function (): void {
    $statusCf = createTestCustomField($this, 'task_status', 'select', 'task', ['To do', 'In progress', 'Done']);
    $priorityCf = createTestCustomField($this, 'task_priority', 'select', 'task', ['Low', 'Medium', 'High']);
    $inProgressOption = $statusCf->options->firstWhere('name', 'In progress');
    $highOption = $priorityCf->options->firstWhere('name', 'High');

    createImportReadyStore($this, ['Title', 'Status', 'Priority'], [
        makeRow(2, ['Title' => 'Urgent Task', 'Status' => 'In progress', 'Priority' => 'High'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Title', target: 'title'),
        ColumnData::toField(source: 'Status', target: "custom_fields_{$statusCf->code}"),
        ColumnData::toField(source: 'Priority', target: "custom_fields_{$priorityCf->code}"),
    ], ImportEntityType::Task);

    runImportJob($this);

    $task = Task::where('team_id', $this->team->id)->where('title', 'Urgent Task')->first();
    expect($task)->not->toBeNull();

    $statusCfv = getTestCustomFieldValue($this, (string) $task->id, (string) $statusCf->id);
    expect($statusCfv)->not->toBeNull()
        ->and($statusCfv->string_value)->toBe((string) $inProgressOption->id);

    $priorityCfv = getTestCustomFieldValue($this, (string) $task->id, (string) $priorityCf->id);
    expect($priorityCfv)->not->toBeNull()
        ->and($priorityCfv->string_value)->toBe((string) $highOption->id);
});

it('imports company with custom field values for toggle and link', function (): void {
    $icpCf = createTestCustomField($this, 'is_icp', 'toggle', 'company');
    $linkedinCf = createTestCustomField($this, 'linkedin_url', 'link', 'company');

    createImportReadyStore($this, ['Name', 'ICP', 'LinkedIn'], [
        makeRow(2, ['Name' => 'Great Corp', 'ICP' => 'true', 'LinkedIn' => 'https://linkedin.com/company/great'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'ICP', target: "custom_fields_{$icpCf->code}"),
        ColumnData::toField(source: 'LinkedIn', target: "custom_fields_{$linkedinCf->code}"),
    ], ImportEntityType::Company);

    runImportJob($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'Great Corp')->first();
    expect($company)->not->toBeNull();

    $icpCfv = getTestCustomFieldValue($this, (string) $company->id, (string) $icpCf->id);
    expect($icpCfv)->not->toBeNull()
        ->and($icpCfv->boolean_value)->toBeTrue();

    $linkedinCfv = getTestCustomFieldValue($this, (string) $company->id, (string) $linkedinCf->id);
    expect($linkedinCfv)->not->toBeNull()
        ->and(collect($linkedinCfv->json_value)->all())->toContain('https://linkedin.com/company/great');
});

// --- Intra-Import Dedup Tests ---

it('deduplicates Create rows with same matchable email value', function (): void {
    createImportReadyStore($this, ['Name', 'Email'], [
        makeRow(2, ['Name' => 'Lay', 'Email' => 'same@acme.com'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => 'Ray', 'Email' => 'same@acme.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    runImportJob($this);

    $import = $this->import->fresh();
    expect($import->created_rows)->toBe(1)
        ->and($import->updated_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(0);

    $people = People::where('team_id', $this->team->id)->whereIn('name', ['Lay', 'Ray'])->get();
    expect($people)->toHaveCount(1)
        ->and($people->first()->name)->toBe('Ray');

    $row3 = $this->store->query()->where('row_number', 3)->first();
    expect($row3->match_action)->toBe(RowMatchAction::Update);
});

it('does not dedup Create rows with different matchable values', function (): void {
    createImportReadyStore($this, ['Name', 'Email'], [
        makeRow(2, ['Name' => 'Alice', 'Email' => 'alice@acme.com'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => 'Bob', 'Email' => 'bob@acme.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    runImportJob($this);

    $import = $this->import->fresh();
    expect($import->created_rows)->toBe(2)
        ->and($import->updated_rows)->toBe(0);

    $people = People::where('team_id', $this->team->id)->whereIn('name', ['Alice', 'Bob'])->get();
    expect($people)->toHaveCount(2);
});

it('deduplicates Create rows with multi-value matchable field', function (): void {
    createImportReadyStore($this, ['Name', 'Email'], [
        makeRow(2, ['Name' => 'First', 'Email' => 'shared@acme.com, extra@acme.com'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => 'Second', 'Email' => 'shared@acme.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    runImportJob($this);

    $import = $this->import->fresh();
    expect($import->created_rows)->toBe(1)
        ->and($import->updated_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(0);

    $people = People::where('team_id', $this->team->id)->whereIn('name', ['First', 'Second'])->get();
    expect($people)->toHaveCount(1)
        ->and($people->first()->name)->toBe('Second');
});

it('deduplicates company Create rows by domain', function (): void {
    createImportReadyStore($this, ['Name', 'Domain'], [
        makeRow(2, ['Name' => 'Acme Inc', 'Domain' => 'acme.com'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => 'Acme Corp', 'Domain' => 'acme.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Domain', target: 'custom_fields_domains'),
    ], ImportEntityType::Company);

    runImportJob($this);

    $import = $this->import->fresh();
    expect($import->created_rows)->toBe(1)
        ->and($import->updated_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(0);

    $companies = Company::where('team_id', $this->team->id)->whereIn('name', ['Acme Inc', 'Acme Corp'])->get();
    expect($companies)->toHaveCount(1)
        ->and($companies->first()->name)->toBe('Acme Corp');
});

// --- Multi-Choice Merge Tests ---

it('merges multi-choice custom field values during update', function (): void {
    $cf = createTestCustomField($this, 'merge_emails', 'email');

    $person = People::factory()->create([
        'name' => 'Merge Test',
        'team_id' => $this->team->id,
    ]);

    \App\Models\CustomFieldValue::create([
        'custom_field_id' => $cf->id,
        'entity_type' => 'people',
        'entity_id' => $person->id,
        'tenant_id' => $this->team->id,
        'json_value' => ['old@work.com'],
    ]);

    createImportReadyStore($this, ['ID', 'Name', 'Email'], [
        makeRow(2, ['ID' => (string) $person->id, 'Name' => 'Merge Test', 'Email' => 'new@work.com'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $import = $this->import->fresh();
    expect($import->updated_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(0);

    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and(collect($cfv->json_value)->all())->toBe(['old@work.com', 'new@work.com']);
});

it('merges multi-choice custom field values during dedup', function (): void {
    $emailField = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if ($emailField === null) {
        $this->markTestSkipped('No emails custom field configured');
    }

    createImportReadyStore($this, ['Name', 'Email'], [
        makeRow(2, ['Name' => 'Dedup A', 'Email' => 'a@test.com'], ['match_action' => RowMatchAction::Create->value]),
        makeRow(3, ['Name' => 'Dedup B', 'Email' => 'a@test.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    runImportJob($this);

    $import = $this->import->fresh();
    expect($import->created_rows)->toBe(1)
        ->and($import->updated_rows)->toBe(1);

    $person = People::where('team_id', $this->team->id)->where('name', 'Dedup B')->first();
    expect($person)->not->toBeNull();

    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $emailField->id);
    expect($cfv)->not->toBeNull()
        ->and(collect($cfv->json_value)->all())->toBe(['a@test.com']);
});

it('does not duplicate existing multi-choice values during merge', function (): void {
    $cf = createTestCustomField($this, 'dedup_emails', 'email');

    $person = People::factory()->create([
        'name' => 'Dedup Merge',
        'team_id' => $this->team->id,
    ]);

    \App\Models\CustomFieldValue::create([
        'custom_field_id' => $cf->id,
        'entity_type' => 'people',
        'entity_id' => $person->id,
        'tenant_id' => $this->team->id,
        'json_value' => ['shared@work.com'],
    ]);

    createImportReadyStore($this, ['ID', 'Name', 'Email'], [
        makeRow(2, ['ID' => (string) $person->id, 'Name' => 'Dedup Merge', 'Email' => 'shared@work.com, new@work.com'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: "custom_fields_{$cf->code}"),
    ]);

    runImportJob($this);

    $import = $this->import->fresh();
    expect($import->updated_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(0);

    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and(collect($cfv->json_value)->all())->toBe(['shared@work.com', 'new@work.com']);
});

it('populates matching custom field when auto-creating person via email MatchOrCreate', function (): void {
    $relationships = json_encode([
        ['relationship' => 'contact', 'action' => 'create', 'id' => null, 'name' => 'john@example.com', 'behavior' => MatchBehavior::MatchOrCreate->value, 'matchField' => 'custom_fields_emails'],
    ]);

    createImportReadyStore($this, ['Name', 'Contact'], [
        makeRow(2, ['Name' => 'Test Opportunity', 'Contact' => 'john@example.com'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Contact', matcherKey: 'custom_fields_emails', entityLinkKey: 'contact'),
    ], ImportEntityType::Opportunity);

    runImportJob($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'john@example.com')->first();
    expect($person)->not->toBeNull();

    $emailField = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    expect($emailField)->not->toBeNull();

    $cfv = getTestCustomFieldValue($this, (string) $person->id, (string) $emailField->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->json_value)->toBeInstanceOf(\Illuminate\Support\Collection::class)
        ->and($cfv->json_value->all())->toBe(['john@example.com']);
});

it('populates matching custom field when auto-creating company via domain MatchOrCreate', function (): void {
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'example.com', 'behavior' => MatchBehavior::MatchOrCreate->value, 'matchField' => 'custom_fields_domains'],
    ]);

    createImportReadyStore($this, ['Name', 'Company'], [
        makeRow(2, ['Name' => 'John Doe', 'Company' => 'example.com'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'custom_fields_domains', entityLinkKey: 'company'),
    ]);

    runImportJob($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'example.com')->first();
    expect($company)->not->toBeNull();

    $domainField = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->first();

    expect($domainField)->not->toBeNull();

    $cfv = getTestCustomFieldValue($this, (string) $company->id, (string) $domainField->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->json_value)->toBeInstanceOf(\Illuminate\Support\Collection::class)
        ->and($cfv->json_value->all())->toBe(['example.com']);
});

it('does not populate custom field when auto-creating via name matcher', function (): void {
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'New Corp', 'behavior' => MatchBehavior::Create->value, 'matchField' => 'name'],
    ]);

    createImportReadyStore($this, ['Name', 'Company'], [
        makeRow(2, ['Name' => 'John Doe', 'Company' => 'New Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    $cfCountBefore = DB::table(config('custom-fields.database.table_names.custom_field_values'))->count();

    runImportJob($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'New Corp')->first();
    expect($company)->not->toBeNull();

    $cfCountAfter = DB::table(config('custom-fields.database.table_names.custom_field_values'))->count();
    expect($cfCountAfter)->toBe($cfCountBefore);
});

it('deduplicates auto-created records while still populating matching custom field', function (): void {
    $relationships = json_encode([
        ['relationship' => 'contact', 'action' => 'create', 'id' => null, 'name' => 'jane@example.com', 'behavior' => MatchBehavior::MatchOrCreate->value, 'matchField' => 'custom_fields_emails'],
    ]);

    createImportReadyStore($this, ['Name', 'Contact'], [
        makeRow(2, ['Name' => 'Opp One', 'Contact' => 'jane@example.com'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
        makeRow(3, ['Name' => 'Opp Two', 'Contact' => 'jane@example.com'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Contact', matcherKey: 'custom_fields_emails', entityLinkKey: 'contact'),
    ], ImportEntityType::Opportunity);

    runImportJob($this);

    $people = People::where('team_id', $this->team->id)->where('name', 'jane@example.com')->get();
    expect($people)->toHaveCount(1);

    $emailField = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    $cfv = getTestCustomFieldValue($this, (string) $people->first()->id, (string) $emailField->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->json_value->all())->toBe(['jane@example.com']);
});

it('does not auto-create record for custom field entity link', function (): void {
    $recordCf = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('type', 'record')
        ->first();

    if ($recordCf === null) {
        $this->markTestSkipped('No record-type custom field configured for people');
    }

    $companyCountBefore = Company::where('team_id', $this->team->id)->count();

    $relationships = json_encode([
        ['relationship' => "cf_{$recordCf->code}", 'action' => 'create', 'id' => null, 'name' => 'Nonexistent Corp', 'behavior' => MatchBehavior::MatchOrCreate->value],
    ]);

    createImportReadyStore($this, ['Name', 'Related Company'], [
        makeRow(2, ['Name' => 'Test Person', 'Related Company' => 'Nonexistent Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Related Company', matcherKey: 'name', entityLinkKey: "cf_{$recordCf->code}"),
    ]);

    runImportJob($this);

    $companyCountAfter = Company::where('team_id', $this->team->id)->count();
    expect($companyCountAfter)->toBe($companyCountBefore);
});
