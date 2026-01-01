<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Imports;

use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Imports\CompanyImporter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->id);
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
        $import = createImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter(
            $import,
            ['name' => 'name', $domainsKey => $domainsKey],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        setImporterData($importer, [
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
        $import = createImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter(
            $import,
            ['name' => 'name', $domainsKey => $domainsKey],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        setImporterData($importer, [
            'name' => 'Acme Inc', // Matches first company by name
            $domainsKey => 'acme.com', // Matches second company by domain
        ]);

        $record = $importer->resolveRecord();

        // Should match by domain (higher priority), not by name
        expect($record->id)->toBe($domainMatchCompany->id);
    });

    it('creates new company when domain does not match', function (): void {
        $domainField = createDomainsFieldForCompany($this->team);
        $existingCompany = Company::factory()->for($this->team, 'team')->create(['name' => 'Existing Inc']);
        setCompanyDomainValue($existingCompany, 'existing.com', $domainField);

        $domainsKey = 'custom_fields_'.CompanyField::DOMAINS->value;
        $import = createImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter(
            $import,
            ['name' => 'name', $domainsKey => $domainsKey],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        setImporterData($importer, [
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
        $import = createImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter(
            $import,
            ['name' => 'name', $domainsKey => $domainsKey],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        setImporterData($importer, [
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
        $import = createImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter(
            $import,
            ['name' => 'name', $domainsKey => $domainsKey],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setImporterData($importer, [
            'name' => 'Acme Inc',
            $domainsKey => 'acme.com',
        ]);

        $record = $importer->resolveRecord();

        // CREATE_NEW should always create new record
        expect($record->exists)->toBeFalse();
    });
});
