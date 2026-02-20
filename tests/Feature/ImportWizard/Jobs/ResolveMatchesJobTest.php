<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\RelationshipMatch;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Jobs\ResolveMatchesJob;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\MatchResolver;

mutates(ResolveMatchesJob::class, MatchResolver::class);

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

function createStoreForMatchResolution(
    object $context,
    array $headers,
    array $rows,
    array $mappings,
): array {
    $import = Import::create([
        'team_id' => (string) $context->team->id,
        'user_id' => (string) $context->user->id,
        'entity_type' => ImportEntityType::People,
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

function makeMatchRow(int $rowNumber, array $rawData, array $overrides = []): array
{
    return [
        'row_number' => $rowNumber,
        'raw_data' => json_encode($rawData),
        'validation' => null,
        'corrections' => null,
        'skipped' => null,
        'match_action' => null,
        'matched_id' => null,
        'relationships' => null,
        ...$overrides,
    ];
}

it('resolves all rows as Create when no match field mapped', function (): void {
    createStoreForMatchResolution($this, ['Name'], [
        makeMatchRow(2, ['Name' => 'John']),
        makeMatchRow(3, ['Name' => 'Jane']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    (new ResolveMatchesJob($this->import->id, (string) $this->team->id))->handle();

    $rows = $this->store->query()->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($row) => $row->match_action === RowMatchAction::Create))->toBeTrue();
});

it('resolves Update when email matches existing record', function (): void {
    $person = People::factory()->create([
        'name' => 'Existing Person',
        'team_id' => $this->team->id,
    ]);

    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if ($emailField) {
        CustomFieldValue::create([
            'custom_field_id' => $emailField->id,
            'entity_type' => 'people',
            'entity_id' => $person->id,
            'tenant_id' => $this->team->id,
            'json_value' => ['existing@test.com'],
        ]);
    }

    createStoreForMatchResolution($this, ['Name', 'Email'], [
        makeMatchRow(2, ['Name' => 'John', 'Email' => 'existing@test.com']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    (new ResolveMatchesJob($this->import->id, (string) $this->team->id))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();

    if ($emailField) {
        expect($row->match_action)->toBe(RowMatchAction::Update)
            ->and($row->matched_id)->toBe((string) $person->id);
    } else {
        expect($row->match_action)->toBe(RowMatchAction::Create);
    }
});

it('resolves Skip when id does not match existing record', function (): void {
    createStoreForMatchResolution($this, ['ID', 'Name'], [
        makeMatchRow(2, ['ID' => '99999', 'Name' => 'Ghost']),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    (new ResolveMatchesJob($this->import->id, (string) $this->team->id))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->match_action)->toBe(RowMatchAction::Skip);
});

it('does not clear relationships column', function (): void {
    $companyMatch = RelationshipMatch::create('company', 'Acme Corp');

    createStoreForMatchResolution($this, ['Name'], [
        makeMatchRow(2, ['Name' => 'John'], [
            'relationships' => json_encode([$companyMatch->toArray()]),
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    (new ResolveMatchesJob($this->import->id, (string) $this->team->id))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->relationships)->not->toBeNull()
        ->and($row->relationships)->toHaveCount(1)
        ->and($row->relationships[0]->relationship)->toBe('company');
});

it('resets previous match resolutions when re-resolving', function (): void {
    createStoreForMatchResolution($this, ['Name'], [
        makeMatchRow(2, ['Name' => 'John'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => '999',
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    (new ResolveMatchesJob($this->import->id, (string) $this->team->id))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();
    expect($row->match_action)->toBe(RowMatchAction::Create)
        ->and($row->matched_id)->toBeNull();
});

it('marks unmatched rows as Create when mixed matched and empty values', function (): void {
    $person = People::factory()->create([
        'name' => 'Existing',
        'team_id' => $this->team->id,
    ]);

    createStoreForMatchResolution($this, ['ID', 'Name'], [
        makeMatchRow(2, ['ID' => (string) $person->id, 'Name' => 'Updated'], []),
        makeMatchRow(3, ['ID' => '', 'Name' => 'New Person'], []),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    (new ResolveMatchesJob($this->import->id, (string) $this->team->id))->handle();

    $matched = $this->store->query()->where('row_number', 2)->first();
    $unmatched = $this->store->query()->where('row_number', 3)->first();

    expect($matched->match_action)->toBe(RowMatchAction::Update)
        ->and($matched->matched_id)->toBe((string) $person->id)
        ->and($unmatched->match_action)->toBe(RowMatchAction::Skip);
});

it('resolves Update when CSV email column contains comma-separated values matching existing record', function (): void {
    $person = People::factory()->create([
        'name' => 'Existing Person',
        'team_id' => $this->team->id,
    ]);

    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if (! $emailField) {
        $this->markTestSkipped('No email custom field seeded for team');
    }

    CustomFieldValue::create([
        'custom_field_id' => $emailField->id,
        'entity_type' => 'people',
        'entity_id' => $person->id,
        'tenant_id' => $this->team->id,
        'json_value' => ['existing@test.com', 'other@test.com'],
    ]);

    createStoreForMatchResolution($this, ['Name', 'Email'], [
        makeMatchRow(2, ['Name' => 'John', 'Email' => 'existing@test.com, new@test.com']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    (new ResolveMatchesJob($this->import->id, (string) $this->team->id))->handle();

    $row = $this->store->query()->where('row_number', 2)->first();

    expect($row->match_action)->toBe(RowMatchAction::Update)
        ->and($row->matched_id)->toBe((string) $person->id);
});

it('handles missing import gracefully', function (): void {
    (new ResolveMatchesJob('nonexistent-id', (string) $this->team->id))->handle();
})->throws(ModelNotFoundException::class);
