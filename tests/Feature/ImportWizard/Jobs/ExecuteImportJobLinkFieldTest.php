<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomFieldLink;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Importers\BaseImporter;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Store\ImportStore;
use Tests\Helpers\ImportExecutionFixture;
use Tests\Helpers\RecordFieldFixture;

mutates(ExecuteImportJob::class, BaseImporter::class);

beforeEach(function (): void {
    Event::fake()->except([TeamCreated::class]);

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

    $person = People::query()->where('workspace_id', $this->workspace->id)->where('name', 'Alice Doe')->firstOrFail();
    $link = CustomFieldLink::query()->sole();

    expect($link->from_entity_id)->toBe($person->getKey())
        ->and($link->to_entity_id)->toBe($this->company->getKey())
        ->and($link->source)->toBe('import')
        ->and($link->created_by_id)->toBe($this->user->getKey())
        ->and($person->getCustomFieldValue($this->field))->toBe([(string) $this->company->getKey()]);
});
