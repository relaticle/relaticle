<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\MatchBehavior;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Models\Import;
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

// --- Intra-Import Dedup Tests ---

it('deduplicates Create rows with same matchable email value', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name', 'Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'Lay', 'Email' => 'same@acme.com'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Ray', 'Email' => 'same@acme.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    ImportExecutionFixture::run($this);

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
    ImportExecutionFixture::readyStore($this, ['Name', 'Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'Alice', 'Email' => 'alice@acme.com'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Bob', 'Email' => 'bob@acme.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->created_rows)->toBe(2)
        ->and($import->updated_rows)->toBe(0);

    $people = People::where('team_id', $this->team->id)->whereIn('name', ['Alice', 'Bob'])->get();
    expect($people)->toHaveCount(2);
});

it('deduplicates Create rows with multi-value matchable field', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name', 'Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'First', 'Email' => 'shared@acme.com, extra@acme.com'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Second', 'Email' => 'shared@acme.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->created_rows)->toBe(1)
        ->and($import->updated_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(0);

    $people = People::where('team_id', $this->team->id)->whereIn('name', ['First', 'Second'])->get();
    expect($people)->toHaveCount(1)
        ->and($people->first()->name)->toBe('Second');
});

it('deduplicates company Create rows by domain', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name', 'Domain'], [
        ImportExecutionFixture::row(2, ['Name' => 'Acme Inc', 'Domain' => 'acme.com'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Acme Corp', 'Domain' => 'acme.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Domain', target: 'custom_fields_domains'),
    ], ImportEntityType::Company);

    ImportExecutionFixture::run($this);

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
    $cf = ImportExecutionFixture::customField($this, 'merge_emails', 'email');

    $person = People::factory()->create([
        'name' => 'Merge Test',
        'team_id' => $this->team->id,
    ]);

    CustomFieldValue::factory()->withJsonValue(['old@work.com'])->create([
        'custom_field_id' => $cf->id,
        'entity_type' => 'people',
        'entity_id' => $person->id,
        'tenant_id' => $this->team->id,
    ]);

    ImportExecutionFixture::readyStore($this, ['ID', 'Name', 'Email'], [
        ImportExecutionFixture::row(2, ['ID' => (string) $person->id, 'Name' => 'Merge Test', 'Email' => 'new@work.com'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->updated_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(0);

    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and(collect($cfv->json_value)->all())->toBe(['old@work.com', 'new@work.com']);
});

it('merges multi-choice custom field values during dedup', function (): void {
    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    if ($emailField === null) {
        $this->markTestSkipped('No emails custom field configured');
    }

    ImportExecutionFixture::readyStore($this, ['Name', 'Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'Dedup A', 'Email' => 'a@test.com'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Dedup B', 'Email' => 'a@test.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->created_rows)->toBe(1)
        ->and($import->updated_rows)->toBe(1);

    $person = People::where('team_id', $this->team->id)->where('name', 'Dedup B')->first();
    expect($person)->not->toBeNull();

    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $emailField->id);
    expect($cfv)->not->toBeNull()
        ->and(collect($cfv->json_value)->all())->toBe(['a@test.com']);
});

it('does not duplicate existing multi-choice values during merge', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'dedup_emails', 'email');

    $person = People::factory()->create([
        'name' => 'Dedup Merge',
        'team_id' => $this->team->id,
    ]);

    CustomFieldValue::factory()->withJsonValue(['shared@work.com'])->create([
        'custom_field_id' => $cf->id,
        'entity_type' => 'people',
        'entity_id' => $person->id,
        'tenant_id' => $this->team->id,
    ]);

    ImportExecutionFixture::readyStore($this, ['ID', 'Name', 'Email'], [
        ImportExecutionFixture::row(2, ['ID' => (string) $person->id, 'Name' => 'Dedup Merge', 'Email' => 'shared@work.com, new@work.com'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->updated_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(0);

    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and(collect($cfv->json_value)->all())->toBe(['shared@work.com', 'new@work.com']);
});

it('populates matching custom field when auto-creating person via email MatchOrCreate', function (): void {
    $relationships = json_encode([
        ['relationship' => 'contact', 'action' => 'create', 'id' => null, 'name' => 'john@example.com', 'behavior' => MatchBehavior::MatchOrCreate->value, 'matchField' => 'custom_fields_emails'],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Contact'], [
        ImportExecutionFixture::row(2, ['Name' => 'Test Opportunity', 'Contact' => 'john@example.com'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Contact', matcherKey: 'custom_fields_emails', entityLinkKey: 'contact'),
    ], ImportEntityType::Opportunity);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'john@example.com')->first();
    expect($person)->not->toBeNull();

    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    expect($emailField)->not->toBeNull();

    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $emailField->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->json_value)->toBeInstanceOf(Collection::class)
        ->and($cfv->json_value->all())->toBe(['john@example.com']);
});

it('populates matching custom field when auto-creating company via domain MatchOrCreate', function (): void {
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'example.com', 'behavior' => MatchBehavior::MatchOrCreate->value, 'matchField' => 'custom_fields_domains'],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Company'], [
        ImportExecutionFixture::row(2, ['Name' => 'John Doe', 'Company' => 'example.com'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'custom_fields_domains', entityLinkKey: 'company'),
    ]);

    ImportExecutionFixture::run($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'example.com')->first();
    expect($company)->not->toBeNull();

    $domainField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->first();

    expect($domainField)->not->toBeNull();

    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $company->id, (string) $domainField->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->json_value)->toBeInstanceOf(Collection::class)
        ->and($cfv->json_value->all())->toBe(['example.com']);
});

it('does not populate custom field when auto-creating via name matcher', function (): void {
    $relationships = json_encode([
        ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => 'New Corp', 'behavior' => MatchBehavior::Create->value, 'matchField' => 'name'],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Company'], [
        ImportExecutionFixture::row(2, ['Name' => 'John Doe', 'Company' => 'New Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    $cfCountBefore = DB::table(config('custom-fields.database.table_names.custom_field_values'))->count();

    ImportExecutionFixture::run($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'New Corp')->first();
    expect($company)->not->toBeNull();

    $cfCountAfter = DB::table(config('custom-fields.database.table_names.custom_field_values'))->count();
    expect($cfCountAfter)->toBe($cfCountBefore);
});

it('deduplicates auto-created records while still populating matching custom field', function (): void {
    $relationships = json_encode([
        ['relationship' => 'contact', 'action' => 'create', 'id' => null, 'name' => 'jane@example.com', 'behavior' => MatchBehavior::MatchOrCreate->value, 'matchField' => 'custom_fields_emails'],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Contact'], [
        ImportExecutionFixture::row(2, ['Name' => 'Opp One', 'Contact' => 'jane@example.com'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
        ImportExecutionFixture::row(3, ['Name' => 'Opp Two', 'Contact' => 'jane@example.com'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Contact', matcherKey: 'custom_fields_emails', entityLinkKey: 'contact'),
    ], ImportEntityType::Opportunity);

    ImportExecutionFixture::run($this);

    $people = People::where('team_id', $this->team->id)->where('name', 'jane@example.com')->get();
    expect($people)->toHaveCount(1);

    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->first();

    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $people->first()->id, (string) $emailField->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->json_value->all())->toBe(['jane@example.com']);
});

it('does not auto-create record for custom field entity link', function (): void {
    $recordCf = CustomField::query()
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

    ImportExecutionFixture::readyStore($this, ['Name', 'Related Company'], [
        ImportExecutionFixture::row(2, ['Name' => 'Test Person', 'Related Company' => 'Nonexistent Corp'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Related Company', matcherKey: 'name', entityLinkKey: "cf_{$recordCf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $companyCountAfter = Company::where('team_id', $this->team->id)->count();
    expect($companyCountAfter)->toBe($companyCountBefore);
});
