<?php

declare(strict_types=1);

use App\Filament\Pages\Workspace\ActivityLog;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Models\User;
use App\Support\ActivityLog\MergedActivityRenderer;
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
    if (property_exists($this, 'import') && $this->import !== null) {
        ImportStore::load($this->import->id)?->destroy();
        $this->import->delete();
    }
});

it('logs a custom field an import update changed, with its old and new value', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'lead_source', 'text');
    $person = People::factory()->create(['name' => 'John', 'workspace_id' => $this->workspace->getKey()]);

    CustomFieldValue::query()->forceCreate([
        'custom_field_id' => $cf->getKey(),
        'entity_type' => 'people',
        'entity_id' => $person->getKey(),
        'tenant_id' => $this->workspace->getKey(),
        'text_value' => 'old value',
    ]);

    Activity::query()->withoutGlobalScopes()->delete();

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

    $activity = Activity::query()->withoutGlobalScopes()->where('event', 'custom_field_changes')->firstOrFail();
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

    CustomFieldValue::query()->forceCreate([
        'custom_field_id' => $cf->getKey(),
        'entity_type' => 'people',
        'entity_id' => $person->getKey(),
        'tenant_id' => $this->workspace->getKey(),
        'text_value' => 'same value',
    ]);

    Activity::query()->withoutGlobalScopes()->delete();

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
    Activity::query()->withoutGlobalScopes()->delete();

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
        CustomFieldValue::query()->forceCreate([
            'custom_field_id' => $field->getKey(),
            'entity_type' => 'people',
            'entity_id' => $person->getKey(),
            'tenant_id' => $this->workspace->getKey(),
            'text_value' => $text,
        ]);
    }

    Activity::query()->withoutGlobalScopes()->delete();

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
    Activity::query()->withoutGlobalScopes()->delete();

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

function runThreePersonImport(object $context): void
{
    Activity::query()->withoutGlobalScopes()->delete();

    ImportExecutionFixture::readyStore($context, ['Name'], [
        ImportExecutionFixture::row(2, ['Name' => 'Ada'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Grace'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(4, ['Name' => 'Linus'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($context);
}

it('stamps every record row of one import with the import and its file', function (): void {
    runThreePersonImport($this);

    $rows = Activity::query()->withoutGlobalScopes()->where('event', 'created')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('properties.import_id')->unique()->all())->toBe([$this->import->id])
        ->and($rows->pluck('properties.import_file')->unique()->all())->toBe(['test.csv']);
});

it('writes one summary row for the import, caused by the importer', function (): void {
    runThreePersonImport($this);

    $summary = Activity::query()->withoutGlobalScopes()->where('event', 'imported')->sole();

    expect($summary->subject_type)->toBe('import')
        ->and($summary->subject_id)->toBe($this->import->id)
        ->and($summary->causer_id)->toBe($this->user->getKey())
        ->and($summary->workspace_id)->toBe($this->workspace->getKey())
        ->and($summary->properties['created'])->toBe(3)
        ->and($summary->properties->has('import_id'))->toBeFalse();
});

it('shows the import as one entry on the workspace activity page', function (): void {
    runThreePersonImport($this);

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertCountTableRecords(1)
        ->assertSee('test.csv')
        ->assertSee(__('workspaces.activity.events.imported'));
});

it('keeps each imported record its own created entry, marked with the import', function (): void {
    runThreePersonImport($this);

    $person = People::query()->where('workspace_id', $this->workspace->getKey())->where('name', 'Ada')->sole();
    $html = (new MergedActivityRenderer)->render($person->timeline()->get()->first())->render();

    expect($html)->toContain(__('workspaces.activity.via_import', ['file' => 'test.csv']));
});

it('stops stamping once the import job is over', function (): void {
    runThreePersonImport($this);

    $company = Company::factory()->for($this->workspace)->create(['name' => 'After Import Co']);

    $row = Activity::query()->withoutGlobalScopes()->where('subject_type', 'company')->where('subject_id', $company->getKey())->sole();

    expect($row->properties->has('import_id'))->toBeFalse();
});
