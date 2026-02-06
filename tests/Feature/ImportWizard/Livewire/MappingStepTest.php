<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Livewire\Livewire;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Livewire\Steps\MappingStep;
use Relaticle\ImportWizard\Store\ImportStore;

beforeEach(function (): void {
    Event::fake()->except([TeamCreated::class]);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->personalTeam();

    Filament::setTenant($this->team);

    $this->store = ImportStore::create(
        teamId: (string) $this->team->id,
        userId: (string) $this->user->id,
        entityType: ImportEntityType::People,
        originalFilename: 'test.csv',
    );

    $this->store->setStatus(ImportStatus::Mapping);
});

afterEach(function (): void {
    $this->store->destroy();
});

function createStoreWithHeaders(object $context, array $headers, array $rows = []): void
{
    $context->store->setHeaders($headers);

    if ($rows === []) {
        $rowData = array_combine($headers, array_fill(0, count($headers), 'sample'));
        $rows = [$rowData];
    }

    foreach ($rows as $index => $row) {
        $context->store->query()->insert([
            'row_number' => $index + 2,
            'raw_data' => json_encode($row),
            'validation' => null,
            'corrections' => null,
            'skipped' => null,
            'match_action' => null,
            'matched_id' => null,
            'relationships' => null,
        ]);
    }

    $context->store->setRowCount(count($rows));
}

function mountMappingStep(object $context): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::test(MappingStep::class, [
        'storeId' => $context->store->id(),
        'entityType' => ImportEntityType::People,
    ]);
}

// ─── Mount & Auto-Mapping ───────────────────────────────────────────────────

it('renders with correct headers from store', function (): void {
    createStoreWithHeaders($this, ['Name', 'Phone', 'Notes']);

    $component = mountMappingStep($this);

    $component->assertOk();
});

it('auto-maps Name header to name field on mount', function (): void {
    createStoreWithHeaders($this, ['Name', 'Phone']);

    $component = mountMappingStep($this);

    $columns = $component->get('columns');
    expect($columns)->toHaveKey('Name')
        ->and($columns['Name']['target'])->toBe('name')
        ->and($columns['Name']['entityLink'])->toBeNull();
});

it('auto-maps Company header to company entity link', function (): void {
    createStoreWithHeaders($this, ['Name', 'Company'], [
        ['Name' => 'John', 'Company' => 'Acme Inc'],
    ]);

    $component = mountMappingStep($this);

    $columns = $component->get('columns');
    expect($columns)->toHaveKey('Company')
        ->and($columns['Company']['entityLink'])->toBe('company');
});

// ─── Manual Mapping ─────────────────────────────────────────────────────────

it('mapToField updates column mapping', function (): void {
    createStoreWithHeaders($this, ['Full Name', 'Notes']);

    $component = mountMappingStep($this);
    $component->call('mapToField', 'Full Name', 'name');

    $columns = $component->get('columns');
    expect($columns)->toHaveKey('Full Name')
        ->and($columns['Full Name']['target'])->toBe('name');
});

it('mapToField with empty target removes mapping', function (): void {
    createStoreWithHeaders($this, ['Name', 'Notes']);

    $component = mountMappingStep($this);

    expect($component->get('columns'))->toHaveKey('Name');

    $component->call('mapToField', 'Name', '');

    expect($component->get('columns'))->not->toHaveKey('Name');
});

it('mapToField rejects duplicate target', function (): void {
    createStoreWithHeaders($this, ['Col A', 'Col B']);

    $component = mountMappingStep($this);
    $component->call('mapToField', 'Col A', 'name');
    $component->call('mapToField', 'Col B', 'name');

    $columns = $component->get('columns');
    expect($columns['Col A']['target'])->toBe('name')
        ->and($columns)->not->toHaveKey('Col B');
});

it('mapToEntityLink creates entity link mapping', function (): void {
    createStoreWithHeaders($this, ['Name', 'Org']);

    $component = mountMappingStep($this);
    $component->call('mapToEntityLink', 'Org', 'name', 'company');

    $columns = $component->get('columns');
    expect($columns)->toHaveKey('Org')
        ->and($columns['Org']['entityLink'])->toBe('company')
        ->and($columns['Org']['target'])->toBe('name');
});

it('unmapColumn removes mapping', function (): void {
    createStoreWithHeaders($this, ['Name', 'Notes']);

    $component = mountMappingStep($this);

    expect($component->get('columns'))->toHaveKey('Name');

    $component->call('unmapColumn', 'Name');

    expect($component->get('columns'))->not->toHaveKey('Name');
});

// ─── canProceed ─────────────────────────────────────────────────────────────

it('canProceed returns false when required field is unmapped', function (): void {
    createStoreWithHeaders($this, ['Notes', 'Phone']);

    $component = mountMappingStep($this);

    $component->call('unmapColumn', 'Notes');
    $component->call('unmapColumn', 'Phone');

    $component->call('canProceed')
        ->assertReturned(false);
});

it('canProceed returns true when all required fields are mapped', function (): void {
    createStoreWithHeaders($this, ['Name', 'Notes']);

    $component = mountMappingStep($this);
    $component->call('mapToField', 'Name', 'name');

    $component->call('canProceed')
        ->assertReturned(true);
});

// ─── Continue to Review ─────────────────────────────────────────────────────

it('continueToReview saves mappings to store', function (): void {
    createStoreWithHeaders($this, ['Name', 'Notes']);

    $component = mountMappingStep($this);
    $component->call('mapToField', 'Name', 'name');
    $component->call('continueToReview');

    $freshStore = ImportStore::load($this->store->id(), (string) $this->team->id);
    $savedMappings = $freshStore->columnMappings();
    expect($savedMappings)->toHaveCount(1)
        ->and($savedMappings->first()->source)->toBe('Name')
        ->and($savedMappings->first()->target)->toBe('name');
});

it('continueToReview sets status to Reviewing', function (): void {
    createStoreWithHeaders($this, ['Name']);

    $component = mountMappingStep($this);
    $component->call('mapToField', 'Name', 'name');
    $component->call('continueToReview');

    $freshStore = ImportStore::load($this->store->id(), (string) $this->team->id);
    expect($freshStore->status())->toBe(ImportStatus::Reviewing);
});

it('continueToReview dispatches completed event', function (): void {
    createStoreWithHeaders($this, ['Name']);

    $component = mountMappingStep($this);
    $component->call('mapToField', 'Name', 'name');
    $component->call('continueToReview');

    $component->assertDispatched('completed');
});

it('continueToReview blocked when canProceed is false', function (): void {
    createStoreWithHeaders($this, ['Notes', 'Phone']);

    $component = mountMappingStep($this);
    $component->call('unmapColumn', 'Notes');
    $component->call('unmapColumn', 'Phone');
    $component->call('continueToReview');

    $component->assertNotDispatched('completed');
    expect($this->store->status())->toBe(ImportStatus::Mapping);
});

// ─── Preview Values ─────────────────────────────────────────────────────────

it('previewValues returns sample values from SQLite', function (): void {
    createStoreWithHeaders($this, ['Name', 'Email'], [
        ['Name' => 'John', 'Email' => 'john@test.com'],
        ['Name' => 'Jane', 'Email' => 'jane@test.com'],
    ]);

    $component = mountMappingStep($this);

    $component->call('previewValues', 'Name')
        ->assertReturned(['John', 'Jane']);
});
