<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Relaticle\ImportWizard\Data\RelationshipField;
use Relaticle\ImportWizard\Data\RelationshipMatcher;
use Relaticle\ImportWizard\Enums\MatchType;
use Relaticle\ImportWizard\Filament\Imports\OpportunityImporter;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;
use Relaticle\ImportWizard\Filament\Imports\TaskImporter;
use Relaticle\ImportWizard\Livewire\ImportWizard;

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    ['user' => $this->user, 'team' => $this->team] = setupImportTestContext();
    createEmailsCustomField($this->team);
});

describe('MatchType Enum', function (): void {
    it('returns correct isMatched for :dataset', function (MatchType $type, bool $expected): void {
        expect($type->isMatched())->toBe($expected);
    })->with([
        'Id matches' => [MatchType::Id, true],
        'Domain matches' => [MatchType::Domain, true],
        'Email matches' => [MatchType::Email, true],
        'New does NOT match' => [MatchType::New, false],
        'None does NOT match' => [MatchType::None, false],
    ]);

    it('returns correct willCreate for :dataset', function (MatchType $type, bool $expected): void {
        expect($type->willCreate())->toBe($expected);
    })->with([
        'New creates' => [MatchType::New, true],
        'Id does NOT create' => [MatchType::Id, false],
        'Domain does NOT create' => [MatchType::Domain, false],
        'Email does NOT create' => [MatchType::Email, false],
        'None does NOT create' => [MatchType::None, false],
    ]);

    it('has correct labels', function (): void {
        expect(MatchType::New->getLabel())->toBe('Will Create New')
            ->and(MatchType::Email->getLabel())->toBe('Matched by Email')
            ->and(MatchType::Domain->getLabel())->toBe('Matched by Domain')
            ->and(MatchType::Id->getLabel())->toBe('Matched by ID');
    });
});

describe('RelationshipField Data', function (): void {
    it('creates a relationship field with matchers', function (): void {
        $field = new RelationshipField(
            name: 'company',
            label: 'Company',
            targetEntity: 'companies',
            icon: 'heroicon-o-building-office-2',
            matchers: RelationshipMatcher::collection([
                new RelationshipMatcher(
                    key: 'id',
                    label: 'Record ID',
                    description: 'Match by ULID',
                    createsNew: false,
                ),
                new RelationshipMatcher(
                    key: 'name',
                    label: 'Name',
                    description: 'Create new',
                    createsNew: true,
                ),
            ]),
            defaultMatcher: 'name',
        );

        expect($field->name)->toBe('company')
            ->and($field->label)->toBe('Company')
            ->and($field->targetEntity)->toBe('companies')
            ->and($field->matchers)->toHaveCount(2)
            ->and($field->getMatcher('id')?->createsNew)->toBeFalse()
            ->and($field->getMatcher('name')?->createsNew)->toBeTrue()
            ->and($field->getDefaultMatcher()->key)->toBe('name');
    });

    it('returns null for non-existent matcher', function (): void {
        $field = new RelationshipField(
            name: 'company',
            label: 'Company',
            targetEntity: 'companies',
            icon: 'heroicon-o-building-office-2',
            matchers: RelationshipMatcher::collection([
                new RelationshipMatcher(key: 'id', label: 'ID', description: 'Match'),
            ]),
        );

        expect($field->getMatcher('nonexistent'))->toBeNull();
    });
});

describe('Importer Relationship Fields', function (): void {
    it('PeopleImporter has company relationship field', function (): void {
        $fields = PeopleImporter::getRelationshipFields();

        expect($fields)->toHaveKey('company')
            ->and($fields['company']->label)->toBe('Company')
            ->and($fields['company']->targetEntity)->toBe('companies')
            ->and($fields['company']->matchers)->toHaveCount(3);

        // Verify matchers
        $idMatcher = $fields['company']->getMatcher('id');
        expect($idMatcher?->createsNew)->toBeFalse()
            ->and($idMatcher?->label)->toBe('Record ID');

        $domainMatcher = $fields['company']->getMatcher('domain');
        expect($domainMatcher?->createsNew)->toBeFalse()
            ->and($domainMatcher?->label)->toBe('Domain');

        $nameMatcher = $fields['company']->getMatcher('name');
        expect($nameMatcher?->createsNew)->toBeTrue()
            ->and($nameMatcher?->label)->toBe('Name');
    });

    it('OpportunityImporter has company and contact relationship fields', function (): void {
        $fields = OpportunityImporter::getRelationshipFields();

        expect($fields)->toHaveKey('company')
            ->and($fields)->toHaveKey('contact');

        // Company matchers
        expect($fields['company']->getMatcher('name')?->createsNew)->toBeTrue()
            ->and($fields['company']->getMatcher('id')?->createsNew)->toBeFalse();

        // Contact matchers (id, email, and name)
        expect($fields['contact']->getMatcher('name')?->createsNew)->toBeTrue()
            ->and($fields['contact']->getMatcher('id')?->createsNew)->toBeFalse()
            ->and($fields['contact']->getMatcher('email')?->createsNew)->toBeFalse()
            ->and($fields['contact']->matchers)->toHaveCount(3);
    });

    it('TaskImporter has linked entity relationship fields', function (): void {
        $fields = TaskImporter::getRelationshipFields();

        expect($fields)->toHaveKey('linked_company')
            ->and($fields)->toHaveKey('linked_person')
            ->and($fields)->toHaveKey('linked_opportunity');
    });
});

