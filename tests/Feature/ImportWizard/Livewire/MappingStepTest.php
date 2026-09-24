<?php

declare(strict_types=1);

use App\Events\WorkspaceCreated;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Data\ImportFieldCollection;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Livewire\Steps\MappingStep;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\ColumnMatcher;
use Tests\Helpers\ClassificationFake;

mutates(MappingStep::class, ColumnData::class, ImportField::class, ImportFieldCollection::class, ColumnMatcher::class);

beforeEach(function (): void {
    Event::fake()->except([WorkspaceCreated::class]);

    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;

    Filament::setTenant($this->workspace);

    $this->import = Import::factory()->create([
        'workspace_id' => (string) $this->workspace->id,
        'user_id' => (string) $this->user->id,
        'entity_type' => ImportEntityType::People,
        'file_name' => 'test.csv',
        'status' => ImportStatus::Mapping,
        'total_rows' => 0,
        'headers' => [],
    ]);

    $this->store = ImportStore::create($this->import->id);
});

afterEach(function (): void {
    $this->store->destroy();
    $this->import->delete();
});

function createStoreWithHeaders(object $context, array $headers, array $rows = []): void
{
    $context->import->update(['headers' => $headers]);

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

    $context->import->update(['total_rows' => count($rows)]);
}

function mountMappingStep(object $context, ImportEntityType $entityType = ImportEntityType::People): Testable
{
    return Livewire::test(MappingStep::class, [
        'storeId' => $context->store->id(),
        'entityType' => $entityType,
    ]);
}

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

it('continue action saves mappings to store', function (): void {
    createStoreWithHeaders($this, ['Name', 'Notes']);

    $component = mountMappingStep($this);
    $component->call('mapToField', 'Name', 'name');
    $component->callAction('continue');

    $freshImport = $this->import->fresh();
    $savedMappings = $freshImport->columnMappings();
    expect($savedMappings)->toHaveCount(1)
        ->and($savedMappings->first()->source)->toBe('Name')
        ->and($savedMappings->first()->target)->toBe('name');
});

it('continue action sets status to Reviewing', function (): void {
    createStoreWithHeaders($this, ['Name']);

    $component = mountMappingStep($this);
    $component->call('mapToField', 'Name', 'name');
    $component->callAction('continue');

    $freshImport = $this->import->fresh();
    expect($freshImport->status)->toBe(ImportStatus::Reviewing);
});

it('continue action dispatches completed event', function (): void {
    createStoreWithHeaders($this, ['Name']);

    $component = mountMappingStep($this);
    $component->call('mapToField', 'Name', 'name');
    $component->callAction('continue');

    $component->assertDispatched('completed');
});

it('continue action is disabled when required fields are unmapped', function (): void {
    createStoreWithHeaders($this, ['Notes', 'Phone']);

    $component = mountMappingStep($this);
    $component->call('unmapColumn', 'Notes');
    $component->call('unmapColumn', 'Phone');

    $component->assertActionDisabled('continue');
});

it('previewValues returns sample values from SQLite', function (): void {
    createStoreWithHeaders($this, ['Name', 'Email'], [
        ['Name' => 'John', 'Email' => 'john@test.com'],
        ['Name' => 'Jane', 'Email' => 'jane@test.com'],
    ]);

    $component = mountMappingStep($this);

    $component->call('previewValues', 'Name')
        ->assertReturned(['John', 'Jane']);
});

it('maps a header the aliases miss to the field the classifier picks', function (): void {
    ClassificationFake::choosing(['Position held' => 'Job Title']);
    createStoreWithHeaders($this, ['Name', 'Position held'], [['Name' => 'Ann', 'Position held' => 'CTO']]);

    $columns = mountMappingStep($this)->get('columns');

    expect($columns['Position held']['target'])->toBe('custom_fields_job_title');
});

it('keeps the more confident header when two pick the same field', function (): void {
    ClassificationFake::choosing(
        ['Role' => 'Job Title', 'Position held' => 'Job Title'],
        confidence: ['Role' => 0.95, 'Position held' => 0.85],
    );
    createStoreWithHeaders($this, ['Name', 'Role', 'Position held'], [['Name' => 'Ann', 'Role' => 'CTO', 'Position held' => 'CTO']]);

    $columns = mountMappingStep($this)->get('columns');

    expect($columns['Role']['target'])->toBe('custom_fields_job_title')
        ->and($columns)->not->toHaveKey('Position held');
});

