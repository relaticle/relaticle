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
        'validation' => null,
        'corrections' => null,
        'skipped' => null,
        'match_action' => null,
        'matched_id' => null,
        'relationships' => null,
    ]);
    $store->setRowCount(1);
    $store->setStatus(ImportStatus::Reviewing);

    $context->createdStoreIds[] = $store->id();

    return $store;
}

// ─── Mount ──────────────────────────────────────────────────────────────────

it('mounts at step 1 (Upload)', function (): void {
    $component = mountImportWizard($this);

    $component->assertOk();
    expect($component->get('currentStep'))->toBe(1);
});

// ─── Upload Completed Event ─────────────────────────────────────────────────

it('onUploadCompleted advances to step 2 with store data', function (): void {
    $store = createFullTestStore($this);

    $component = mountImportWizard($this);
    $component->call('onUploadCompleted', $store->id(), 5, 3);

    expect($component->get('currentStep'))->toBe(2)
        ->and($component->get('storeId'))->toBe($store->id())
        ->and($component->get('rowCount'))->toBe(5)
        ->and($component->get('columnCount'))->toBe(3);
});

// ─── Step Navigation (step 1 stays on step 1, no child rendering issues) ──

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

// ─── Cancel & Start Over ───────────────────────────────────────────────────

it('cancelImport destroys store and redirects', function (): void {
    $store = createFullTestStore($this);
    $returnUrl = '/dashboard';

    $component = mountImportWizard($this, $returnUrl);
    $component->set('storeId', $store->id());
    $component->call('cancelImport');

    $component->assertRedirect($returnUrl);

    $reloadedStore = ImportStore::load($store->id(), (string) $this->team->id);
    expect($reloadedStore)->toBeNull();

    $this->createdStoreIds = array_filter(
        $this->createdStoreIds,
        fn ($id) => $id !== $store->id()
    );
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

    $reloadedStore = ImportStore::load($store->id(), (string) $this->team->id);
    expect($reloadedStore)->toBeNull();

    $this->createdStoreIds = array_filter(
        $this->createdStoreIds,
        fn ($id) => $id !== $store->id()
    );
});

// ─── Step Titles ────────────────────────────────────────────────────────────

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
