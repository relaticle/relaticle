<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use App\Enums\CustomFields\CompanyField;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Imports\CompanyImporter;
use Relaticle\ImportWizard\Models\Import;

uses(RefreshDatabase::class);

function createCompanyTestImportRecord(User $user, Team $team): Import
{
    return Import::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'importer' => CompanyImporter::class,
        'file_name' => 'test.csv',
        'file_path' => '/tmp/test.csv',
        'total_rows' => 1,
    ]);
}

function setCompanyImporterData(object $importer, array $data): void
{
    $reflection = new \ReflectionClass($importer);
    $dataProperty = $reflection->getProperty('data');
    $dataProperty->setValue($importer, $data);
}

beforeEach(function () {
    Storage::fake('local');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->id);
});

test('company importer has correct columns defined', function () {
    $columns = CompanyImporter::getColumns();

    $columnNames = collect($columns)->map(fn ($column) => $column->getName())->all();

    // Core database columns are defined explicitly
    // Custom fields like address, country, phone are handled by CustomFields::importer()
    expect($columnNames)
        ->toContain('name')
        ->toContain('account_owner_email');
});

test('company importer has required name column', function () {
    $columns = CompanyImporter::getColumns();

    $nameColumn = collect($columns)->first(fn ($column) => $column->getName() === 'name');

    expect($nameColumn)->not->toBeNull()
        ->and($nameColumn->isMappingRequired())->toBeTrue();
});

test('company importer has options form with duplicate handling', function () {
    $components = CompanyImporter::getOptionsFormComponents();

    $duplicateHandlingComponent = collect($components)->first(
        fn ($component) => $component->getName() === 'duplicate_handling'
    );

    expect($duplicateHandlingComponent)->not->toBeNull()
        ->and($duplicateHandlingComponent->isRequired())->toBeTrue();
});

test('import action exists on list companies page', function () {
    Livewire::test(ListCompanies::class)
        ->assertSuccessful()
        ->assertActionExists('import');
});

test('company importer guesses column names correctly', function () {
    $columns = CompanyImporter::getColumns();

    $nameColumn = collect($columns)->first(fn ($column) => $column->getName() === 'name');

    expect($nameColumn->getGuesses())
        ->toContain('name')
        ->toContain('company_name')
        ->toContain('company');
});

test('company importer provides example values', function () {
    $columns = CompanyImporter::getColumns();

    $nameColumn = collect($columns)->first(fn ($column) => $column->getName() === 'name');

    expect($nameColumn->getExample())->not->toBeNull()
        ->and($nameColumn->getExample())->toBe('Acme Corporation');
});

test('duplicate handling strategy enum has correct values', function () {
    expect(DuplicateHandlingStrategy::SKIP->value)->toBe('skip')
        ->and(DuplicateHandlingStrategy::UPDATE->value)->toBe('update')
        ->and(DuplicateHandlingStrategy::CREATE_NEW->value)->toBe('create_new');
});

test('duplicate handling strategy has labels', function () {
    expect(DuplicateHandlingStrategy::SKIP->getLabel())->toBe('Skip duplicates')
        ->and(DuplicateHandlingStrategy::UPDATE->getLabel())->toBe('Update existing records')
        ->and(DuplicateHandlingStrategy::CREATE_NEW->getLabel())->toBe('Create new records anyway');
});

test('company importer returns completed notification body', function () {
    $import = new Import;
    $import->successful_rows = 10;

    $body = CompanyImporter::getCompletedNotificationBody($import);

    expect($body)->toContain('10')
        ->and($body)->toContain('imported');
});

test('company importer includes failed rows in notification', function () {
    $import = Import::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'successful_rows' => 8,
        'total_rows' => 10,
        'processed_rows' => 10,
        'importer' => CompanyImporter::class,
        'file_name' => 'test.csv',
        'file_path' => 'imports/test.csv',
    ]);

    // Create some failed rows
    $import->failedRows()->createMany([
        ['data' => ['name' => 'Failed 1'], 'validation_error' => 'Invalid data'],
        ['data' => ['name' => 'Failed 2'], 'validation_error' => 'Invalid data'],
    ]);

    $body = CompanyImporter::getCompletedNotificationBody($import);

    expect($body)->toContain('8')
        ->and($body)->toContain('2')
        ->and($body)->toContain('failed');
});