describe('Import Wizard Relationship Mapping', function (): void {
    it('shows relationship fields in mapping step for people', function (): void {
        wizardTest($this->team, 'people')
            ->set('uploadedFile', createTestCsv("name,company_name,email\nJohn,Acme,john@acme.com"))
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->assertSee('Company');
    });

    it('shows relationship fields in mapping step for opportunities', function (): void {
        wizardTest($this->team, 'opportunities')
            ->set('uploadedFile', createTestCsv("name,company_name,contact_name\nDeal,Acme,John"))
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP)
            ->assertSee('Company')
            ->assertSee('Contact');
    });

    it('maps CSV column to relationship field', function (): void {
        $component = wizardTest($this->team, 'people')
            ->set('uploadedFile', createTestCsv("name,company_col\nJohn,Acme Corp"))
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);

        $component->call('mapRelationshipField', 'company', 'company_col', 'name');

        expect($component->get('relationshipMappings'))->toHaveKey('company')
            ->and($component->get('relationshipMappings.company.csvColumn'))->toBe('company_col')
            ->and($component->get('relationshipMappings.company.matcher'))->toBe('name');
    });

    it('clears relationship mapping when mapping empty string', function (): void {
        $component = wizardTest($this->team, 'people')
            ->set('uploadedFile', createTestCsv("name,company_col\nJohn,Acme Corp"))
            ->call('nextStep')
            ->call('mapRelationshipField', 'company', 'company_col', 'name');

        expect($component->get('relationshipMappings'))->toHaveKey('company');

        $component->call('mapRelationshipField', 'company', '', 'name');

        expect($component->get('relationshipMappings'))->not->toHaveKey('company');
    });

    it('auto-maps company_name CSV header to relationship column', function (): void {
        // company_name CSV header is claimed by rel_company_name ImportColumn
        // which is hidden from the dropdown but still participates in auto-mapping
        $component = wizardTest($this->team, 'people')
            ->set('uploadedFile', createTestCsv("name,company_name\nJohn,Acme Corp"))
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);

        // company_name CSV header should be mapped to rel_company_name internal column
        expect($component->get('columnMap.rel_company_name'))->toBe('company_name');
    });

    it('detects when relationship will create new records', function (): void {
        $component = wizardTest($this->team, 'people')
            ->set('uploadedFile', createTestCsv("name,company_col\nJohn,Acme Corp"))
            ->call('nextStep')
            ->call('mapRelationshipField', 'company', 'company_col', 'name');

        // Name matcher should trigger "creates new" warning
        $reflection = new ReflectionMethod($component->instance(), 'hasRelationshipCreatingNewRecords');

        expect($reflection->invoke($component->instance()))->toBeTrue();
    });

    it('does NOT flag creating new when using ID matcher', function (): void {
        $component = wizardTest($this->team, 'people')
            ->set('uploadedFile', createTestCsv("name,company_id\nJohn,01ABC123"))
            ->call('nextStep')
            ->call('mapRelationshipField', 'company', 'company_id', 'id');

        $reflection = new ReflectionMethod($component->instance(), 'hasRelationshipCreatingNewRecords');

        expect($reflection->invoke($component->instance()))->toBeFalse();
    });

    it('clears internal relationship column when selecting relationship matcher for same CSV column', function (): void {
        $component = wizardTest($this->team, 'people')
            ->set('uploadedFile', createTestCsv("name,some_col\nJohn,Test Value"))
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);

        // First map CSV column to internal relationship column via dropdown
        $component->call('mapCsvColumnToField', 'some_col', 'rel_company_name');
        expect($component->get('columnMap.rel_company_name'))->toBe('some_col');

        // Then map to relationship via submenu - should update internal column
        $component->call('mapRelationshipField', 'company', 'some_col', 'name');
        expect($component->get('relationshipMappings'))->toHaveKey('company')
            ->and($component->get('columnMap.rel_company_name'))->toBe('some_col');
    });

    it('clears relationship when selecting different internal column for same CSV column', function (): void {
        $component = wizardTest($this->team, 'people')
            ->set('uploadedFile', createTestCsv("name,some_col\nJohn,Test Value"))
            ->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);

        // First map to relationship
        $component->call('mapRelationshipField', 'company', 'some_col', 'name');
        expect($component->get('relationshipMappings'))->toHaveKey('company');

        // Then map to a different internal column - should clear relationship
        $component->call('mapCsvColumnToField', 'some_col', 'rel_company_id');
        expect($component->get('columnMap.rel_company_id'))->toBe('some_col')
            ->and($component->get('relationshipMappings'))->not->toHaveKey('company');
    });
});

