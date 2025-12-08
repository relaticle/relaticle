<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Import;

use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\ImportWizard\Data\CompanyMatchResult;
use Relaticle\ImportWizard\Services\CompanyMatcher;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->id);

    // Create the domain_name custom field for Company
    // Uses tenant_id per custom-fields config
    $this->domainNameField = CustomField::create([
        'code' => 'domain_name',
        'name' => 'Domain Name',
        'type' => 'text',
        'entity_type' => Company::class,
        'tenant_id' => $this->team->id,
        'sort_order' => 1,
        'active' => true,
        'system_defined' => true,
    ]);

    $this->matcher = app(CompanyMatcher::class);

    // Helper to set domain_name custom field value directly
    $this->setCompanyDomain = function (Company $company, string $domain, ?Team $team = null): void {
        $team = $team ?? $this->team;
        $field = CustomField::withoutGlobalScopes()
            ->where('code', 'domain_name')
            ->where('entity_type', Company::class)
            ->where('tenant_id', $team->id)
            ->first();

        CustomFieldValue::withoutGlobalScopes()->create([
            'entity_type' => Company::class,
            'entity_id' => $company->id,
            'custom_field_id' => $field->id,
            'tenant_id' => $team->id,
            'string_value' => $domain,
        ]);
    };
});

test('matches company by domain from email', function () {
    $company = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);
    ($this->setCompanyDomain)($company, 'acme.com');

    $result = $this->matcher->match('Acme Inc', ['john@acme.com'], $this->team->id);

    expect($result)
        ->toBeInstanceOf(CompanyMatchResult::class)
        ->companyName->toBe('Acme Inc')
        ->matchType->toBe('domain')
        ->matchCount->toBe(1)
        ->companyId->toBe($company->id);
});

test('matches company by exact name when no domain match', function () {
    $company = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);

    $result = $this->matcher->match('Acme Inc', [], $this->team->id);

    expect($result)
        ->companyName->toBe('Acme Inc')
        ->matchType->toBe('name')
        ->matchCount->toBe(1)
        ->companyId->toBe($company->id);
});

test('returns ambiguous when multiple companies match by name', function () {
    Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);
    Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);

    $result = $this->matcher->match('Acme Inc', [], $this->team->id);

    expect($result)
        ->companyName->toBe('Acme Inc')
        ->matchType->toBe('ambiguous')
        ->matchCount->toBe(2)
        ->companyId->toBeNull();
});

test('returns ambiguous when multiple companies match by domain', function () {
    $company1 = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);
    $company1->saveCustomFieldValue($this->domainNameField, 'acme.com', $this->team);

    $company2 = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Corp']);
    $company2->saveCustomFieldValue($this->domainNameField, 'acme.com', $this->team);

    $result = $this->matcher->match('Something Else', ['john@acme.com'], $this->team->id);

    expect($result)
        ->companyName->toBe('Something Else')
        ->matchType->toBe('ambiguous')
        ->matchCount->toBe(2)
        ->companyId->toBeNull();
});

test('prioritizes domain match over name match', function () {
    // Company that matches by name only
    Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);

    // Company that matches by domain but has different name
    $domainCompany = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Corporation']);
    $domainCompany->saveCustomFieldValue($this->domainNameField, 'acme.com', $this->team);

    $result = $this->matcher->match('Acme Inc', ['john@acme.com'], $this->team->id);

    // Should match by domain, not name
    expect($result)
        ->companyName->toBe('Acme Corporation')
        ->matchType->toBe('domain')
        ->matchCount->toBe(1)
        ->companyId->toBe($domainCompany->id);
});

