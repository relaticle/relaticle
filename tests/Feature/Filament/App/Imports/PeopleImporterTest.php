<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\Team;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;

beforeEach(function (): void {
    ['user' => $this->user, 'team' => $this->team] = setupImportTestContext();
    $this->company = Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);
    $this->emailsField = createEmailsCustomField($this->team);
});

describe('Email-Based Duplicate Detection', function (): void {
    it('finds existing person by email with :dataset strategy', function (DuplicateHandlingStrategy $strategy): void {
        $person = People::factory()->for($this->team)->create(['name' => 'John Doe', 'company_id' => $this->company->id]);
        setPersonEmail($person, 'john@acme.com', $this->emailsField);

        $importer = createImporter(
            PeopleImporter::class,
            $this->user,
            $this->team,
            ['name' => 'name', 'company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails'],
            ['name' => 'John Updated', 'company_name' => 'Acme Corp', 'custom_fields_emails' => 'john@acme.com'],
            ['duplicate_handling' => $strategy]
        );

        expect($importer->resolveRecord())->id->toBe($person->id)->exists->toBeTrue();
    })->with([DuplicateHandlingStrategy::UPDATE, DuplicateHandlingStrategy::SKIP]);

    it(':dataset creates new person', function (string $scenario, array $data): void {
        $columnMap = ['name' => 'name', 'company_name' => 'company_name'];
        if (isset($data['custom_fields_emails'])) {
            $columnMap['custom_fields_emails'] = 'custom_fields_emails';
        }

        $import = createImportRecord($this->user, $this->team, PeopleImporter::class);
        $importer = new PeopleImporter($import, $columnMap, ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]);
        setImporterData($importer, $data);

        expect($importer->resolveRecord()->exists)->toBeFalse();
    })->with([
        'email not matching' => ['mismatch', ['name' => 'New Person', 'company_name' => 'Acme Corp', 'custom_fields_emails' => 'newperson@acme.com']],
        'no email provided' => ['no email', ['name' => 'Person Without Email', 'company_name' => 'Acme Corp']],
    ]);

    it('creates new with CREATE_NEW even with matching email', function (): void {
        $person = People::factory()->for($this->team)->create(['name' => 'Bob Johnson', 'company_id' => $this->company->id]);
        setPersonEmail($person, 'bob@acme.com', $this->emailsField);

        $importer = createImporter(
            PeopleImporter::class,
            $this->user,
            $this->team,
            ['name' => 'name', 'company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails'],
            ['name' => 'Bob Duplicate', 'company_name' => 'Acme Corp', 'custom_fields_emails' => 'bob@acme.com'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        expect($importer->resolveRecord()->exists)->toBeFalse();
    });

    it('matches on any of multiple emails', function (): void {
        $person = People::factory()->for($this->team)->create(['name' => 'Multi Email Person', 'company_id' => $this->company->id]);
        setPersonEmail($person, ['primary@acme.com', 'secondary@acme.com'], $this->emailsField);

        $importer = createImporter(
            PeopleImporter::class,
            $this->user,
            $this->team,
            ['name' => 'name', 'company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails'],
            ['name' => 'Updated Name', 'company_name' => 'Acme Corp', 'custom_fields_emails' => 'secondary@acme.com'],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        expect($importer->resolveRecord())->id->toBe($person->id)->exists->toBeTrue();
    });

    it('does not match people from other teams', function (): void {
        $otherTeam = Team::factory()->create();
        $otherCompany = Company::factory()->for($otherTeam)->create(['name' => 'Other Corp']);
        $otherEmailsField = createEmailsCustomField($otherTeam);
        $otherPerson = People::factory()->for($otherTeam)->create(['name' => 'Other Team Person', 'company_id' => $otherCompany->id]);
        setPersonEmail($otherPerson, 'shared@email.com', $otherEmailsField);

        $importer = createImporter(
            PeopleImporter::class,
            $this->user,
            $this->team,
            ['name' => 'name', 'company_name' => 'company_name', 'custom_fields_emails' => 'custom_fields_emails'],
            ['name' => 'My Team Person', 'company_name' => 'Acme Corp', 'custom_fields_emails' => 'shared@email.com'],
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        expect($importer->resolveRecord()->exists)->toBeFalse();
    });
});

describe('Email Domain → Company Auto-Linking', function (): void {
    beforeEach(function (): void {
        $this->domainField = createDomainsField($this->team);
        setCompanyDomain($this->company, 'acme.com', $this->domainField);
    });

    it(':dataset company linking behavior', function (string $scenario, array $data, ?string $expectedCompanyName): void {
        if ($scenario === 'explicit company') {
            Company::factory()->for($this->team)->create(['name' => 'Different Corp']);
        }

        $columnMap = ['name' => 'name', 'company_name' => 'company_name'];
        if (isset($data['custom_fields_emails'])) {
            $columnMap['custom_fields_emails'] = 'custom_fields_emails';
        }

        $import = createImportRecord($this->user, $this->team, PeopleImporter::class);
        $importer = new PeopleImporter($import, $columnMap, ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]);
        setImporterData($importer, $data);
        ($importer)($data);

        $person = People::where('name', $data['name'])->first();
        expect($person)->not->toBeNull();

        if ($expectedCompanyName === null) {
            expect($person->company_id)->toBeNull();
        } else {
            $expectedCompany = Company::where('name', $expectedCompanyName)->first();
            expect($person->company_id)->toBe($expectedCompany?->id);
        }
    })->with([
        'auto-links by email domain' => ['domain match', ['name' => 'John Doe', 'company_name' => '', 'custom_fields_emails' => 'john@acme.com'], 'Acme Corp'],
        'prefers explicit company_name' => ['explicit company', ['name' => 'John Doe', 'company_name' => 'Different Corp', 'custom_fields_emails' => 'john@acme.com'], 'Different Corp'],
        'creates new company when no match' => ['new company', ['name' => 'John Doe', 'company_name' => 'Brand New Company'], 'Brand New Company'],
        'leaves null when no company_name and no domain' => ['no match', ['name' => 'John Doe', 'company_name' => '', 'custom_fields_emails' => 'john@unknown-company.com'], null],
    ]);
});