describe('Domain-Based Duplicate Detection', function (): void {
    function createDomainsFieldForCompany(Team $team): CustomField
    {
        return CustomField::withoutGlobalScopes()->create([
            'code' => CompanyField::DOMAINS->value,
            'name' => CompanyField::DOMAINS->getDisplayName(),
            'type' => 'link',
            'entity_type' => 'company',
            'tenant_id' => $team->id,
            'sort_order' => 1,
            'active' => true,
            'system_defined' => true,
        ]);
    }

    function setCompanyDomainValue(Company $company, string $domain, CustomField $field): void
    {
        CustomFieldValue::withoutGlobalScopes()->create([
            'entity_type' => 'company',
            'entity_id' => $company->id,
            'custom_field_id' => $field->id,
            'tenant_id' => $company->team_id,
            'json_value' => [$domain],
        ]);
    }

    it('matches company by domains with UPDATE strategy', function (): void {
        $domainField = createDomainsFieldForCompany($this->team);
        $existingCompany = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);
        setCompanyDomainValue($existingCompany, 'acme.com', $domainField);

        $domainsKey = 'custom_fields_'.CompanyField::DOMAINS->value;
        $import = createCompanyTestImportRecord($this->user, $this->team);
        $importer = new CompanyImporter(
            $import,
            ['name' => 'name', $domainsKey => $domainsKey],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        setCompanyImporterData($importer, [
            'name' => 'Different Name',
            $domainsKey => 'acme.com',
        ]);

        $record = $importer->resolveRecord();

        expect($record->id)->toBe($existingCompany->id)
            ->and($record->exists)->toBeTrue();
    });

    it('prioritizes domain match over name match', function (): void {
        $domainField = createDomainsFieldForCompany($this->team);

        // Company that matches by name only
        $nameMatchCompany = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);

        // Company that matches by domain but has different name
        $domainMatchCompany = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Corporation']);
        setCompanyDomainValue($domainMatchCompany, 'acme.com', $domainField);

        $domainsKey = 'custom_fields_'.CompanyField::DOMAINS->value;
        $import = createCompanyTestImportRecord($this->user, $this->team);
        $importer = new CompanyImporter(
            $import,
            ['name' => 'name', $domainsKey => $domainsKey],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        setCompanyImporterData($importer, [
            'name' => 'Acme Inc', // Matches first company by name
            $domainsKey => 'acme.com', // Matches second company by domain
        ]);

        $record = $importer->resolveRecord();

        // Should match by domain (higher priority), not by name
        expect($record->id)->toBe($domainMatchCompany->id);
    });

    it('falls back to name match when no domain provided', function (): void {
        $domainField = createDomainsFieldForCompany($this->team);
        $existingCompany = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);
        setCompanyDomainValue($existingCompany, 'acme.com', $domainField);

        $import = createCompanyTestImportRecord($this->user, $this->team);
        $importer = new CompanyImporter(
            $import,
            ['name' => 'name'],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        setCompanyImporterData($importer, [
            'name' => 'Acme Inc',
            // No domain provided
        ]);

        $record = $importer->resolveRecord();

        expect($record->id)->toBe($existingCompany->id)
            ->and($record->exists)->toBeTrue();
    });

    it('creates new company when domain does not match', function (): void {
        $domainField = createDomainsFieldForCompany($this->team);
        $existingCompany = Company::factory()->for($this->team, 'team')->create(['name' => 'Existing Inc']);
        setCompanyDomainValue($existingCompany, 'existing.com', $domainField);

        $domainsKey = 'custom_fields_'.CompanyField::DOMAINS->value;
        $import = createCompanyTestImportRecord($this->user, $this->team);
        $importer = new CompanyImporter(
            $import,
            ['name' => 'name', $domainsKey => $domainsKey],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        setCompanyImporterData($importer, [
            'name' => 'New Company',
            $domainsKey => 'newcompany.com', // Different domain
        ]);

        $record = $importer->resolveRecord();

        expect($record->exists)->toBeFalse();
    });

    it('normalizes domain to lowercase for matching', function (): void {
        $domainField = createDomainsFieldForCompany($this->team);
        $existingCompany = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);
        setCompanyDomainValue($existingCompany, 'acme.com', $domainField);

        $domainsKey = 'custom_fields_'.CompanyField::DOMAINS->value;
        $import = createCompanyTestImportRecord($this->user, $this->team);
        $importer = new CompanyImporter(
            $import,
            ['name' => 'name', $domainsKey => $domainsKey],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        setCompanyImporterData($importer, [
            'name' => 'Acme Inc',
            $domainsKey => 'ACME.COM', // Uppercase
        ]);

        $record = $importer->resolveRecord();

        expect($record->id)->toBe($existingCompany->id);
    });

    it('respects CREATE_NEW strategy even with domain match', function (): void {
        $domainField = createDomainsFieldForCompany($this->team);
        $existingCompany = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);
        setCompanyDomainValue($existingCompany, 'acme.com', $domainField);

        $domainsKey = 'custom_fields_'.CompanyField::DOMAINS->value;
        $import = createCompanyTestImportRecord($this->user, $this->team);
        $importer = new CompanyImporter(
            $import,
            ['name' => 'name', $domainsKey => $domainsKey],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setCompanyImporterData($importer, [
            'name' => 'Acme Inc',
            $domainsKey => 'acme.com',
        ]);

        $record = $importer->resolveRecord();

        // CREATE_NEW should always create new record
        expect($record->exists)->toBeFalse();
    });
});
