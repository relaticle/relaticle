<?php

declare(strict_types=1);

use App\Actions\People\CreatePeople;
use App\Actions\People\UpdatePeople;
use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\User;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\EmailIntegration\Actions\LinkPersonCompanyFromEmails;

mutates(LinkPersonCompanyFromEmails::class, CreatePeople::class, UpdatePeople::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->personalTeam();
    TenantContextService::setTenantId($this->team->getKey());
    $this->team->update(['auto_create_companies' => true]);
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

it('creates and links a company from a work email on create', function (): void {
    $person = app(CreatePeople::class)->execute($this->user, [
        'name' => 'Jane',
        'custom_fields' => ['emails' => ['jane@acme.com']],
    ], CreationSource::API);

    expect($person->fresh()->company_id)->not->toBeNull();
    expect(Company::query()->where('team_id', $this->team->id)->where('name', 'Acme')->exists())->toBeTrue();
});

it('does not create a company from a public domain alone', function (): void {
    $person = app(CreatePeople::class)->execute($this->user, [
        'name' => 'Jane',
        'custom_fields' => ['emails' => ['jane@gmail.com']],
    ], CreationSource::API);

    expect($person->fresh()->company_id)->toBeNull()
        ->and(Company::query()->where('team_id', $this->team->id)->count())->toBe(0);
});

it('uses the work email when the person also has a public address', function (): void {
    $person = app(CreatePeople::class)->execute($this->user, [
        'name' => 'Jane',
        'custom_fields' => ['emails' => ['jane@gmail.com', 'jane@acme.com']],
    ], CreationSource::API);

    expect(Company::query()->where('team_id', $this->team->id)->where('name', 'Acme')->exists())->toBeTrue();
    expect($person->fresh()->company_id)->not->toBeNull();
});

it('links an existing company when auto_create_companies is false', function (): void {
    $this->team->update(['auto_create_companies' => false]);

    $domainsField = CustomField::query()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->firstOrFail();

    $company = Company::factory()->create(['team_id' => $this->team->id, 'name' => 'Acme']);
    $company->saveCustomFieldValue($domainsField, 'www.acme.com', $this->team);

    $person = app(CreatePeople::class)->execute($this->user, [
        'name' => 'Jane',
        'custom_fields' => ['emails' => ['jane@acme.com']],
    ], CreationSource::API);

    expect($person->fresh()->company_id)->toBe($company->getKey())
        ->and(Company::query()->where('team_id', $this->team->id)->count())->toBe(1);
});

it('does not create a company when the toggle is off and none exists', function (): void {
    $this->team->update(['auto_create_companies' => false]);

    $person = app(CreatePeople::class)->execute($this->user, [
        'name' => 'Jane',
        'custom_fields' => ['emails' => ['jane@newco.com']],
    ], CreationSource::API);

    expect($person->fresh()->company_id)->toBeNull()
        ->and(Company::query()->where('team_id', $this->team->id)->count())->toBe(0);
});

it('does not overwrite an explicit company_id', function (): void {
    $chosen = Company::factory()->create(['team_id' => $this->team->id, 'name' => 'Chosen']);

    $person = app(CreatePeople::class)->execute($this->user, [
        'name' => 'Jane',
        'company_id' => $chosen->getKey(),
        'custom_fields' => ['emails' => ['jane@acme.com']],
    ], CreationSource::API);

    expect($person->fresh()->company_id)->toBe($chosen->getKey());
});

it('links a company when a work email is added later', function (): void {
    $person = app(CreatePeople::class)->execute($this->user, [
        'name' => 'Jane',
    ], CreationSource::API);

    expect($person->company_id)->toBeNull();

    app(UpdatePeople::class)->execute($this->user, $person, [
        'custom_fields' => ['emails' => ['jane@acme.com']],
    ]);

    expect($person->fresh()->company_id)->not->toBeNull();
});
