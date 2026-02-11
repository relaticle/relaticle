<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\RelationshipMatch;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Jobs\ResolveMatchesJob;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\MatchResolver;

mutates(ResolveMatchesJob::class, MatchResolver::class);

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

function createStoreForMatchResolution(
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

function makeMatchRow(int $rowNumber, array $rawData, array $overrides = []): array
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

it('resolves all rows as Create when no match field mapped', function (): void {
    $store = createStoreForMatchResolution($this, ['Name'], [
        makeMatchRow(2, ['Name' => 'John']),
        makeMatchRow(3, ['Name' => 'Jane']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    (new ResolveMatchesJob($store->id(), $store->teamId()))->handle();

    $rows = $store->query()->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($row) => $row->match_action === RowMatchAction::Create))->toBeTrue();
});

it('resolves Update when email matches existing record', function (): void {
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

    $store = createStoreForMatchResolution($this, ['Name', 'Email'], [
        makeMatchRow(2, ['Name' => 'John', 'Email' => 'existing@test.com']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    (new ResolveMatchesJob($store->id(), $store->teamId()))->handle();

    $row = $store->query()->where('row_number', 2)->first();

    if ($emailField) {
        expect($row->match_action)->toBe(RowMatchAction::Update)
            ->and($row->matched_id)->toBe((string) $person->id);
    } else {
        expect($row->match_action)->toBe(RowMatchAction::Create);
    }
});

it('resolves Skip when id does not match existing record', function (): void {
    $store = createStoreForMatchResolution($this, ['ID', 'Name'], [
        makeMatchRow(2, ['ID' => '99999', 'Name' => 'Ghost']),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    (new ResolveMatchesJob($store->id(), $store->teamId()))->handle();

    $row = $store->query()->where('row_number', 2)->first();
    expect($row->match_action)->toBe(RowMatchAction::Skip);
});

it('does not clear relationships column', function (): void {
    $companyMatch = RelationshipMatch::create('company', 'Acme Corp');

    $store = createStoreForMatchResolution($this, ['Name'], [
        makeMatchRow(2, ['Name' => 'John'], [
            'relationships' => json_encode([$companyMatch->toArray()]),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    (new ResolveMatchesJob($store->id(), $store->teamId()))->handle();

    $row = $store->query()->where('row_number', 2)->first();
    expect($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1)
        ->and($row->relationships[0]->relationship)->toBe('company');
});

it('resets previous match resolutions when re-resolving', function (): void {
    $store = createStoreForMatchResolution($this, ['Name'], [
        makeMatchRow(2, ['Name' => 'John'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => '999',
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    (new ResolveMatchesJob($store->id(), $store->teamId()))->handle();

    $row = $store->query()->where('row_number', 2)->first();
    expect($row->match_action)->toBe(RowMatchAction::Create)
        ->and($row->matched_id)->toBeNull();
});

it('marks unmatched rows as Create when mixed matched and empty values', function (): void {
    $person = People::factory()->create([
        'name' => 'Existing',
        'team_id' => $this->team->id,
    ]);

    $store = createStoreForMatchResolution($this, ['ID', 'Name'], [
        makeMatchRow(2, ['ID' => (string) $person->id, 'Name' => 'Updated'], []),
        makeMatchRow(3, ['ID' => '', 'Name' => 'New Person'], []),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    (new ResolveMatchesJob($store->id(), $store->teamId()))->handle();

    $matched = $store->query()->where('row_number', 2)->first();
    $unmatched = $store->query()->where('row_number', 3)->first();

    expect($matched->match_action)->toBe(RowMatchAction::Update)
        ->and($matched->matched_id)->toBe((string) $person->id)
        ->and($unmatched->match_action)->toBe(RowMatchAction::Skip);
});

it('handles missing store gracefully', function (): void {
    $job = new ResolveMatchesJob('nonexistent-id', (string) $this->team->id);

    $job->handle();
})->throwsNoExceptions();
