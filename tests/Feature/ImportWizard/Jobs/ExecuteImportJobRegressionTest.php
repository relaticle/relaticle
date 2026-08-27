<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Enums\EntityLinkSource;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Jobs\ResolveMatchesJob;
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

// --- Issue #282 Bug 2: imported tags-input values become options ---

it('adds a new imported tags-input value to the field option list', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'labels282', 'tags-input', 'company', ['Existing']);

    ImportExecutionFixture::readyStore($this, ['Name', 'Labels'], [
        ImportExecutionFixture::row(2, ['Name' => 'Tagged Co 282', 'Labels' => 'Existing, BrandNewTag'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Labels', target: 'custom_fields_labels282'),
    ], ImportEntityType::Company);

    ImportExecutionFixture::run($this);

    $company = Company::where('team_id', $this->team->id)->where('name', 'Tagged Co 282')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $company->id, (string) $cf->id);

    expect(collect($cfv->json_value)->all())->toContain('BrandNewTag');

    $optionNames = $cf->refresh()->options->pluck('name')->all();
    expect($optionNames)->toContain('Existing')
        ->toContain('BrandNewTag');

    $newOption = $cf->refresh()->options()->withoutGlobalScopes()->where('name', 'BrandNewTag')->first();
    expect((int) $newOption->sort_order)->toBeGreaterThan(0);
});

it('does not duplicate an imported tag that already exists as an option (case-insensitive)', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'labels282b', 'tags-input', 'company', ['VIP']);

    ImportExecutionFixture::readyStore($this, ['Name', 'Labels'], [
        ImportExecutionFixture::row(2, ['Name' => 'Dup Co 282', 'Labels' => 'vip'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Labels', target: 'custom_fields_labels282b'),
    ], ImportEntityType::Company);

    ImportExecutionFixture::run($this);

    expect($cf->refresh()->options()->withoutGlobalScopes()->count())->toBe(1);
});

it('does not create options when importing an arbitrary email custom field', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'contact_emails282', 'email', 'company');

    ImportExecutionFixture::readyStore($this, ['Name', 'Emails'], [
        ImportExecutionFixture::row(2, ['Name' => 'Email Co 282', 'Emails' => 'a@b.com, c@d.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Emails', target: 'custom_fields_contact_emails282'),
    ], ImportEntityType::Company);

    ImportExecutionFixture::run($this);

    expect($cf->refresh()->options()->withoutGlobalScopes()->count())->toBe(0);
});

// --- Issue #282 Bug 1: soft-deleted records must not be matched ---

it('does not match a soft-deleted company by domain (resolver)', function (): void {
    $domainField = CustomField::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)->where('entity_type', 'company')->where('code', 'domains')->first();

    expect($domainField)->not->toBeNull();

    $live = Company::factory()->create(['name' => 'Live Co', 'team_id' => $this->team->id]);
    CustomFieldValue::forceCreate(['custom_field_id' => $domainField->id, 'entity_type' => 'company', 'entity_id' => $live->id, 'tenant_id' => $this->team->id, 'json_value' => ['live282.com']]);

    $trashed = Company::factory()->create(['name' => 'Trashed Co', 'team_id' => $this->team->id]);
    CustomFieldValue::forceCreate(['custom_field_id' => $domainField->id, 'entity_type' => 'company', 'entity_id' => $trashed->id, 'tenant_id' => $this->team->id, 'json_value' => ['ghost282.com']]);
    $trashed->delete();

    $resolver = new EntityLinkResolver((string) $this->team->id);
    $link = new EntityLink(key: 'self', source: EntityLinkSource::Relationship, targetEntity: 'company', targetModelClass: Company::class);
    $matcher = MatchableField::domain('custom_fields_domains');

    $resolved = $resolver->batchResolve($link, $matcher, ['live282.com', 'ghost282.com']);

    expect((string) ($resolved['live282.com'] ?? 'null'))->toBe((string) $live->id)
        ->and($resolved['ghost282.com'] ?? null)->toBeNull();
});

