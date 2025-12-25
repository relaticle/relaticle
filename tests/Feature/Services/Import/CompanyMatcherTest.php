<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Import;

use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // Create the domains custom field for Company (without section - query uses withoutGlobalScopes)
    // Uses 'company' morph alias (not Company::class) to match Laravel's morph map
    CustomField::withoutGlobalScopes()->create([
        'code' => CompanyField::DOMAINS->value,
        'name' => CompanyField::DOMAINS->getDisplayName(),
        'type' => 'link',
        'entity_type' => 'company',
        'tenant_id' => $this->team->id,
        'sort_order' => 1,
        'active' => true,
        'system_defined' => true,
    ]);

    $this->matcher = app(CompanyMatcher::class);

    // Helper to set domains custom field value directly (stored as json_value array)
    // Uses 'company' morph alias (not Company::class) to match Laravel's morph map
    $this->setCompanyDomain = function (Company $company, string $domain, ?Team $team = null): void {
        $team = $team ?? $this->team;
        $field = CustomField::withoutGlobalScopes()
            ->where('code', CompanyField::DOMAINS->value)
            ->where('entity_type', 'company')
            ->where('tenant_id', $team->id)
            ->first();

        CustomFieldValue::withoutGlobalScopes()->create([
            'entity_type' => 'company',
            'entity_id' => $company->id,
            'custom_field_id' => $field->id,
            'tenant_id' => $team->id,
            'json_value' => [$domain],
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
    ($this->setCompanyDomain)($company1, 'acme.com');

    $company2 = Company::factory()->for($this->team, 'team')->create(['name' => 'Acme Corp']);
    ($this->setCompanyDomain)($company2, 'acme.com');

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
    ($this->setCompanyDomain)($domainCompany, 'acme.com');

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

    // Create domains field for the other team (without section - query uses withoutGlobalScopes)
    // Uses 'company' morph alias (not Company::class) to match Laravel's morph map
    CustomField::withoutGlobalScopes()->create([
        'code' => CompanyField::DOMAINS->value,
        'name' => CompanyField::DOMAINS->getDisplayName(),
        'type' => 'link',
        'entity_type' => 'company',
        'tenant_id' => $otherTeam->id,
        'sort_order' => 1,
        'active' => true,
        'system_defined' => true,
    ]);
    ($this->setCompanyDomain)($otherCompany, 'acme.com', $otherTeam);

    $result = $this->matcher->match('Acme Inc', ['john@acme.com'], $this->team->id);

    // Should not find the other team's company
    expect($result)
        ->matchType->toBe('new')
        ->matchCount->toBe(0)
        ->companyId->toBeNull();
});

test('handles personal emails by falling back to name match', function () {
    // Gmail domain won't match any company (no company has domains containing gmail.com)
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
    ($this->setCompanyDomain)($company, 'acme.com');

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
    ($this->setCompanyDomain)($company, 'acme.com');

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

    $domainResult = new CompanyMatchResult(companyName: 'Test', matchType: 'domain', matchCount: 1, companyId: '01kccnz9t2x1r00369k6wm6wk2');
    expect($domainResult->isDomainMatch())->toBeTrue()
        ->and($domainResult->isNew())->toBeFalse();

    $nameResult = new CompanyMatchResult(companyName: 'Test', matchType: 'name', matchCount: 1, companyId: '01kccnz9t2x1r00369k6wm6wk2');
    expect($nameResult->isNameMatch())->toBeTrue()
        ->and($nameResult->isDomainMatch())->toBeFalse();

    $ambiguousResult = new CompanyMatchResult(companyName: 'Test', matchType: 'ambiguous', matchCount: 3);
    expect($ambiguousResult->isAmbiguous())->toBeTrue()
        ->and($ambiguousResult->isNew())->toBeFalse();
});
