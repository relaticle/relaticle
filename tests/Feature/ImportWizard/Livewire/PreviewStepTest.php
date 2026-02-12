<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Livewire\Livewire;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\RelationshipMatch;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Livewire\Steps\PreviewStep;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\MatchResolver;

mutates(PreviewStep::class, MatchResolver::class);

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
    if (isset($this->import)) {
        $this->import->delete();
    }
});

function createPreviewReadyStore(
    object $context,
    array $headers,
    array $rows,
    array $mappings,
    ImportEntityType $entityType = ImportEntityType::People,
): ImportStore {
    $import = Import::create([
        'team_id' => (string) $context->team->id,
        'user_id' => (string) $context->user->id,
        'entity_type' => $entityType,
        'file_name' => 'test.csv',
        'status' => ImportStatus::Reviewing,
        'total_rows' => count($rows),
        'headers' => $headers,
        'column_mappings' => collect($mappings)->map(fn (ColumnData $m) => $m->toArray())->all(),
    ]);

    $store = ImportStore::create($import->id);
    $store->query()->insert($rows);

    (new MatchResolver($store, $import, $import->getImporter()))->resolve();

    $context->import = $import;
    $context->store = $store;

    return $store;
}

function mountPreviewStep(object $context): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(PreviewStep::class, [
        'storeId' => $context->store->id(),
        'entityType' => ImportEntityType::People,
    ]);
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

it('mounts and renders with summary data', function (): void {
    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
        makeRow(3, ['Name' => 'Jane']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);

    $component->assertOk()
        ->assertSee('People')
        ->assertSee('Start Import');
});

it('resolves all rows as Create when only name is mapped', function (): void {
    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
        makeRow(3, ['Name' => 'Jane']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    mountPreviewStep($this);

    $rows = $this->store->query()->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($row) => $row->match_action === RowMatchAction::Create))->toBeTrue();
});

