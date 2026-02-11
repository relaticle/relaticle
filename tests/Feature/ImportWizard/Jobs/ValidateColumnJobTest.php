<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Jobs\ValidateColumnJob;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\EntityLinkValidator;
use Relaticle\ImportWizard\Support\Validation\ColumnValidator;

mutates(ValidateColumnJob::class, ColumnValidator::class, EntityLinkValidator::class);

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

function createValidationStore(
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
    $store->setStatus(ImportStatus::Reviewing);

    $context->store = $store;

    return $store;
}

function makeValidationRow(int $rowNumber, array $rawData, array $overrides = []): array
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

it('writes RelationshipMatch create for AlwaysCreate entity links', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company');

    $store = createValidationStore($this, ['Name', 'Company'], [
        makeValidationRow(2, ['Name' => 'John', 'Company' => 'Acme Corp']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($store->id(), $column, $store->teamId()))->handle();

    $row = $store->query()->where('row_number', 2)->first();
    expect($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1)
        ->and($row->relationships[0]->relationship)->toBe('company')
        ->and($row->relationships[0]->isCreate())->toBeTrue()
        ->and($row->relationships[0]->name)->toBe('Acme Corp');
});

it('writes RelationshipMatch existing when resolved to existing record', function (): void {
    $company = Company::factory()->create([
        'name' => 'Acme Corp',
        'team_id' => $this->team->id,
    ]);

    $column = ColumnData::toEntityLink(source: 'Company ID', matcherKey: 'id', entityLinkKey: 'company');

    $store = createValidationStore($this, ['Name', 'Company ID'], [
        makeValidationRow(2, ['Name' => 'John', 'Company ID' => (string) $company->id]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($store->id(), $column, $store->teamId()))->handle();

    $row = $store->query()->where('row_number', 2)->first();
    expect($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1)
        ->and($row->relationships[0]->relationship)->toBe('company')
        ->and($row->relationships[0]->isExisting())->toBeTrue()
        ->and($row->relationships[0]->id)->toBe((string) $company->id);
});

it('skips relationship for UpdateOnly when no match found', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company ID', matcherKey: 'id', entityLinkKey: 'company');

    $store = createValidationStore($this, ['Name', 'Company ID'], [
        makeValidationRow(2, ['Name' => 'John', 'Company ID' => '99999']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($store->id(), $column, $store->teamId()))->handle();

    $row = $store->query()->where('row_number', 2)->first();
    expect($row->relationships)->toBeNull();
});

it('writes validation errors for invalid email field values', function (): void {
    $column = ColumnData::toField(source: 'Owner Email', target: 'account_owner_email');

    $store = createValidationStore($this, ['Name', 'Owner Email'], [
        makeValidationRow(1, ['Name' => 'Acme', 'Owner Email' => 'valid@example.com']),
        makeValidationRow(2, ['Name' => 'Beta', 'Owner Email' => 'not-an-email']),
        makeValidationRow(3, ['Name' => 'Gamma', 'Owner Email' => 'also@valid.org']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ], ImportEntityType::Company);

    (new ValidateColumnJob($store->id(), $column, $store->teamId()))->handle();

    $invalidRow = $store->query()->where('row_number', 2)->first();

    expect($invalidRow->hasValidationError('Owner Email'))->toBeTrue();
});

it('writes validation errors for entity link column with invalid id', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company ID', matcherKey: 'id', entityLinkKey: 'company');

    $store = createValidationStore($this, ['Name', 'Company ID'], [
        makeValidationRow(1, ['Name' => 'John', 'Company ID' => '99999']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($store->id(), $column, $store->teamId()))->handle();

    $row = $store->query()->where('row_number', 1)->first();

    expect($row->hasValidationError('Company ID'))->toBeTrue();
});

it('clears validation for corrected date fields', function (): void {
    $column = ColumnData::toField(source: 'Due Date', target: 'due_date');
    $column->importField = new ImportField(
        key: 'due_date',
        label: 'Due Date',
        rules: ['nullable', 'date'],
        type: FieldDataType::DATE,
    );

    $store = createValidationStore($this, ['Name', 'Due Date'], [
        makeValidationRow(1, ['Name' => 'Task 1', 'Due Date' => 'not-a-date'], [
            'validation' => json_encode(['Due Date' => 'Invalid date format']),
            'corrections' => json_encode(['Due Date' => '2024-01-15']),
        ]),
        makeValidationRow(2, ['Name' => 'Task 2', 'Due Date' => 'also-invalid'], [
            'validation' => json_encode(['Due Date' => 'Invalid date format']),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ], ImportEntityType::Task);

    (new ValidateColumnJob($store->id(), $column, $store->teamId()))->handle();

    $correctedRow = $store->query()->where('row_number', 1)->first();
    $uncorrectedRow = $store->query()->where('row_number', 2)->first();

    expect($correctedRow->hasValidationError('Due Date'))->toBeFalse();
    expect($uncorrectedRow->hasValidationError('Due Date'))->toBeTrue();
});

it('skips validation when store does not exist', function (): void {
    $column = ColumnData::toField(source: 'Name', target: 'name');

    $job = new ValidateColumnJob('nonexistent-store-id', $column, (string) $this->team->id);
    $job->handle();

    expect(true)->toBeTrue();
});

it('skips validation when all values are empty', function (): void {
    $column = ColumnData::toField(source: 'Owner Email', target: 'account_owner_email');

    $store = createValidationStore($this, ['Name', 'Owner Email'], [
        makeValidationRow(1, ['Name' => 'Acme', 'Owner Email' => '']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ], ImportEntityType::Company);

    (new ValidateColumnJob($store->id(), $column, $store->teamId()))->handle();

    $row = $store->query()->where('row_number', 1)->first();
    expect($row->validation)->toBeNull();
});

it('appends to existing relationships array without overwriting', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company');

    $existingRelationship = [
        'relationship' => 'contact',
        'action' => 'create',
        'name' => 'Jane Doe',
    ];

    $store = createValidationStore($this, ['Name', 'Company'], [
        makeValidationRow(2, ['Name' => 'John', 'Company' => 'Acme Corp'], [
            'relationships' => json_encode([$existingRelationship]),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($store->id(), $column, $store->teamId()))->handle();

    $row = $store->query()->where('row_number', 2)->first();
    expect($row->relationships)->toHaveCount(2)
        ->and($row->relationships[0]->relationship)->toBe('contact')
        ->and($row->relationships[1]->relationship)->toBe('company');
});
