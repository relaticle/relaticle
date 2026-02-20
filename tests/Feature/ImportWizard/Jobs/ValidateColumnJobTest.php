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
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\EntityLinkValidator;
use Relaticle\ImportWizard\Support\Validation\ColumnValidator;

mutates(ValidateColumnJob::class, ColumnValidator::class, EntityLinkValidator::class);

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

function createValidationStore(
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
        'status' => ImportStatus::Reviewing,
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

it('writes RelationshipMatch create for Create entity links', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company'], [
        makeValidationRow(2, ['Name' => 'John', 'Company' => 'Acme Corp']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();
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

    createValidationStore($this, ['Name', 'Company ID'], [
        makeValidationRow(2, ['Name' => 'John', 'Company ID' => (string) $company->id]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1)
        ->and($row->relationships[0]->relationship)->toBe('company')
        ->and($row->relationships[0]->isExisting())->toBeTrue()
        ->and($row->relationships[0]->id)->toBe((string) $company->id);
});

it('skips relationship for MatchOnly when no match found', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company ID', matcherKey: 'id', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company ID'], [
        makeValidationRow(2, ['Name' => 'John', 'Company ID' => '99999']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->relationships)->toBeNull();
});

it('writes validation errors for unresolvable account owner entity link', function (): void {
    $column = ColumnData::toEntityLink(source: 'Owner Email', matcherKey: 'email', entityLinkKey: 'account_owner');

    createValidationStore($this, ['Name', 'Owner Email'], [
        makeValidationRow(1, ['Name' => 'Acme', 'Owner Email' => 'nonexistent@example.com']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ], ImportEntityType::Company);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 1)->first();

    expect($row->hasValidationError('Owner Email'))->toBeTrue();
});

it('writes validation errors for entity link column with invalid id', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company ID', matcherKey: 'id', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company ID'], [
        makeValidationRow(1, ['Name' => 'John', 'Company ID' => '99999']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 1)->first();

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

    createValidationStore($this, ['Name', 'Due Date'], [
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

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $correctedRow = $this->store->query()->where('row_number', 1)->first();
    $uncorrectedRow = $this->store->query()->where('row_number', 2)->first();

    expect($correctedRow->hasValidationError('Due Date'))->toBeFalse();
    expect($uncorrectedRow->hasValidationError('Due Date'))->toBeTrue();
});

it('skips validation when import does not exist', function (): void {
    $column = ColumnData::toField(source: 'Name', target: 'name');

    $job = new ValidateColumnJob('nonexistent-import-id', $column);

    try {
        $job->handle();
        expect(false)->toBeTrue('Expected exception was not thrown');
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        expect($e->getModel())->toBe(Import::class);
    }
});

it('skips validation when all values are empty', function (): void {
    $column = ColumnData::toField(source: 'Owner Email', target: 'account_owner_email');

    createValidationStore($this, ['Name', 'Owner Email'], [
        makeValidationRow(1, ['Name' => 'Acme', 'Owner Email' => '']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ], ImportEntityType::Company);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 1)->first();
    expect($row->validation)->toBeNull();
});

it('appends to existing relationships array without overwriting', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company');

    $existingRelationship = [
        'relationship' => 'contact',
        'action' => 'create',
        'name' => 'Jane Doe',
    ];

    createValidationStore($this, ['Name', 'Company'], [
        makeValidationRow(2, ['Name' => 'John', 'Company' => 'Acme Corp'], [
            'relationships' => json_encode([$existingRelationship]),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->relationships)->toHaveCount(2)
        ->and($row->relationships[0]->relationship)->toBe('contact')
        ->and($row->relationships[1]->relationship)->toBe('company');
});

it('validates color picker hex format', function (): void {
    $column = ColumnData::toField(source: 'Color', target: 'custom_fields_brand_color');
    $column->importField = new ImportField(
        key: 'custom_fields_brand_color',
        label: 'Brand Color',
        rules: ['regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
        isCustomField: true,
        type: FieldDataType::STRING,
    );

    createValidationStore($this, ['Name', 'Color'], [
        makeValidationRow(1, ['Name' => 'John', 'Color' => 'not-a-color']),
        makeValidationRow(2, ['Name' => 'Jane', 'Color' => '#ff5733']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $invalidRow = $this->store->query()->where('row_number', 1)->first();
    $validRow = $this->store->query()->where('row_number', 2)->first();

    expect($invalidRow->hasValidationError('Color'))->toBeTrue()
        ->and($validRow->hasValidationError('Color'))->toBeFalse();
});

// --- Entity Link Format Validation Tests ---

function ensureCustomFieldExists(object $context, string $code, string $type, string $entityType = 'people'): \App\Models\CustomField
{
    $existing = \App\Models\CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $context->team->id)
        ->where('entity_type', $entityType)
        ->where('code', $code)
        ->first();

    if ($existing !== null) {
        return $existing;
    }

    return \App\Models\CustomField::forceCreate([
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
}

it('rejects invalid domain format in entity link and prevents relationship creation', function (): void {
    ensureCustomFieldExists($this, 'domains', 'link', 'company');

    $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'custom_fields_domains', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company'], [
        makeValidationRow(2, ['Name' => 'John', 'Company' => 'GlobalHealth Inc']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();

    expect($row->hasValidationError('Company'))->toBeTrue()
        ->and($row->relationships)->toBeNull();
});

it('accepts valid domain format in entity link and creates relationship', function (): void {
    ensureCustomFieldExists($this, 'domains', 'link', 'company');

    $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'custom_fields_domains', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company'], [
        makeValidationRow(2, ['Name' => 'John', 'Company' => 'globalhealth.io']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();

    expect($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1)
        ->and($row->relationships[0]->isCreate())->toBeTrue()
        ->and($row->relationships[0]->name)->toBe('globalhealth.io');
});

it('rejects invalid email format in entity link', function (): void {
    ensureCustomFieldExists($this, 'emails', 'email');

    $column = ColumnData::toEntityLink(source: 'Contact', matcherKey: 'custom_fields_emails', entityLinkKey: 'contact');

    createValidationStore($this, ['Name', 'Contact'], [
        makeValidationRow(2, ['Name' => 'Deal', 'Contact' => 'not-an-email']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ], ImportEntityType::Opportunity);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();

    expect($row->hasValidationError('Contact'))->toBeTrue()
        ->and($row->relationships)->toBeNull();
});

it('accepts valid email format in entity link and creates relationship', function (): void {
    ensureCustomFieldExists($this, 'emails', 'email');

    $column = ColumnData::toEntityLink(source: 'Contact', matcherKey: 'custom_fields_emails', entityLinkKey: 'contact');

    createValidationStore($this, ['Name', 'Contact'], [
        makeValidationRow(2, ['Name' => 'Deal', 'Contact' => 'john@example.com']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ], ImportEntityType::Opportunity);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();

    expect($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1)
        ->and($row->relationships[0]->isCreate())->toBeTrue()
        ->and($row->relationships[0]->name)->toBe('john@example.com');
});

it('skips format validation for name matcher on entity link', function (): void {
    $column = ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company');

    createValidationStore($this, ['Name', 'Company'], [
        makeValidationRow(2, ['Name' => 'John', 'Company' => 'Acme Corp']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ]);

    (new ValidateColumnJob($this->import->id, $column))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();

    expect($row->hasValidationError('Company'))->toBeFalse()
        ->and($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1);
});