it('never offers the record id field to the classifier', function (): void {
    ClassificationFake::choosing([]);
    createStoreWithHeaders($this, ['Name', 'Customer number'], [['Name' => 'Ann', 'Customer number' => '8812']]);

    mountMappingStep($this);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => ! in_array('Record ID', $prompt->questions['column_0']->options, true));
});

it('never offers a matchable field to the classifier, because mapping one turns creates into updates', function (): void {
    ClassificationFake::choosing(['Parent domain' => 'Domains']);
    $this->import->update(['entity_type' => ImportEntityType::Company]);
    createStoreWithHeaders($this, ['Name', 'Parent domain'], [['Name' => 'Acme', 'Parent domain' => 'acme.com']]);

    $columns = mountMappingStep($this, ImportEntityType::Company)->get('columns');

    expect($columns)->not->toHaveKey('Parent domain');
    Classification::assertClassified(function (ClassificationPrompt $prompt): bool {
        $labels = array_values($prompt->questions['column_0']->options);

        return ! in_array('Domains', $labels, true) && ! in_array('Record ID', $labels, true);
    });
});

it('never offers the record id field, even for an importer without match fields', function (): void {
    ClassificationFake::choosing(['Customer number' => 'Record ID']);
    $this->import->update(['entity_type' => ImportEntityType::Note]);
    createStoreWithHeaders($this, ['Title', 'Customer number'], [['Title' => 'Kickoff', 'Customer number' => '8812']]);

    $columns = mountMappingStep($this, ImportEntityType::Note)->get('columns');

    expect($columns)->not->toHaveKey('Customer number');
    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => ! in_array('Record ID', $prompt->questions['column_0']->options, true));
});

it('sends the first non-blank sample values even when the leading rows are blank', function (): void {
    ClassificationFake::choosing([]);
    createStoreWithHeaders($this, ['Name', 'Position held'], [
        ['Name' => 'Ann', 'Position held' => ''],
        ['Name' => 'Bob', 'Position held' => ''],
        ['Name' => 'Cara', 'Position held' => ''],
        ['Name' => 'Dan', 'Position held' => 'CTO'],
    ]);

    mountMappingStep($this);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->state['columns'][0]['samples'] === ['CTO']);
});

it('keeps a field already mapped by alias out of the classifier options for an unmapped header', function (): void {
    ClassificationFake::choosing(['Referral source' => 'Name']);
    createStoreWithHeaders($this, ['Name', 'Referral source'], [['Name' => 'Ann', 'Referral source' => 'Website']]);

    $columns = mountMappingStep($this)->get('columns');

    expect($columns['Name']['target'])->toBe('name')
        ->and($columns)->not->toHaveKey('Referral source');
    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => ! in_array('Name', $prompt->questions['column_0']->options, true));
});

it('does not classify when the aliases map every header', function (): void {
    ClassificationFake::choosing([]);
    createStoreWithHeaders($this, ['Name']);

    mountMappingStep($this);

    Classification::assertNothingClassified();
});

it('leaves unknown headers unmapped when matching is off', function (): void {
    Classification::fake();
    createStoreWithHeaders($this, ['Name', 'Position held'], [['Name' => 'Ann', 'Position held' => 'CTO']]);

    $columns = mountMappingStep($this)->get('columns');

    expect($columns)->not->toHaveKey('Position held');
    Classification::assertNothingClassified();
});

it('leaves unknown headers unmapped when the classifier fails', function (): void {
    ClassificationFake::failing();
    createStoreWithHeaders($this, ['Name', 'Position held'], [['Name' => 'Ann', 'Position held' => 'CTO']]);

    $component = mountMappingStep($this);

    $component->assertOk();
    expect($component->get('columns'))->not->toHaveKey('Position held');
});

it('leaves the header unmapped when the classifier returns a choice outside the field range', function (): void {
    ClassificationFake::respondingWith(fn (ClassificationPrompt $prompt): array => [
        'column_0' => new ChoiceAnswer('f99', [], 0.99),
    ]);
    createStoreWithHeaders($this, ['Name', 'Position held'], [['Name' => 'Ann', 'Position held' => 'CTO']]);

    $component = mountMappingStep($this);

    $component->assertOk();
    expect($component->get('columns'))->not->toHaveKey('Position held');
});
