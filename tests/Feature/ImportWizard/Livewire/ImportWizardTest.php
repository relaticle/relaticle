<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Livewire\Livewire;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Livewire\ImportWizard;
use Relaticle\ImportWizard\Store\ImportStore;

mutates(ImportWizard::class);

beforeEach(function (): void {
    Event::fake()->except([TeamCreated::class]);
    Bus::fake();

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->personalTeam();

    Filament::setTenant($this->team);

    $this->createdStoreIds = [];
});

afterEach(function (): void {
    foreach ($this->createdStoreIds as $storeId) {
        ImportStore::load($storeId, (string) $this->team->id)?->destroy();
    }
});

function mountImportWizard(object $context, ?string $returnUrl = null): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(ImportWizard::class, [
        'entityType' => ImportEntityType::People,
        'returnUrl' => $returnUrl,
    ]);
}

function createFullTestStore(object $context): ImportStore
{
    $store = ImportStore::create(
        teamId: (string) $context->team->id,
        userId: (string) $context->user->id,
        entityType: ImportEntityType::People,
        originalFilename: 'test.csv',
    );

    $store->setHeaders(['Name', 'Email']);
    $store->setColumnMappings([
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);
    $store->query()->insert([
        'row_number' => 2,
        'raw_data' => json_encode(['Name' => 'John', 'Email' => 'john@test.com']),
    ]);
    $store->setRowCount(1);
    $store->setStatus(ImportStatus::Reviewing);

    $context->createdStoreIds[] = $store->id();

    return $store;
}

function markStoreAsDestroyed(object $context, ImportStore $store): void
{
    $context->createdStoreIds = array_filter(
        $context->createdStoreIds,
        fn (string $id): bool => $id !== $store->id(),
    );
}

it('mounts at step 1 (Upload)', function (): void {
    $component = mountImportWizard($this);

    $component->assertOk();
    expect($component->get('currentStep'))->toBe(1);
});

it('onUploadCompleted advances to step 2 with store data', function (): void {
    $store = createFullTestStore($this);

    $component = mountImportWizard($this);
    $component->call('onUploadCompleted', $store->id(), 5, 3);

    expect($component->get('currentStep'))->toBe(2)
        ->and($component->get('storeId'))->toBe($store->id())
        ->and($component->get('rowCount'))->toBe(5)
        ->and($component->get('columnCount'))->toBe(3);
});

it('goBack caps at step 1', function (): void {
    $component = mountImportWizard($this);

    $component->call('goBack');

    expect($component->get('currentStep'))->toBe(1);
});

it('goToStep ignores forward navigation', function (): void {
    $store = createFullTestStore($this);

    $component = mountImportWizard($this);
    $component->set('storeId', $store->id());
    $component->set('currentStep', 2);

    $component->call('goToStep', 3);

    expect($component->get('currentStep'))->toBe(2);
});

it('goToStep navigates to completed step', function (): void {
    $store = createFullTestStore($this);

    $component = mountImportWizard($this);
    $component->set('storeId', $store->id());
    $component->set('currentStep', 3);

    $component->call('goToStep', 2);

    expect($component->get('currentStep'))->toBe(2);
});

it('cancelImport destroys store and redirects', function (): void {
    $store = createFullTestStore($this);
    $returnUrl = '/dashboard';

    $component = mountImportWizard($this, $returnUrl);
    $component->set('storeId', $store->id());
    $component->call('cancelImport');

    $component->assertRedirect($returnUrl);

    expect(ImportStore::load($store->id(), (string) $this->team->id))->toBeNull();

    markStoreAsDestroyed($this, $store);
});

it('startOver resets to step 1', function (): void {
    $store = createFullTestStore($this);

    $component = mountImportWizard($this);
    $component->set('storeId', $store->id());
    $component->set('currentStep', 2);
    $component->set('rowCount', 10);
    $component->set('columnCount', 5);

    $component->call('startOver');

    expect($component->get('currentStep'))->toBe(1)
        ->and($component->get('storeId'))->toBeNull()
        ->and($component->get('rowCount'))->toBe(0)
        ->and($component->get('columnCount'))->toBe(0);

    expect(ImportStore::load($store->id(), (string) $this->team->id))->toBeNull();

    markStoreAsDestroyed($this, $store);
});

it('getStepTitle returns correct title for Upload step', function (): void {
    $component = mountImportWizard($this);

    $component->call('getStepTitle')->assertReturned('Upload CSV');
});

it('getStepTitle returns correct title for Map step', function (): void {
    $store = createFullTestStore($this);

    $component = mountImportWizard($this);
    $component->call('onUploadCompleted', $store->id(), 1, 2);

    $component->call('getStepTitle')->assertReturned('Map Columns');
});

it('getStepDescription returns correct description per step', function (): void {
    $component = mountImportWizard($this);

    $component->call('getStepDescription')
        ->assertReturned('Upload your CSV file to import People');
});

it('restores to mapping step when store status is Mapping', function (): void {
    $store = createFullTestStore($this);
    $store->setStatus(ImportStatus::Mapping);

    $component = Livewire::withQueryParams(['import' => $store->id()])
        ->test(ImportWizard::class, [
            'entityType' => ImportEntityType::People,
        ]);

    expect($component->get('currentStep'))->toBe(2)
        ->and($component->get('storeId'))->toBe($store->id())
        ->and($component->get('rowCount'))->toBe(1)
        ->and($component->get('columnCount'))->toBe(2);
});

it('restores to review step when store status is Reviewing', function (): void {
    $store = createFullTestStore($this);
    $store->setStatus(ImportStatus::Reviewing);

    $component = Livewire::withQueryParams(['import' => $store->id()])
        ->test(ImportWizard::class, [
            'entityType' => ImportEntityType::People,
        ]);

    expect($component->get('currentStep'))->toBe(3)
        ->and($component->get('storeId'))->toBe($store->id());
});

it('restores to preview step with locked navigation when store status is Completed', function (): void {
    $store = createFullTestStore($this);
    $store->setStatus(ImportStatus::Completed);

    $component = Livewire::withQueryParams(['import' => $store->id()])
        ->test(ImportWizard::class, [
            'entityType' => ImportEntityType::People,
        ]);

    expect($component->get('currentStep'))->toBe(4)
        ->and($component->get('storeId'))->toBe($store->id())
        ->and($component->get('importStarted'))->toBeTrue();
});

it('blocks navigation when import has started', function (): void {
    $store = createFullTestStore($this);
    $store->setStatus(ImportStatus::Importing);

    $component = Livewire::withQueryParams(['import' => $store->id()])
        ->test(ImportWizard::class, [
            'entityType' => ImportEntityType::People,
        ]);

    expect($component->get('importStarted'))->toBeTrue();

    $component->call('goToStep', 2);
    expect($component->get('currentStep'))->toBe(4);

    $component->call('goBack');
    expect($component->get('currentStep'))->toBe(4);
});

it('resets storeId when store not found', function (): void {
    $component = Livewire::withQueryParams(['import' => 'nonexistent-id'])
        ->test(ImportWizard::class, [
            'entityType' => ImportEntityType::People,
        ]);

    expect($component->get('currentStep'))->toBe(1)
        ->and($component->get('storeId'))->toBeNull();
});

it('resets storeId when store belongs to different team', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherTeam = $otherUser->personalTeam();

    $store = ImportStore::create(
        teamId: (string) $otherTeam->id,
        userId: (string) $otherUser->id,
        entityType: ImportEntityType::People,
        originalFilename: 'test.csv',
    );
    $store->setHeaders(['Name', 'Email']);
    $store->setRowCount(1);
    $store->setStatus(ImportStatus::Mapping);

    $component = Livewire::withQueryParams(['import' => $store->id()])
        ->test(ImportWizard::class, [
            'entityType' => ImportEntityType::People,
        ]);

    expect($component->get('currentStep'))->toBe(1)
        ->and($component->get('storeId'))->toBeNull();

    $store->destroy();
});