describe('Relationship Mapping Persistence', function (): void {
    it('preserves relationship mapping when navigating forward through steps', function (): void {
        $component = wizardTest($this->team, 'people')
            ->set('uploadedFile', createTestCsv("name,company_col\nJohn,Acme Corp"))
            ->call('nextStep')
            ->call('mapRelationshipField', 'company', 'company_col', 'name');

        expect($component->get('relationshipMappings'))->toHaveKey('company')
            ->and($component->get('relationshipMappings.company.csvColumn'))->toBe('company_col');

        // Proceed to review (needs required mapping)
        $component->set('columnMap.name', 'name')
            ->callAction('proceedWithoutUniqueIdentifiers');

        // Mapping should persist through step advancement
        expect($component->get('relationshipMappings'))->toHaveKey('company')
            ->and($component->get('relationshipMappings.company.csvColumn'))->toBe('company_col');
    });

    it('re-runs auto-mapping when going back to upload step', function (): void {
        // This is expected behavior: going back to upload step and forward
        // re-processes the file and re-runs auto-mapping
        $component = wizardTest($this->team, 'people')
            ->set('uploadedFile', createTestCsv("name,company_col\nJohn,Acme Corp"))
            ->call('nextStep')
            ->call('mapRelationshipField', 'company', 'company_col', 'name');

        expect($component->get('relationshipMappings'))->toHaveKey('company');

        // Go back to upload step
        $component->call('previousStep')
            ->assertSet('currentStep', ImportWizard::STEP_UPLOAD);

        // Going forward re-runs autoMapColumns which resets relationship mappings
        $component->call('nextStep')
            ->assertSet('currentStep', ImportWizard::STEP_MAP);

        // Auto-mapping doesn't find company_col (not in guesses), so no relationship mapped
        expect($component->get('relationshipMappings'))->not->toHaveKey('company');
    });

    it('clears relationship mapping on reset', function (): void {
        $component = wizardTest($this->team, 'people')
            ->set('uploadedFile', createTestCsv("name,company_col\nJohn,Acme Corp"))
            ->call('nextStep')
            ->call('mapRelationshipField', 'company', 'company_col', 'name');

        expect($component->get('relationshipMappings'))->not->toBeEmpty();

        $component->call('resetWizard');

        expect($component->get('relationshipMappings'))->toBeEmpty();
    });
});

describe('Relationship Creation During Import', function (): void {
    it('creates new company when using rel_company_name column', function (): void {
        Company::factory()->for($this->team)->create(['name' => 'Existing Corp']);

        $import = createImportRecord($this->user, $this->team, PeopleImporter::class);
        $importer = new PeopleImporter(
            $import,
            ['name' => 'name', 'rel_company_name' => 'company_name'],
            []
        );

        $data = ['name' => 'John Doe', 'company_name' => 'Brand New Corp'];
        setImporterData($importer, $data);
        ($importer)($data);

        $person = People::where('name', 'John Doe')->first();
        $newCompany = Company::where('name', 'Brand New Corp')->first();

        expect($person)->not->toBeNull()
            ->and($newCompany)->not->toBeNull()
            ->and($person->company_id)->toBe($newCompany->id);
    });

    it('matches existing company by ID using rel_company_id column', function (): void {
        $existingCompany = Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);

        $import = createImportRecord($this->user, $this->team, PeopleImporter::class);
        $importer = new PeopleImporter(
            $import,
            ['name' => 'name', 'rel_company_id' => 'company_id'],
            []
        );

        $data = ['name' => 'John Doe', 'company_id' => $existingCompany->id];
        setImporterData($importer, $data);
        ($importer)($data);

        $person = People::where('name', 'John Doe')->first();

        expect($person)->not->toBeNull()
            ->and($person->company_id)->toBe($existingCompany->id);

        // Should NOT create a new company
        expect(Company::count())->toBe(1);
    });
});