it('resolves rows as Update when email matches existing record', function (): void {
    $person = People::factory()->create([
        'name' => 'Existing Person',
        'team_id' => $this->team->id,
    ]);

    $emailField = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if ($emailField) {
        \App\Models\CustomFieldValue::create([
            'custom_field_id' => $emailField->id,
            'entity_type' => 'people',
            'entity_id' => $person->id,
            'tenant_id' => $this->team->id,
            'json_value' => ['existing@test.com'],
        ]);
    }

    createPreviewReadyStore($this, ['Name', 'Email'], [
        makeRow(2, ['Name' => 'John', 'Email' => 'existing@test.com']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    mountPreviewStep($this);

    $row = $this->store->query()->where('row_number', 2)->first();

    if ($emailField) {
        expect($row->match_action)->toBe(RowMatchAction::Update)
            ->and($row->matched_id)->toBe((string) $person->id);
    } else {
        expect($row->match_action)->toBe(RowMatchAction::Create);
    }
});

it('resolves rows as Create when email does not match existing record', function (): void {
    createPreviewReadyStore($this, ['Name', 'Email'], [
        makeRow(2, ['Name' => 'John', 'Email' => 'nonexistent@test.com']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    mountPreviewStep($this);

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->match_action)->toBe(RowMatchAction::Create);
});

it('resolves rows as Skip when id does not match existing record', function (): void {
    createPreviewReadyStore($this, ['ID', 'Name'], [
        makeRow(2, ['ID' => '99999', 'Name' => 'Ghost']),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    mountPreviewStep($this);

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->match_action)->toBe(RowMatchAction::Skip);
});

it('resolves rows as Update when id matches existing record', function (): void {
    $person = People::factory()->create([
        'name' => 'Existing Person',
        'team_id' => $this->team->id,
    ]);

    createPreviewReadyStore($this, ['ID', 'Name'], [
        makeRow(2, ['ID' => (string) $person->id, 'Name' => 'Updated Name']),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    mountPreviewStep($this);

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->match_action)->toBe(RowMatchAction::Update)
        ->and($row->matched_id)->toBe((string) $person->id);
});

it('renders entity link relationships from pre-populated data', function (): void {
    $companyMatch = RelationshipMatch::create('company', 'Acme Corp');

    createPreviewReadyStore($this, ['Name', 'Company'], [
        makeRow(2, ['Name' => 'John', 'Company' => 'Acme Corp'], [
            'relationships' => json_encode([$companyMatch->toArray()]),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    mountPreviewStep($this);

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1)
        ->and($row->relationships[0]->relationship)->toBe('company')
        ->and($row->relationships[0]->isCreate())->toBeTrue()
        ->and($row->relationships[0]->name)->toBe('Acme Corp');
});

it('handles rows with no entity link relationships', function (): void {
    createPreviewReadyStore($this, ['Name', 'Company ID'], [
        makeRow(2, ['Name' => 'John', 'Company ID' => '99999']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company ID', matcherKey: 'id', entityLinkKey: 'company'),
    ]);

    mountPreviewStep($this);

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->relationships)->toBeNull();
});

it('renders existing entity link relationships from pre-populated data', function (): void {
    $company = Company::factory()->create([
        'name' => 'Acme Corp',
        'team_id' => $this->team->id,
    ]);

    $companyMatch = RelationshipMatch::existing('company', (string) $company->id);

    createPreviewReadyStore($this, ['Name', 'Company ID'], [
        makeRow(2, ['Name' => 'John', 'Company ID' => (string) $company->id], [
            'relationships' => json_encode([$companyMatch->toArray()]),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company ID', matcherKey: 'id', entityLinkKey: 'company'),
    ]);

    mountPreviewStep($this);

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1)
        ->and($row->relationships[0]->relationship)->toBe('company')
        ->and($row->relationships[0]->isExisting())->toBeTrue()
        ->and($row->relationships[0]->id)->toBe((string) $company->id);
});

it('resolves rows with validation errors through normal match resolution', function (): void {
    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John'], ['validation' => json_encode(['Name' => 'Name is required'])]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    mountPreviewStep($this);

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->match_action)->toBe(RowMatchAction::Create);
});

it('createCount returns correct count', function (): void {
    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
        makeRow(3, ['Name' => 'Jane']),
        makeRow(4, ['Name' => 'Bob']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);

    expect($component->get('createCount'))->toBe(3);
});

it('updateCount returns correct count', function (): void {
    $person = People::factory()->create([
        'name' => 'Existing',
        'team_id' => $this->team->id,
    ]);

    createPreviewReadyStore($this, ['ID', 'Name'], [
        makeRow(2, ['ID' => (string) $person->id, 'Name' => 'Updated']),
        makeRow(3, ['ID' => '99999', 'Name' => 'Ghost']),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);

    expect($component->get('updateCount'))->toBe(1);
});

it('skipCount returns correct count', function (): void {
    createPreviewReadyStore($this, ['ID', 'Name'], [
        makeRow(2, ['ID' => '99999', 'Name' => 'Ghost']),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);

    expect($component->get('skipCount'))->toBe(1);
});

it('errorCount returns count of rows with validation errors', function (): void {
    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John'], ['validation' => json_encode(['Name' => 'Error'])]),
        makeRow(3, ['Name' => 'Jane']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);

    expect($component->get('errorCount'))->toBe(1);
});

it('startImport dispatches ExecuteImportJob and sets status to Importing', function (): void {
    Bus::fake();

    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);
    $component->call('startImport');

    Bus::assertBatched(function ($batch) {
        return $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof ExecuteImportJob;
    });

    $freshImport = $this->import->fresh();
    expect($freshImport->status)->toBe(ImportStatus::Importing);
});

it('startImport proceeds even when rows have validation errors', function (): void {
    Bus::fake();

    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John'], ['validation' => json_encode(['Name' => 'Error'])]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);
    $component->call('startImport');

    Bus::assertBatched(function ($batch) {
        return $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof ExecuteImportJob;
    });
});

it('startImport sets batchId for progress tracking', function (): void {
    Bus::fake();

    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);
    $component->call('startImport');

    expect($component->get('batchId'))->not->toBeNull();
});

it('isImporting returns true while batch is running', function (): void {
    Bus::fake();

    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);
    $component->call('startImport');

    expect($component->get('isImporting'))->toBeTrue();
});

it('does not skip rows with validation errors that are covered by per-value skips', function (): void {
    createPreviewReadyStore($this, ['Name', 'Email'], [
        makeRow(2, ['Name' => 'John', 'Email' => 'bad-email'], [
            'validation' => json_encode(['$.Email' => 'Invalid email']),
            'skipped' => json_encode(['$.Email' => true]),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    $component = mountPreviewStep($this);

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->match_action)->toBe(RowMatchAction::Create);
});

it('previewRows returns paginated rows', function (): void {
    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
        makeRow(3, ['Name' => 'Jane']),
        makeRow(4, ['Name' => 'Bob']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);

    $previewRows = $component->get('previewRows');
    expect($previewRows)->toBeInstanceOf(\Illuminate\Contracts\Pagination\LengthAwarePaginator::class)
        ->and($previewRows->total())->toBe(3)
        ->and($previewRows->items())->toHaveCount(3);
});

it('columns returns mapped column data', function (): void {
    createPreviewReadyStore($this, ['Name', 'Email'], [
        makeRow(2, ['Name' => 'John', 'Email' => 'john@test.com']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    $component = mountPreviewStep($this);

    $columns = $component->get('columns');
    expect($columns)->toHaveCount(2)
        ->and($columns->first()->source)->toBe('Name')
        ->and($columns->last()->source)->toBe('Email');
});

it('relationshipTabs returns entity link tabs', function (): void {
    $companyMatch = RelationshipMatch::create('company', 'Acme');

    createPreviewReadyStore($this, ['Name', 'Company'], [
        makeRow(2, ['Name' => 'John', 'Company' => 'Acme'], [
            'relationships' => json_encode([$companyMatch->toArray()]),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    $component = mountPreviewStep($this);

    $tabs = $component->get('relationshipTabs');
    expect($tabs)->toHaveCount(1)
        ->and($tabs[0]['key'])->toBe('company')
        ->and($tabs[0]['label'])->toBe('Company');
});

it('relationshipTabs returns empty when no entity links mapped', function (): void {
    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);

    expect($component->get('relationshipTabs'))->toBeEmpty();
});

it('relationshipSummary aggregates by entity link key', function (): void {
    $acmeMatch = RelationshipMatch::create('company', 'Acme Corp');
    $newCorpMatch = RelationshipMatch::create('company', 'New Corp');

    createPreviewReadyStore($this, ['Name', 'Company'], [
        makeRow(2, ['Name' => 'John', 'Company' => 'Acme Corp'], [
            'relationships' => json_encode([$acmeMatch->toArray()]),
        ]),
        makeRow(3, ['Name' => 'Jane', 'Company' => 'Acme Corp'], [
            'relationships' => json_encode([$acmeMatch->toArray()]),
        ]),
        makeRow(4, ['Name' => 'Bob', 'Company' => 'New Corp'], [
            'relationships' => json_encode([$newCorpMatch->toArray()]),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    $component = mountPreviewStep($this);
    $component->call('setActiveTab', 'company');

    $summary = $component->get('relationshipSummary');

    $createEntries = collect($summary->items())->where('action', 'create');
    $totalCreated = $createEntries->sum('count');

    expect($totalCreated)->toBe(3);
});

it('relationshipSummary returns empty on all tab', function (): void {
    $companyMatch = RelationshipMatch::create('company', 'Acme');

    createPreviewReadyStore($this, ['Name', 'Company'], [
        makeRow(2, ['Name' => 'John', 'Company' => 'Acme'], [
            'relationships' => json_encode([$companyMatch->toArray()]),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    $component = mountPreviewStep($this);

    expect($component->get('relationshipSummary'))->toBeEmpty();
});

it('setActiveTab changes tab and resets page', function (): void {
    $companyMatch = RelationshipMatch::create('company', 'Acme');

    createPreviewReadyStore($this, ['Name', 'Company'], [
        makeRow(2, ['Name' => 'John', 'Company' => 'Acme'], [
            'relationships' => json_encode([$companyMatch->toArray()]),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    $component = mountPreviewStep($this);

    expect($component->get('activeTab'))->toBe('all');

    $component->call('setActiveTab', 'company');
    expect($component->get('activeTab'))->toBe('company');

    $component->call('setActiveTab', 'all');
    expect($component->get('activeTab'))->toBe('all');
});

it('renders preview data table with row values', function (): void {
    createPreviewReadyStore($this, ['Name', 'Email'], [
        makeRow(2, ['Name' => 'John', 'Email' => 'john@test.com']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    $component = mountPreviewStep($this);

    $component->assertOk()
        ->assertSee('People')
        ->assertSee('John')
        ->assertSee('john@test.com')
        ->assertSee('will be created');
});

it('renders skipped and invalid cells as empty', function (): void {
    createPreviewReadyStore($this, ['Name', 'Email'], [
        makeRow(2, ['Name' => 'John', 'Email' => 'bad'], [
            'validation' => json_encode(['Email' => 'Invalid']),
            'skipped' => json_encode(['Name' => true]),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    $component = mountPreviewStep($this);

    $component->assertOk()
        ->assertDontSee('John')
        ->assertDontSee('bad');
});

it('checkImportProgress detects completion', function (): void {
    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);

    $this->import->update([
        'status' => ImportStatus::Completed,
        'results' => ['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0],
    ]);

    $component->set('batchId', 'fake-batch-id');
    $component->call('checkImportProgress');

    expect($component->get('isCompleted'))->toBeTrue();
});

it('startImport is a no-op when batchId is already set', function (): void {
    Bus::fake();

    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);
    $component->set('batchId', 'existing-batch-id');
    $component->call('startImport');

    Bus::assertNothingBatched();
});

it('startImport is a no-op when already completed', function (): void {
    Bus::fake();

    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $component = mountPreviewStep($this);
    $component->set('isCompleted', true);
    $component->call('startImport');

    Bus::assertNothingBatched();
});

it('startImport is a no-op when status is already Importing', function (): void {
    Bus::fake();

    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $this->import->update(['status' => ImportStatus::Importing]);

    $component = mountPreviewStep($this);
    $component->call('startImport');

    Bus::assertNothingBatched();
});

it('downloadFailedRows action is visible when there are failed rows', function (): void {
    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $this->import->update([
        'status' => ImportStatus::Completed,
        'results' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 1],
    ]);

    $component = mountPreviewStep($this);

    $component->assertSee('Download Failed Rows');
});

it('downloadFailedRows action is hidden when there are no failed rows', function (): void {
    createPreviewReadyStore($this, ['Name'], [
        makeRow(2, ['Name' => 'John']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    $this->import->update([
        'status' => ImportStatus::Completed,
        'results' => ['created' => 1, 'updated' => 0, 'skipped' => 0, 'failed' => 0],
    ]);

    $component = mountPreviewStep($this);

    $component->assertDontSee('Download Failed Rows');
});
