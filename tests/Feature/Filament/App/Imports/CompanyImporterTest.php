<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use App\Models\Team;
use Illuminate\Support\Facades\Storage;
use Relaticle\ImportWizard\Data\CompanyMatchResult;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Enums\MatchType;
use Relaticle\ImportWizard\Filament\Imports\CompanyImporter;
use Relaticle\ImportWizard\Support\CompanyMatcher;

beforeEach(function (): void {
    Storage::fake('local');
    ['user' => $this->user, 'team' => $this->team] = setupImportTestContext();
    $this->domainField = createDomainsField($this->team);
    $this->domainsKey = 'custom_fields_'.CompanyField::DOMAINS->value;
    $this->matcher = app(CompanyMatcher::class);
});

describe('Domain-Based Duplicate Detection', function (): void {
    it(':dataset with UPDATE strategy', function (string $scenario, array $setup, array $importData, string $expectation): void {
        $company = Company::factory()->for($this->team)->create(['name' => 'Acme Inc']);
        setCompanyDomain($company, 'acme.com', $this->domainField);

        if ($scenario === 'domain priority') {
            Company::factory()->for($this->team)->create(['name' => 'Acme Inc']);
        }

        $importer = createImporter(
            CompanyImporter::class,
            $this->user,
            $this->team,
            ['name' => 'name', $this->domainsKey => $this->domainsKey],
            $importData,
            ['duplicate_handling' => DuplicateHandlingStrategy::UPDATE]
        );

        $result = $importer->resolveRecord();

        match ($expectation) {
            'matches' => expect($result)->id->toBe($company->id)->exists->toBeTrue(),
            'creates_new' => expect($result->exists)->toBeFalse(),
        };
    })->with([
        'matches by domain' => ['match', [], ['name' => 'Different Name', 'custom_fields_domains' => 'acme.com'], 'matches'],
        'prioritizes domain over name' => ['domain priority', [], ['name' => 'Acme Inc', 'custom_fields_domains' => 'acme.com'], 'matches'],
        'normalizes domain to lowercase' => ['match', [], ['name' => 'Acme Inc', 'custom_fields_domains' => 'ACME.COM'], 'matches'],
        'creates new when no domain match' => ['no match', [], ['name' => 'New Company', 'custom_fields_domains' => 'newcompany.com'], 'creates_new'],
    ]);

    it('respects CREATE_NEW strategy even with domain match', function (): void {
        $company = Company::factory()->for($this->team)->create(['name' => 'Acme Inc']);
        setCompanyDomain($company, 'acme.com', $this->domainField);

        $importer = createImporter(
            CompanyImporter::class,
            $this->user,
            $this->team,
            ['name' => 'name', $this->domainsKey => $this->domainsKey],
            ['name' => 'Acme Inc', $this->domainsKey => 'acme.com'],
            ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW]
        );

        expect($importer->resolveRecord()->exists)->toBeFalse();
    });
});

