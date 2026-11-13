<?php

declare(strict_types=1);

use App\Events\WorkspaceCreated;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
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
use Tests\Helpers\ImportExecutionFixture;

mutates(MappingStep::class, ColumnData::class, ImportField::class, ImportFieldCollection::class);

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
    ImportStore::delete($this->store->id());
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

    $context->store = ImportExecutionFixture::publish($context->store);
}

function mountMappingStep(object $context): Testable
{
    return Livewire::test(MappingStep::class, [
        'storeId' => $context->store->id(),
        'entityType' => ImportEntityType::People,
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

it('auto-maps the person column of an opportunity export to the contact link', function (string $header): void {
    $this->import->update(['entity_type' => ImportEntityType::Opportunity]);

    createStoreWithHeaders($this, ['Name', $header], [
        ['Name' => 'Renewal', $header => 'Jane Roe'],
    ]);

    $columns = Livewire::test(MappingStep::class, [
        'storeId' => $this->store->id(),
        'entityType' => ImportEntityType::Opportunity,
    ])->get('columns');

    expect($columns)->toHaveKey($header)
        ->and($columns[$header]['entityLink'])->toBe('contact');
})->with(['Point of Contact', 'Contact Person']);

it('offers the opportunity contact link as point of contact', function (): void {
    $this->import->update(['entity_type' => ImportEntityType::Opportunity]);

    createStoreWithHeaders($this, ['Name', 'Person'], [
        ['Name' => 'Renewal', 'Person' => 'Jane Roe'],
    ]);

    Livewire::test(MappingStep::class, [
        'storeId' => $this->store->id(),
        'entityType' => ImportEntityType::Opportunity,
    ])->assertSee('Point of Contact');
});

it('auto-maps a column of linkedin urls to the linkedin field', function (): void {
    createStoreWithHeaders($this, ['Name', 'Profile'], [
        ['Name' => 'Ada', 'Profile' => 'https://linkedin.com/in/ada'],
        ['Name' => 'Grace', 'Profile' => 'https://linkedin.com/in/grace'],
    ]);

    $columns = mountMappingStep($this)->get('columns');

    expect($columns)->toHaveKey('Profile')
        ->and($columns['Profile']['target'])->toBe('custom_fields_linkedin');
});

it('auto-maps a single url column of a company import to the domains field', function (): void {
    $this->import->update(['entity_type' => ImportEntityType::Company]);

    createStoreWithHeaders($this, ['Name', 'Site'], [
        ['Name' => 'Acme', 'Site' => 'https://acme.com'],
        ['Name' => 'Globex', 'Site' => 'https://globex.com'],
    ]);

    $columns = Livewire::test(MappingStep::class, [
        'storeId' => $this->store->id(),
        'entityType' => ImportEntityType::Company,
    ])->get('columns');

    expect($columns['Site']['target'])->toBe('custom_fields_domains');
});

it('suggests the domains field for a url column after its row moved in the heap', function (): void {
    $this->import->update(['entity_type' => ImportEntityType::Company]);

    DB::table('custom_fields')
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->update(['name' => DB::raw('name')]);

    $physicalOrder = DB::table('custom_fields')
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'company')
        ->whereIn('code', ['domains', 'linkedin'])
        ->orderByRaw('ctid')
        ->pluck('code')
        ->all();

    expect($physicalOrder)->toBe(['linkedin', 'domains']);

    createStoreWithHeaders($this, ['Name', 'Site'], [
        ['Name' => 'Acme', 'Site' => 'https://acme.com'],
        ['Name' => 'Globex', 'Site' => 'https://globex.com'],
    ]);

    $columns = Livewire::test(MappingStep::class, [
        'storeId' => $this->store->id(),
        'entityType' => ImportEntityType::Company,
    ])->get('columns');

    expect($columns['Site']['target'])->toBe('custom_fields_domains');
});

it('suggests the domains field ahead of a link field a user created and ordered', function (): void {
    $this->import->update(['entity_type' => ImportEntityType::Company]);

    $seeded = DB::table('custom_fields')
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'company')
        ->where('code', 'linkedin')
        ->first();

    DB::table('custom_fields')->insert([
        ...(array) $seeded,
        'id' => strtolower((string) Str::ulid()),
        'code' => 'press_page',
        'name' => 'Press page',
        'sort_order' => 1,
        'system_defined' => false,
    ]);

    createStoreWithHeaders($this, ['Name', 'Site'], [
        ['Name' => 'Acme', 'Site' => 'https://acme.com'],
        ['Name' => 'Globex', 'Site' => 'https://globex.com'],
    ]);

    $columns = Livewire::test(MappingStep::class, [
        'storeId' => $this->store->id(),
        'entityType' => ImportEntityType::Company,
    ])->get('columns');

    expect($columns['Site']['target'])->toBe('custom_fields_domains');
});

it('maps a second url column of a company import to the other link field', function (): void {
    $this->import->update(['entity_type' => ImportEntityType::Company]);

    createStoreWithHeaders($this, ['Name', 'Site', 'Profile'], [
        ['Name' => 'Acme', 'Site' => 'https://acme.com', 'Profile' => 'https://linkedin.com/company/acme'],
        ['Name' => 'Globex', 'Site' => 'https://globex.com', 'Profile' => 'https://linkedin.com/company/globex'],
    ]);

    $columns = Livewire::test(MappingStep::class, [
        'storeId' => $this->store->id(),
        'entityType' => ImportEntityType::Company,
    ])->get('columns');

    expect($columns['Site']['target'])->toBe('custom_fields_domains')
        ->and($columns['Profile']['target'])->toBe('custom_fields_linkedin');
});

it('maps a company url column whose values carry a path to the link field', function (): void {
    $this->import->update(['entity_type' => ImportEntityType::Company]);

    createStoreWithHeaders($this, ['Name', 'Profile'], [
        ['Name' => 'Acme', 'Profile' => 'https://www.linkedin.com/company/acme'],
        ['Name' => 'Globex', 'Profile' => 'https://www.linkedin.com/company/globex'],
    ]);

    $columns = Livewire::test(MappingStep::class, [
        'storeId' => $this->store->id(),
        'entityType' => ImportEntityType::Company,
    ])->get('columns');

    expect($columns['Profile']['target'])->toBe('custom_fields_linkedin');
});

it('maps a profile column before a homepage column to the link field and the homepage to domains', function (): void {
    $this->import->update(['entity_type' => ImportEntityType::Company]);

    createStoreWithHeaders($this, ['Name', 'Profile', 'Site'], [
        ['Name' => 'Acme', 'Profile' => 'https://linkedin.com/company/acme', 'Site' => 'https://www.acme.com/'],
        ['Name' => 'Globex', 'Profile' => 'https://linkedin.com/company/globex', 'Site' => 'https://globex.com'],
    ]);

    $columns = Livewire::test(MappingStep::class, [
        'storeId' => $this->store->id(),
        'entityType' => ImportEntityType::Company,
    ])->get('columns');

    expect($columns['Profile']['target'])->toBe('custom_fields_linkedin')
        ->and($columns['Site']['target'])->toBe('custom_fields_domains');
});

it('keeps a homepage column on domains when a few of its values are deep links', function (): void {
    $this->import->update(['entity_type' => ImportEntityType::Company]);

    createStoreWithHeaders($this, ['Name', 'Site'], [
        ['Name' => 'Acme', 'Site' => 'https://acme.com'],
        ['Name' => 'Globex', 'Site' => 'https://globex.com/about'],
        ['Name' => 'Initech', 'Site' => 'https://initech.com'],
    ]);

    $columns = Livewire::test(MappingStep::class, [
        'storeId' => $this->store->id(),
        'entityType' => ImportEntityType::Company,
    ])->get('columns');

    expect($columns['Site']['target'])->toBe('custom_fields_domains');
});

it('leaves a second profile column unmapped instead of reducing it to a domain', function (): void {
    $this->import->update(['entity_type' => ImportEntityType::Company]);

    createStoreWithHeaders($this, ['Name', 'Profile', 'Feed'], [
        ['Name' => 'Acme', 'Profile' => 'https://linkedin.com/company/acme', 'Feed' => 'https://twitter.com/acme'],
        ['Name' => 'Globex', 'Profile' => 'https://linkedin.com/company/globex', 'Feed' => 'https://twitter.com/globex'],
    ]);

    $columns = Livewire::test(MappingStep::class, [
        'storeId' => $this->store->id(),
        'entityType' => ImportEntityType::Company,
    ])->get('columns');

    expect($columns['Profile']['target'])->toBe('custom_fields_linkedin')
        ->and($columns)->not->toHaveKey('Feed');
});

it('maps a company url column to the link field when a host would lose its content', function (string $first, string $second): void {
    $this->import->update(['entity_type' => ImportEntityType::Company]);

    createStoreWithHeaders($this, ['Name', 'Page'], [
        ['Name' => 'Acme', 'Page' => $first],
        ['Name' => 'Globex', 'Page' => $second],
    ]);

    $columns = Livewire::test(MappingStep::class, [
        'storeId' => $this->store->id(),
        'entityType' => ImportEntityType::Company,
    ])->get('columns');

    expect($columns['Page']['target'])->toBe('custom_fields_linkedin');
})->with([
    'a path that also appears in the host' => ['https://about.acme.com/about', 'https://globex.com/globex'],
    'a query string and no path' => ['https://maps.example.com/?cid=123', 'https://example.org/?p=42'],
]);

it('still maps a column of values that are not urls by their type', function (string $header, array $values, string $target): void {
    createStoreWithHeaders($this, ['Name', $header], [
        ['Name' => 'Ana', $header => $values[0]],
        ['Name' => 'Bob', $header => $values[1]],
    ]);

    $columns = mountMappingStep($this)->get('columns');

    expect($columns[$header]['target'])->toBe($target);
})->with([
    'emails' => ['Reach', ['ana@acme.com', 'bob@globex.com'], 'custom_fields_emails'],
    'phones' => ['Dial', ['+14155550100', '+14155550101'], 'custom_fields_phone_number'],
]);

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

describe('on a remote store disk', function (): void {
    beforeEach(function (): void {
        ImportStore::delete($this->store->id());

        useRemoteImportStore();

        $this->store = ImportStore::create($this->import->id);
    });

    it('previewValues returns sample values from the remote store', function (): void {
        createStoreWithHeaders($this, ['Name', 'Email'], [
            ['Name' => 'John', 'Email' => 'john@test.com'],
            ['Name' => 'Jane', 'Email' => 'jane@test.com'],
        ]);

        mountMappingStep($this)->call('previewValues', 'Name')
            ->assertReturned(['John', 'Jane']);
    });

    it('keeps a second reader of the same copy working after the first closes', function (): void {
        createStoreWithHeaders($this, ['Name', 'Email'], [
            ['Name' => 'John', 'Email' => 'john@test.com'],
        ]);
        $first = ImportStore::forRead($this->import->id);
        $second = ImportStore::forRead($this->import->id);

        $first->close();

        expect($second->query()->count())->toBe(1);
    });
});
