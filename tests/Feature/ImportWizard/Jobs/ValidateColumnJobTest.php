<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Jobs\ValidateColumnJob;
use Relaticle\ImportWizard\Store\ImportStore;

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
): ImportStore {
    $store = ImportStore::create(
        teamId: (string) $context->team->id,
        userId: (string) $context->user->id,
        entityType: ImportEntityType::People,
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