it('creates a new company when re-importing a domain whose company was soft-deleted', function (): void {
    $domainField = CustomField::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)->where('entity_type', 'company')->where('code', 'domains')->first();

    expect($domainField)->not->toBeNull();

    $original = Company::factory()->create(['name' => 'Acme Original 282', 'team_id' => $this->team->id]);
    CustomFieldValue::forceCreate(['custom_field_id' => $domainField->id, 'entity_type' => 'company', 'entity_id' => $original->id, 'tenant_id' => $this->team->id, 'json_value' => ['acme282.com']]);
    $original->delete();

    ImportExecutionFixture::readyStore($this, ['Name', 'Domain'], [
        ImportExecutionFixture::row(2, ['Name' => 'Acme Reimport 282', 'Domain' => 'acme282.com']),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Domain', target: 'custom_fields_domains'),
    ], ImportEntityType::Company);

    (new ResolveMatchesJob($this->import->id))->handle();
    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->created_rows)->toBe(1)
        ->and($import->skipped_rows)->toBe(0)
        ->and(Company::where('team_id', $this->team->id)->where('name', 'Acme Reimport 282')->exists())->toBeTrue();
});

/**
 * A naive datetime in a CSV means "that wall clock where the importer is", exactly as
 * it does when the same string is typed into the Filament form, which converts out of
 * the user's zone before storing. Parsed as UTC instead, the two paths disagree by the
 * offset, so the same value imported and hand-entered lands on different instants.
 */
it('parses an imported datetime in the importer timezone, matching what the form would store', function (): void {
    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();

    $cf = ImportExecutionFixture::customField($this, 'meeting_tokyo', 'date-time');

    ImportExecutionFixture::readyStore($this, ['Name', 'Meeting'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Meeting' => '2026-08-19 08:30:00'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Meeting', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);

    // 08:30 on the 19th in Tokyo is 23:30 the previous evening in UTC, the exact value
    // TaskResourceTest asserts the form stores for this same typed string.
    expect($cfv)->not->toBeNull()
        ->and($cfv->datetime_value->format('Y-m-d H:i:s'))->toBe('2026-08-18 23:30:00');
});

/**
 * The other half of the rule: a date-only field has no time of day, so it must never be
 * shifted. Converting it would move the calendar day for every importer west of UTC.
 */
it('does not shift a date-only custom field for an importer in another timezone', function (): void {
    $this->user->forceFill(['timezone' => 'America/Los_Angeles'])->save();

    $cf = ImportExecutionFixture::customField($this, 'start_date_la', 'date');

    ImportExecutionFixture::readyStore($this, ['Name', 'Start'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Start' => '2026-08-19'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Start', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);

    expect($cfv)->not->toBeNull()
        ->and($cfv->date_value->format('Y-m-d'))->toBe('2026-08-19');
});

/**
 * A date column holding something that is not a date used to import "successfully" with
 * the value dropped: the wizard flagged it at review, the user clicked through, and the
 * summary then said 0 failed. Silently discarding data is worse than refusing the row.
 */
it('fails a row whose datetime cannot be parsed instead of dropping the value', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'meeting_bad', 'date-time');

    ImportExecutionFixture::readyStore($this, ['Name', 'Meeting'], [
        ImportExecutionFixture::row(2, ['Name' => 'Valid Row', 'Meeting' => '2026-08-19 08:30:00'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => 'Bad Row', 'Meeting' => 'not-a-date'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Meeting', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();

    expect($import->created_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(1)
        ->and(People::where('team_id', $this->team->id)->where('name', 'Bad Row')->exists())->toBeFalse()
        ->and(People::where('team_id', $this->team->id)->where('name', 'Valid Row')->exists())->toBeTrue();

    // The failed-rows table is what the user downloads, so the message has to name the
    // column and quote the value rather than read like a stack trace.
    expect($import->failedRows()->value('validation_error'))
        ->toContain('Meeting bad')
        ->toContain('not-a-date');
});

/**
 * REG-003. A CSV row with the required name empty was imported as a record whose name is
 * the empty string: invisible in the list, absent from search, and counted as successful.
 */
it('fails a row that leaves a required field empty rather than creating a nameless record', function (): void {
    ImportExecutionFixture::readyStore($this, ['Name', 'Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'Has A Name', 'Email' => 'ok@example.test'], ['match_action' => RowMatchAction::Create->value]),
        ImportExecutionFixture::row(3, ['Name' => '', 'Email' => 'blank@example.test'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'emails'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();

    expect($import->created_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(1)
        ->and(People::where('team_id', $this->team->id)->where('name', '')->exists())->toBeFalse();

    expect($import->failedRows()->value('validation_error'))->toContain('required');
});
