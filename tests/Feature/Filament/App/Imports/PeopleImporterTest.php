<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;
use Relaticle\ImportWizard\Models\Import;

function createPeopleTestImportRecord(User $user, Team $team): Import
{
    return Import::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'importer' => PeopleImporter::class,
        'file_name' => 'test.csv',
        'file_path' => '/tmp/test.csv',
        'total_rows' => 1,
    ]);
}

function setPeopleImporterData(object $importer, array $data): void
{
    $reflection = new ReflectionClass($importer);
    $dataProperty = $reflection->getProperty('data');
    $dataProperty->setValue($importer, $data);
}

function createEmailsCustomField(Team $team): CustomField
{
    return CustomField::withoutGlobalScopes()->create([
        'code' => 'emails',
        'name' => 'Emails',
        'type' => 'email',
        'entity_type' => 'people',
        'tenant_id' => $team->id,
        'sort_order' => 1,
        'active' => true,
        'system_defined' => true,
    ]);
}

function setPersonEmail(People $person, string $email, CustomField $field): void
{
    CustomFieldValue::withoutGlobalScopes()->create([
        'entity_type' => 'people',
        'entity_id' => $person->id,
        'custom_field_id' => $field->id,
        'tenant_id' => $person->team_id,
        'json_value' => [$email], // Don't JSON encode - the model cast handles it
    ]);
}

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->id);

    $this->company = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Corp']);
    $this->emailsField = createEmailsCustomField($this->team);
});

describe('Email-Based Duplicate Detection', function (): void {
    it('finds existing person by email with UPDATE strategy', function (): void {
        // Create existing person with email
        $existingPerson = People::factory()->for($this->team, 'team')->create([
            'name' => 'John Doe',
            'company_id' => $this->company->id,
        ]);
        setPersonEmail($existingPerson, 'john@acme.com', $this->emailsField);

        $import = createPeopleTestImportRecord($this->user, $this->team);
        $importer = new PeopleImporter(
            $import,
            ['name' => 'name', 'company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails'],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        setPeopleImporterData($importer, [
            'name' => 'John Updated',
            'company_name' => 'Acme Corp',
            'custom_fields_emails' => 'john@acme.com',
        ]);

        $record = $importer->resolveRecord();

        expect($record->id)->toBe($existingPerson->id)
            ->and($record->exists)->toBeTrue()
            ->and(People::query()->where('team_id', $this->team->id)->count())->toBe(1);
    });

    it('finds existing person by email with SKIP strategy', function (): void {
        $existingPerson = People::factory()->for($this->team, 'team')->create([
            'name' => 'Jane Smith',
            'company_id' => $this->company->id,
        ]);
        setPersonEmail($existingPerson, 'jane@acme.com', $this->emailsField);

        $import = createPeopleTestImportRecord($this->user, $this->team);
        $importer = new PeopleImporter(
            $import,
            ['name' => 'name', 'company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails'],
            ['duplicate_handling' => DuplicateHandlingStrategy::SKIP]
        );

        setPeopleImporterData($importer, [
            'name' => 'Jane Updated',
            'company_name' => 'Acme Corp',
            'custom_fields_emails' => 'jane@acme.com',
        ]);

        $record = $importer->resolveRecord();

        expect($record->id)->toBe($existingPerson->id)
            ->and($record->exists)->toBeTrue();
    });

    it('creates new person with CREATE_NEW strategy even with matching email', function (): void {
        $existingPerson = People::factory()->for($this->team, 'team')->create([
            'name' => 'Bob Johnson',
            'company_id' => $this->company->id,
        ]);
        setPersonEmail($existingPerson, 'bob@acme.com', $this->emailsField);

        $import = createPeopleTestImportRecord($this->user, $this->team);
        $importer = new PeopleImporter(
            $import,
            ['name' => 'name', 'company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        setPeopleImporterData($importer, [
            'name' => 'Bob Duplicate',
            'company_name' => 'Acme Corp',
            'custom_fields_emails' => 'bob@acme.com',
        ]);

        $record = $importer->resolveRecord();

        expect($record->exists)->toBeFalse()
            ->and(People::query()->where('team_id', $this->team->id)->count())->toBe(1); // Still 1 because record not saved yet
    });

    it('creates new person when email does not match any existing person', function (): void {
        $import = createPeopleTestImportRecord($this->user, $this->team);
        $importer = new PeopleImporter(
            $import,
            ['name' => 'name', 'company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails'],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        setPeopleImporterData($importer, [
            'name' => 'New Person',
            'company_name' => 'Acme Corp',
            'custom_fields_emails' => 'newperson@acme.com',
        ]);

        $record = $importer->resolveRecord();

        expect($record->exists)->toBeFalse();
    });

    it('creates new person when no email provided', function (): void {
        $import = createPeopleTestImportRecord($this->user, $this->team);
        $importer = new PeopleImporter(
            $import,
            ['name' => 'name', 'company_name' => 'company_name'],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        setPeopleImporterData($importer, [
            'name' => 'Person Without Email',
            'company_name' => 'Acme Corp',
        ]);

        $record = $importer->resolveRecord();

        expect($record->exists)->toBeFalse();
    });

    it('handles multiple emails and matches on any of them', function (): void {
        $existingPerson = People::factory()->for($this->team, 'team')->create([
            'name' => 'Multi Email Person',
            'company_id' => $this->company->id,
        ]);

        // Person has two emails
        CustomFieldValue::withoutGlobalScopes()->create([
            'entity_type' => 'people',
            'entity_id' => $existingPerson->id,
            'custom_field_id' => $this->emailsField->id,
            'tenant_id' => $this->team->id,
            'json_value' => ['primary@acme.com', 'secondary@acme.com'], // Don't JSON encode
        ]);

        $import = createPeopleTestImportRecord($this->user, $this->team);
        $importer = new PeopleImporter(
            $import,
            ['name' => 'name', 'company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails'],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        // Import data has only the secondary email
        setPeopleImporterData($importer, [
            'name' => 'Updated Name',
            'company_name' => 'Acme Corp',
            'custom_fields_emails' => 'secondary@acme.com',
        ]);

        $record = $importer->resolveRecord();

        expect($record->id)->toBe($existingPerson->id)
            ->and($record->exists)->toBeTrue();
    });

    it('does not match people from other teams', function (): void {
        $otherTeam = Team::factory()->create();
        $otherCompany = Company::factory()->for($otherTeam, 'team')->create(['name' => 'Other Corp']);

        // Create emails field for other team
        $otherEmailsField = CustomField::withoutGlobalScopes()->create([
            'code' => 'emails',
            'name' => 'Emails',
            'type' => 'email',
            'entity_type' => 'people',
            'tenant_id' => $otherTeam->id,
            'sort_order' => 1,
            'active' => true,
            'system_defined' => true,
        ]);

        $otherPerson = People::factory()->for($otherTeam, 'team')->create([
            'name' => 'Other Team Person',
            'company_id' => $otherCompany->id,
        ]);
        setPersonEmail($otherPerson, 'shared@email.com', $otherEmailsField);

        $import = createPeopleTestImportRecord($this->user, $this->team);
        $importer = new PeopleImporter(
            $import,
            ['name' => 'name', 'company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails'],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        setPeopleImporterData($importer, [
            'name' => 'My Team Person',
            'company_name' => 'Acme Corp',
            'custom_fields_emails' => 'shared@email.com',
        ]);

        $record = $importer->resolveRecord();

        // Should create new record, not match other team's person
        expect($record->exists)->toBeFalse();
    });
});
