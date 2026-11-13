<?php

declare(strict_types=1);

use App\Events\WorkspaceCreated;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldLink;
use App\Models\CustomFieldValue;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Enums\WriteSource;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Importers\BaseImporter;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Jobs\ValidateColumnJob;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\EntityLinkValidator;
use Tests\Helpers\ImportExecutionFixture;
use Tests\Helpers\RecordFieldFixture;

mutates(ExecuteImportJob::class, BaseImporter::class, ValidateColumnJob::class, EntityLinkValidator::class);

beforeEach(function (): void {
    Event::fake()->except([TeamCreated::class, WorkspaceCreated::class]);

    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;

    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());

    $this->field = RecordFieldFixture::paired($this->workspace, 'people', 'company', 'employer', 'staff', RelationshipCardinality::ManyToOne)[0];
    $this->company = Company::factory()->for($this->workspace)->create(['name' => 'Acme Robotics']);
});

afterEach(function (): void {
    if (isset($this->import)) {
        ImportStore::delete($this->import->id);
        $this->import->delete();
    }
});

it('links a record through a relationship column and stamps the import as its source', function (): void {
    $relationships = json_encode([
        ['relationship' => 'custom_fields_employer', 'action' => 'update', 'id' => (string) $this->company->id, 'name' => null],
    ]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Employer'], [
        ImportExecutionFixture::row(2, ['Name' => 'Alice Doe', 'Employer' => 'Acme Robotics'], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Employer', matcherKey: 'name', entityLinkKey: 'custom_fields_employer'),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $this->workspace->id)->where('name', 'Alice Doe')->firstOrFail();
    $link = CustomFieldLink::query()->sole();

    expect($link->from_entity_id)->toBe($person->getKey())
        ->and($link->to_entity_id)->toBe($this->company->getKey())
        ->and($link->source)->toBe(WriteSource::Import)
        ->and($link->created_by_id)->toBe($this->user->getKey())
        ->and($person->getCustomFieldValue($this->field))->toBe([(string) $this->company->getKey()]);
});

function importPartnersByDomain(object $test, string $cell): Opportunity
{
    $column = ColumnData::toEntityLink(source: 'Partner companies', matcherKey: 'custom_fields_domains', entityLinkKey: 'custom_fields_partner_companies');

    ImportExecutionFixture::readyStore($test, ['Name', 'Partner companies'], [
        ImportExecutionFixture::row(2, ['Name' => 'Loop Domain Deal', 'Partner companies' => $cell], [
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        $column,
    ], ImportEntityType::Opportunity);

    (new ValidateColumnJob($test->import->id, $column))->handle();
    ImportExecutionFixture::run($test);

    return Opportunity::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $test->workspace->id)->where('name', 'Loop Domain Deal')->sole();
}

function companyWithDomain(object $test, string $name, string $domain): Company
{
    $company = Company::factory()->for($test->workspace)->create(['name' => $name]);
    $domains = CustomField::query()->withoutGlobalScopes()->where('tenant_id', $test->workspace->id)->where('entity_type', 'company')->where('code', 'domains')->sole();

    CustomFieldValue::forceCreate(['custom_field_id' => $domains->id, 'entity_type' => 'company', 'entity_id' => $company->id, 'tenant_id' => $test->workspace->id, 'json_value' => [$domain]]);

    return $company;
}

it('links every company a domain cell names to a many-to-many record field, in cell order', function (): void {
    $partners = RecordFieldFixture::record($this->workspace, 'opportunity', 'company', 'partner_companies', name: 'Partner companies');
    $apple = companyWithDomain($this, 'Apple', 'apple.com');
    $airbnb = companyWithDomain($this, 'Airbnb', 'airbnb.com');

    $opportunity = importPartnersByDomain($this, 'airbnb.com, apple.com');

    $links = CustomFieldLink::query()->where('from_entity_id', $opportunity->getKey())->orderBy('sort_order')->get();

    expect($links->pluck('to_entity_id')->all())->toBe([$airbnb->getKey(), $apple->getKey()])
        ->and($links->pluck('source')->unique()->all())->toBe([WriteSource::Import])
        ->and($opportunity->getCustomFieldValue($partners))->toBe([(string) $airbnb->getKey(), (string) $apple->getKey()])
        ->and(ImportExecutionFixture::freshStore($this)->query()->sole()->hasValidationError('Partner companies'))->toBeFalse();
});

it('links the company a single domain cell names to a many-to-many record field', function (): void {
    $partners = RecordFieldFixture::record($this->workspace, 'opportunity', 'company', 'partner_companies', name: 'Partner companies');
    $apple = companyWithDomain($this, 'Apple', 'apple.com');

    $opportunity = importPartnersByDomain($this, 'apple.com');

    $link = CustomFieldLink::query()->where('from_entity_id', $opportunity->getKey())->sole();

    expect($link->to_entity_id)->toBe($apple->getKey())
        ->and($link->source)->toBe(WriteSource::Import)
        ->and($link->created_by_id)->toBe($this->user->getKey())
        ->and($opportunity->getCustomFieldValue($partners))->toBe([(string) $apple->getKey()]);
});
