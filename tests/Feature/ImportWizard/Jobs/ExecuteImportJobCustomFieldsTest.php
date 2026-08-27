<?php

declare(strict_types=1);

use App\Models\CustomFieldValue;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Enums\NumberFormat;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Tests\Helpers\ImportExecutionFixture;

mutates(ExecuteImportJob::class);

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

// --- Custom Field Import Tests ---

it('imports text custom field value', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'website_notes', 'text');

    ImportExecutionFixture::readyStore($this, ['Name', 'Notes'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Notes' => 'Some important text'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Notes', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull();

    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBe('Some important text');
});

it('imports number custom field as integer', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'employee_count', 'number');

    ImportExecutionFixture::readyStore($this, ['Name', 'Employees'], [
        ImportExecutionFixture::row(2, ['Name' => 'Acme', 'Employees' => '42'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Employees', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'Acme')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->integer_value)->toBe(42);
});

it('imports currency custom field with point decimal format', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'revenue', 'currency');

    ImportExecutionFixture::readyStore($this, ['Name', 'Revenue'], [
        ImportExecutionFixture::row(2, ['Name' => 'Acme', 'Revenue' => '1234.56'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Revenue', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'Acme')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->float_value)->toBe(1234.56);
});

it('imports currency custom field with comma decimal format', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'revenue_eu', 'currency');

    ImportExecutionFixture::readyStore($this, ['Name', 'Revenue'], [
        ImportExecutionFixture::row(2, ['Name' => 'Acme', 'Revenue' => '1.234,56'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        (new ColumnData(
            source: 'Revenue',
            target: "custom_fields_{$cf->code}",
            numberFormat: NumberFormat::COMMA,
        )),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'Acme')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->float_value)->toBe(1234.56);
});

it('imports date custom field with ISO format', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'start_date', 'date');

    ImportExecutionFixture::readyStore($this, ['Name', 'Start'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Start' => '2024-05-15'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Start', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->date_value->format('Y-m-d'))->toBe('2024-05-15');
});

it('imports date custom field with European format', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'start_date_eu', 'date');

    ImportExecutionFixture::readyStore($this, ['Name', 'Start'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Start' => '15/05/2024'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        (new ColumnData(
            source: 'Start',
            target: "custom_fields_{$cf->code}",
            dateFormat: DateFormat::EUROPEAN,
        )),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->date_value->format('Y-m-d'))->toBe('2024-05-15');
});

it('imports date custom field with American format', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'start_date_us', 'date');

    ImportExecutionFixture::readyStore($this, ['Name', 'Start'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Start' => '05/15/2024'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        (new ColumnData(
            source: 'Start',
            target: "custom_fields_{$cf->code}",
            dateFormat: DateFormat::AMERICAN,
        )),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->date_value->format('Y-m-d'))->toBe('2024-05-15');
});

it('imports datetime custom field with ISO format including time', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'meeting_at', 'date-time');

    ImportExecutionFixture::readyStore($this, ['Name', 'Meeting'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Meeting' => '2024-05-15 14:30:00'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Meeting', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->datetime_value->format('Y-m-d H:i:s'))->toBe('2024-05-15 14:30:00');
});

it('imports datetime custom field with European format including time', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'meeting_at_eu', 'date-time');

    ImportExecutionFixture::readyStore($this, ['Name', 'Meeting'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Meeting' => '15/05/2024 14:30'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        (new ColumnData(
            source: 'Meeting',
            target: "custom_fields_{$cf->code}",
            dateFormat: DateFormat::EUROPEAN,
        )),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->datetime_value->format('Y-m-d H:i'))->toBe('2024-05-15 14:30');
});

it('imports boolean custom field with truthy values', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'is_vip', 'checkbox');

    ImportExecutionFixture::readyStore($this, ['Name', 'VIP'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'VIP' => '1'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'VIP', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->boolean_value)->toBeTrue();
});

it('imports select custom field with option name resolved to ID', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'priority', 'select', 'people', ['Low', 'Medium', 'High']);
    $mediumOption = $cf->options->firstWhere('name', 'Medium');

    ImportExecutionFixture::readyStore($this, ['Name', 'Priority'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Priority' => 'Medium'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Priority', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->string_value)->toBe((string) $mediumOption->id);
});

it('imports multi-select custom field with option names resolved to IDs', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'tags_field', 'multi-select', 'people', ['Urgent', 'Follow-up', 'VIP']);
    $urgentOption = $cf->options->firstWhere('name', 'Urgent');
    $vipOption = $cf->options->firstWhere('name', 'VIP');

    ImportExecutionFixture::readyStore($this, ['Name', 'Tags'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Tags' => 'Urgent, VIP'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Tags', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = $cfv->json_value;
    expect($jsonValue)->toContain((string) $urgentOption->id)
        ->toContain((string) $vipOption->id);
});

it('imports tags-input custom field with comma-separated values', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'labels', 'tags-input');

    ImportExecutionFixture::readyStore($this, ['Name', 'Labels'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Labels' => 'tag1, tag2, tag3'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Labels', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = $cfv->json_value;
    expect($jsonValue)->toContain('tag1')
        ->toContain('tag2')
        ->toContain('tag3');
});

it('persists a blank mapped custom field value on create as carried, not skipped', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'optional_notes', 'text');

    ImportExecutionFixture::readyStore($this, ['Name', 'Notes'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Notes' => ''], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Notes', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull();

    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBeEmpty();
});