describe('CompanyMatcher Service', function (): void {
    it('matches by :dataset', function (string $matchType, ?string $companyId, string $companyName, array $emails, MatchType $expectedType): void {
        $company = Company::factory()->for($this->team)->create(['name' => 'Acme Inc']);
        setCompanyDomain($company, 'acme.com', $this->domainField);

        $id = match ($companyId) {
            'valid' => $company->id,
            'invalid' => 'not-a-valid-ulid',
            'notfound' => '01KCCNZ9T2X1R00369K6WM6WK2',
            default => '',
        };

        $result = $this->matcher->match($id, $companyName, $emails, $this->team->id);

        expect($result)->toBeInstanceOf(CompanyMatchResult::class)
            ->matchType->toBe($expectedType);

        if ($expectedType !== MatchType::New && $expectedType !== MatchType::None) {
            expect($result->companyId)->toBe($company->id);
        }
    })->with([
        'ID with highest priority' => ['id', 'valid', 'Different Name', [], MatchType::Id],
        'domain from email' => ['domain', '', 'Acme Inc', ['john@acme.com'], MatchType::Domain],
        'domain over company name' => ['domain', '', 'Acme Inc', ['john@acme.com'], MatchType::Domain],
        'multiple emails' => ['domain', '', 'Some Company', ['john@gmail.com', 'jane@acme.com'], MatchType::Domain],
        'uppercase domain normalization' => ['domain', '', 'Acme Inc', ['JOHN@ACME.COM'], MatchType::Domain],
        'invalid ID falls back to domain' => ['domain', 'invalid', 'Some Name', ['john@acme.com'], MatchType::Domain],
    ]);

    it('returns :dataset match result', function (string $scenario, string $companyName, array $emails, MatchType $expectedType): void {
        if ($scenario === 'with company') {
            Company::factory()->for($this->team)->create(['name' => 'Acme Inc']);
        }

        $result = $this->matcher->match('', $companyName, $emails, $this->team->id);

        expect($result)
            ->matchType->toBe($expectedType)
            ->companyId->toBeNull();
    })->with([
        'new when company name without domain match' => ['with company', 'Acme Inc', [], MatchType::New],
        'new for personal emails' => ['with company', 'Acme Inc', ['john@gmail.com'], MatchType::New],
        'new when no match exists' => ['empty', 'Unknown Company', ['contact@unknown.com'], MatchType::New],
        'new when ID not found' => ['empty', 'New Company', [], MatchType::New],
        'none for empty company name' => ['empty', '', ['john@acme.com'], MatchType::None],
    ]);

    it('handles invalid emails gracefully', function (): void {
        Company::factory()->for($this->team)->create(['name' => 'Acme Inc']);

        $result = $this->matcher->match('', 'Acme Inc', ['not-an-email', 'also.not.valid', ''], $this->team->id);

        expect($result)->matchType->toBe(MatchType::New)->matchCount->toBe(0);
    });

    it('takes first match when multiple companies share domain', function (): void {
        $company1 = Company::factory()->for($this->team)->create(['name' => 'Acme Inc']);
        setCompanyDomain($company1, 'acme.com', $this->domainField);
        $company2 = Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);
        setCompanyDomain($company2, 'acme.com', $this->domainField);

        $result = $this->matcher->match('', 'Something Else', ['john@acme.com'], $this->team->id);

        expect($result)
            ->matchType->toBe(MatchType::Domain)
            ->matchCount->toBe(2)
            ->companyId->toBeIn([$company1->id, $company2->id]);
    });
});

describe('Team Isolation', function (): void {
    it('does not match :dataset from other teams', function (string $matchType): void {
        $otherTeam = Team::factory()->create();
        $otherCompany = Company::factory()->for($otherTeam)->create(['name' => 'Acme Inc']);

        if ($matchType === 'domain') {
            createDomainsField($otherTeam);
            setCompanyDomain($otherCompany, 'acme.com', createDomainsField($otherTeam));
        }

        $result = $this->matcher->match(
            $matchType === 'id' ? $otherCompany->id : '',
            'Acme Inc',
            $matchType === 'domain' ? ['john@acme.com'] : [],
            $this->team->id
        );

        expect($result)->matchType->toBe(MatchType::New)->companyId->toBeNull();
    })->with(['id', 'domain']);
});

describe('CompanyMatchResult', function (): void {
    it('isDomainMatch returns correct value for :dataset', function (MatchType $type, bool $expected): void {
        $result = new CompanyMatchResult(
            companyName: 'Test',
            matchType: $type,
            matchCount: $type === MatchType::Domain ? 1 : 0,
            companyId: $type === MatchType::Domain ? '01kccnz9t2x1r00369k6wm6wk2' : null
        );

        expect($result->isDomainMatch())->toBe($expected);
    })->with([
        'domain match' => [MatchType::Domain, true],
        'new' => [MatchType::New, false],
        'id' => [MatchType::Id, false],
        'none' => [MatchType::None, false],
    ]);
});