test('does not match companies from other teams', function () {
    $otherTeam = Team::factory()->create();

    // Create company in other team
    $otherCompany = Company::factory()->for($otherTeam, 'team')->create(['name' => 'Acme Inc']);

    // Create domain_name field for the other team
    $otherDomainField = CustomField::create([
        'code' => 'domain_name',
        'name' => 'Domain Name',
        'type' => 'text',
        'entity_type' => Company::class,
        'tenant_id' => $otherTeam->id,
        'sort_order' => 1,
        'active' => true,
        'system_defined' => true,
    ]);
    $otherCompany->saveCustomFieldValue($otherDomainField, 'acme.com', $otherTeam);

    $result = $this->matcher->match('Acme Inc', ['john@acme.com'], $this->team->id);

    // Should not find the other team's company
    expect($result)
        ->matchType->toBe('new')
        ->matchCount->toBe(0)
        ->companyId->toBeNull();
});

test('handles personal emails by falling back to name match', function () {
    // Gmail domain won't match any company (no company has domain_name=gmail.com)
    $company = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);

    $result = $this->matcher->match('Acme Inc', ['john@gmail.com'], $this->team->id);

    // Should fall back to name matching since gmail.com doesn't match any company
    expect($result)
        ->companyName->toBe('Acme Inc')
        ->matchType->toBe('name')
        ->matchCount->toBe(1)
        ->companyId->toBe($company->id);
});

test('returns new when no company matches', function () {
    $result = $this->matcher->match('Unknown Company', ['contact@unknown.com'], $this->team->id);

    expect($result)
        ->companyName->toBe('Unknown Company')
        ->matchType->toBe('new')
        ->matchCount->toBe(0)
        ->companyId->toBeNull();
});

test('returns new for empty company name', function () {
    $result = $this->matcher->match('', ['john@acme.com'], $this->team->id);

    expect($result)
        ->companyName->toBe('')
        ->matchType->toBe('new')
        ->matchCount->toBe(0)
        ->companyId->toBeNull();
});

test('extracts domain correctly from multiple emails', function () {
    $company = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);
    $company->saveCustomFieldValue($this->domainNameField, 'acme.com', $this->team);

    // Multiple emails, one with the matching domain
    $result = $this->matcher->match('Some Company', ['john@gmail.com', 'jane@acme.com', 'bob@hotmail.com'], $this->team->id);

    expect($result)
        ->companyName->toBe('Acme Inc')
        ->matchType->toBe('domain')
        ->companyId->toBe($company->id);
});

test('handles invalid email formats gracefully', function () {
    $company = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);

    $result = $this->matcher->match('Acme Inc', ['not-an-email', 'also.not.valid', ''], $this->team->id);

    // Should fall back to name matching since no valid domains extracted
    expect($result)
        ->companyName->toBe('Acme Inc')
        ->matchType->toBe('name')
        ->matchCount->toBe(1);
});

test('normalizes email domains to lowercase', function () {
    $company = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Inc']);
    $company->saveCustomFieldValue($this->domainNameField, 'acme.com', $this->team);

    // Email with uppercase domain
    $result = $this->matcher->match('Acme Inc', ['JOHN@ACME.COM'], $this->team->id);

    expect($result)
        ->matchType->toBe('domain')
        ->companyId->toBe($company->id);
});

test('CompanyMatchResult helper methods work correctly', function () {
    $newResult = new CompanyMatchResult(companyName: 'Test', matchType: 'new', matchCount: 0);
    expect($newResult->isNew())->toBeTrue()
        ->and($newResult->isAmbiguous())->toBeFalse()
        ->and($newResult->isDomainMatch())->toBeFalse()
        ->and($newResult->isNameMatch())->toBeFalse();

    $domainResult = new CompanyMatchResult(companyName: 'Test', matchType: 'domain', matchCount: 1, companyId: 1);
    expect($domainResult->isDomainMatch())->toBeTrue()
        ->and($domainResult->isNew())->toBeFalse();

    $nameResult = new CompanyMatchResult(companyName: 'Test', matchType: 'name', matchCount: 1, companyId: 1);
    expect($nameResult->isNameMatch())->toBeTrue()
        ->and($nameResult->isDomainMatch())->toBeFalse();

    $ambiguousResult = new CompanyMatchResult(companyName: 'Test', matchType: 'ambiguous', matchCount: 3);
    expect($ambiguousResult->isAmbiguous())->toBeTrue()
        ->and($ambiguousResult->isNew())->toBeFalse();
});