it('updates existing custom field value on record update', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'note_field', 'text');

    $person = People::factory()->create([
        'name' => 'John',
        'team_id' => $this->team->id,
    ]);

    CustomFieldValue::forceCreate([
        'custom_field_id' => $cf->id,
        'entity_type' => 'people',
        'entity_id' => $person->id,
        'tenant_id' => $this->team->id,
        'text_value' => 'old value',
    ]);

    ImportExecutionFixture::readyStore($this, ['ID', 'Name', 'Notes'], [
        ImportExecutionFixture::row(2, ['ID' => (string) $person->id, 'Name' => 'John', 'Notes' => 'new value'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Notes', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBe('new value');
});

it('clears existing custom field value when mapped column is blank on update', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'note_field_blank', 'text');

    $person = People::factory()->create([
        'name' => 'John',
        'team_id' => $this->team->id,
    ]);

    CustomFieldValue::forceCreate([
        'custom_field_id' => $cf->id,
        'entity_type' => 'people',
        'entity_id' => $person->id,
        'tenant_id' => $this->team->id,
        'text_value' => 'old value',
    ]);

    ImportExecutionFixture::readyStore($this, ['ID', 'Name', 'Notes'], [
        ImportExecutionFixture::row(2, ['ID' => (string) $person->id, 'Name' => 'John', 'Notes' => ''], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Notes', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBeEmpty();
});

it('stores a blank mapped date custom field as null instead of failing the import', function (): void {
    $date = ImportExecutionFixture::customField($this, 'blank_start_date', 'date');
    $dateTime = ImportExecutionFixture::customField($this, 'blank_start_datetime', 'date-time');

    ImportExecutionFixture::readyStore($this, ['Name', 'Start', 'StartAt'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Start' => '', 'StartAt' => ''], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Start', target: "custom_fields_{$date->code}"),
        ColumnData::toField(source: 'StartAt', target: "custom_fields_{$dateTime->code}"),
    ]);

    ImportExecutionFixture::run($this);

    expect($this->import->fresh()->status)->toBe(ImportStatus::Completed);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull()
        ->and(ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $date->id)->date_value)->toBeNull()
        ->and(ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $dateTime->id)->datetime_value)->toBeNull();
});

it('clears an existing date custom field value when the mapped column is blank on update', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'clearable_start_date', 'date');

    $person = People::factory()->create([
        'name' => 'John',
        'team_id' => $this->team->id,
    ]);

    CustomFieldValue::forceCreate([
        'custom_field_id' => $cf->id,
        'entity_type' => 'people',
        'entity_id' => $person->id,
        'tenant_id' => $this->team->id,
        'date_value' => '2024-05-15',
    ]);

    ImportExecutionFixture::readyStore($this, ['ID', 'Name', 'Start'], [
        ImportExecutionFixture::row(2, ['ID' => (string) $person->id, 'Name' => 'John', 'Start' => ''], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Start', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->date_value)->toBeNull();
});

it('stores a whitespace-only mapped date custom field as null instead of failing the import', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'whitespace_start_date', 'date');

    ImportExecutionFixture::readyStore($this, ['Name', 'Start'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Start' => '   '], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Start', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    expect($this->import->fresh()->status)->toBe(ImportStatus::Completed);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    expect($person)->not->toBeNull()
        ->and(ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id)->date_value)->toBeNull();
});

it('leaves an existing custom field value untouched when the cell was skipped in review', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'skipped_note_field', 'text');

    $person = People::factory()->create([
        'name' => 'John',
        'team_id' => $this->team->id,
    ]);

    CustomFieldValue::forceCreate([
        'custom_field_id' => $cf->id,
        'entity_type' => 'people',
        'entity_id' => $person->id,
        'tenant_id' => $this->team->id,
        'text_value' => 'keep me',
    ]);

    ImportExecutionFixture::readyStore($this, ['ID', 'Name', 'Notes'], [
        ImportExecutionFixture::row(2, ['ID' => (string) $person->id, 'Name' => 'John', 'Notes' => 'discarded'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
            'skipped' => json_encode(['Notes' => true]),
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Notes', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBe('keep me');
});

it('imports email custom field with comma-separated addresses as array', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'contact_emails', 'email');

    ImportExecutionFixture::readyStore($this, ['Name', 'Emails'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Emails' => 'a@b.com, c@d.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Emails', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = collect($cfv->json_value)->all();
    expect($jsonValue)->toBeArray()
        ->toContain('a@b.com')
        ->toContain('c@d.com');
});

it('imports select custom field with case-insensitive option name', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'priority_ci', 'select', 'people', ['Low', 'Medium', 'High']);
    $mediumOption = $cf->options->firstWhere('name', 'Medium');

    ImportExecutionFixture::readyStore($this, ['Name', 'Priority'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Priority' => 'medium'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Priority', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->string_value)->toBe((string) $mediumOption->id);
});

it('imports select custom field with value already being an option ID', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'priority_id', 'select', 'people', ['Low', 'Medium', 'High']);
    $mediumOption = $cf->options->firstWhere('name', 'Medium');

    ImportExecutionFixture::readyStore($this, ['Name', 'Priority'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Priority' => (string) $mediumOption->id], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Priority', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->string_value)->toBe((string) $mediumOption->id);
});

it('imports multi-select custom field with mixed option names resolved to IDs', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'categories', 'multi-select', 'people', ['Alpha', 'Beta', 'Gamma']);
    $alphaOption = $cf->options->firstWhere('name', 'Alpha');
    $gammaOption = $cf->options->firstWhere('name', 'Gamma');

    ImportExecutionFixture::readyStore($this, ['Name', 'Categories'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Categories' => 'Alpha, Gamma'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Categories', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = collect($cfv->json_value)->all();
    expect($jsonValue)->toBeArray()
        ->toContain((string) $alphaOption->id)
        ->toContain((string) $gammaOption->id)
        ->not->toContain('Alpha')
        ->not->toContain('Gamma');
});
