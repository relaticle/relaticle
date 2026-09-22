<?php

declare(strict_types=1);

use App\Models\ActivityLog\Activity;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Store\ImportStore;
use Tests\Helpers\ImportExecutionFixture;

mutates(ExecuteImportJob::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

afterEach(function (): void {
    if (isset($this->import)) {
        ImportStore::load($this->import->id)?->destroy();
        $this->import->delete();
    }
});

it('logs a custom field an import update changed, with its old and new value', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'lead_source', 'text');
    $person = People::factory()->create(['name' => 'John', 'workspace_id' => $this->workspace->getKey()]);

    CustomFieldValue::forceCreate([
        'custom_field_id' => $cf->getKey(),
        'entity_type' => 'people',
        'entity_id' => $person->getKey(),
        'tenant_id' => $this->workspace->getKey(),
        'text_value' => 'old value',
    ]);

    Activity::withoutGlobalScopes()->delete();

    ImportExecutionFixture::readyStore($this, ['ID', 'Source'], [
        ImportExecutionFixture::row(2, ['ID' => (string) $person->getKey(), 'Source' => 'new value'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->getKey(),
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Source', target: "custom_fields_{$cf->code}"),
    ]);

    $this->actingAs(User::factory()->create());

    ImportExecutionFixture::run($this);

    $activity = Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->firstOrFail();
    $change = $activity->properties['custom_field_changes'][0];

    expect($change['code'])->toBe('lead_source')
        ->and($change['old']['label'])->toBe('old value')
        ->and($change['new']['label'])->toBe('new value')
        ->and($activity->subject_id)->toBe($person->getKey())
        ->and($activity->causer_id)->toBe($this->user->getKey())
        ->and($activity->workspace_id)->toBe($this->workspace->getKey());
});

it('does not log an import update that leaves a custom field as it was', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'lead_source', 'text');
    $person = People::factory()->create(['name' => 'John', 'workspace_id' => $this->workspace->getKey()]);

    CustomFieldValue::forceCreate([
        'custom_field_id' => $cf->getKey(),
        'entity_type' => 'people',
        'entity_id' => $person->getKey(),
        'tenant_id' => $this->workspace->getKey(),
        'text_value' => 'same value',
    ]);

    Activity::withoutGlobalScopes()->delete();

    ImportExecutionFixture::readyStore($this, ['ID', 'Source'], [
        ImportExecutionFixture::row(2, ['ID' => (string) $person->getKey(), 'Source' => 'same value'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->getKey(),
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Source', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    expect(Activity::query()->where('event', 'custom_field_changes')->count())->toBe(0);
});

it('does not log custom field values on import creates', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'lead_source', 'text');
    Activity::withoutGlobalScopes()->delete();

    ImportExecutionFixture::readyStore($this, ['Name', 'Source'], [
        ImportExecutionFixture::row(2, ['Name' => 'Jane', 'Source' => 'referral'], [
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Source', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    expect(Activity::query()->where('event', 'custom_field_changes')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'created')->count())->toBe(1);
});

it('logs an import update on a record that already holds several custom field values', function (): void {
    $source = ImportExecutionFixture::customField($this, 'lead_source', 'text');
    $region = ImportExecutionFixture::customField($this, 'region', 'text');
    $person = People::factory()->create(['name' => 'John', 'workspace_id' => $this->workspace->getKey()]);

    foreach ([[$source, 'old source'], [$region, 'EMEA']] as [$field, $text]) {
        CustomFieldValue::forceCreate([
            'custom_field_id' => $field->getKey(),
            'entity_type' => 'people',
            'entity_id' => $person->getKey(),
            'tenant_id' => $this->workspace->getKey(),
            'text_value' => $text,
        ]);
    }

    Activity::withoutGlobalScopes()->delete();

    ImportExecutionFixture::readyStore($this, ['ID', 'Source'], [
        ImportExecutionFixture::row(2, ['ID' => (string) $person->getKey(), 'Source' => 'new source'], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->getKey(),
        ]),
    ], [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Source', target: "custom_fields_{$source->code}"),
    ]);

    ImportExecutionFixture::run($this);

    expect($this->import->fresh()->failed_rows)->toBe(0)
        ->and($this->import->fresh()->updated_rows)->toBe(1)
        ->and(Activity::query()->where('event', 'custom_field_changes')->count())->toBe(1);
});

it('logs nothing extra when a later row of the same import lands on a record it just created', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'lead_source', 'text');
    Activity::withoutGlobalScopes()->delete();

    ImportExecutionFixture::readyStore($this, ['Name', 'Email', 'Source'], [
        ImportExecutionFixture::row(2, ['Name' => 'Jane', 'Email' => 'jane@acme.test', 'Source' => 'referral'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Jane', 'Email' => 'jane@acme.test', 'Source' => 'referral'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
        ColumnData::toField(source: 'Source', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    expect(Activity::query()->where('event', 'custom_field_changes')->count())->toBe(0);
});
