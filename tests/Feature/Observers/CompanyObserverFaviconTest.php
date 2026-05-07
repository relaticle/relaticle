<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Jobs\FetchFaviconForCompany;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Observers\CompanyObserver;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;

mutates(CompanyObserver::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

test('observer dispatches favicon job when company has domain and no existing logo', function (): void {
    Bus::fake([FetchFaviconForCompany::class]);

    $company = Company::factory()->for($this->user->currentTeam)->create();

    $domainsField = CustomField::query()
        ->where('code', CompanyField::DOMAINS->value)
        ->forEntity(Company::class)
        ->firstOrFail();

    CustomFieldValue::forceCreate([
        'tenant_id' => $this->user->currentTeam->getKey(),
        'entity_type' => 'company',
        'entity_id' => $company->getKey(),
        'custom_field_id' => $domainsField->getKey(),
        'json_value' => ['example.com'],
    ]);

    $company->touch();

    Bus::assertDispatched(FetchFaviconForCompany::class);
});

test('observer does not dispatch favicon job when company already has a logo', function (): void {
    $company = Company::factory()->for($this->user->currentTeam)->create();

    $domainsField = CustomField::query()
        ->where('code', CompanyField::DOMAINS->value)
        ->forEntity(Company::class)
        ->firstOrFail();

    CustomFieldValue::forceCreate([
        'tenant_id' => $this->user->currentTeam->getKey(),
        'entity_type' => 'company',
        'entity_id' => $company->getKey(),
        'custom_field_id' => $domainsField->getKey(),
        'json_value' => ['example.com'],
    ]);

    $company->addMediaFromString('fake-png-bytes')
        ->usingFileName('logo.png')
        ->toMediaCollection('logo');

    Bus::fake([FetchFaviconForCompany::class]);
    $company->touch();
    Bus::assertNotDispatched(FetchFaviconForCompany::class);
});

test('observer does not dispatch when domain custom field is empty', function (): void {
    Bus::fake([FetchFaviconForCompany::class]);

    Company::factory()->for($this->user->currentTeam)->create();

    Bus::assertNotDispatched(FetchFaviconForCompany::class);
});
