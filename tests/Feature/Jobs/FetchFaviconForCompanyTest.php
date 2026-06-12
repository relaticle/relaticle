<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Jobs\FetchFaviconForCompany;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use AshAllenDesign\FaviconFetcher\Facades\Favicon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

mutates(FetchFaviconForCompany::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

test('job declares timeout, tries, uniqueFor consistent with horizon worker timeout', function (): void {
    $job = new FetchFaviconForCompany(Company::factory()->for($this->user->currentTeam)->create());

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(30)
        ->and($job->uniqueFor)->toBe(600);
});

test('job swallows throwable from favicon driver instead of letting it escape', function (): void {
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

    Favicon::shouldReceive('driver')
        ->once()
        ->andThrow(new TypeError('simulated php error inside favicon driver'));

    // If the job's catch were `catch (Exception)`, the TypeError would escape this call.
    // The test passes simply by not throwing.
    (new FetchFaviconForCompany($company->fresh()))->handle();

    expect(true)->toBeTrue();
});

test('job rejects favicon url that resolves to private address', function (): void {
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

    $favicon = Mockery::mock(AshAllenDesign\FaviconFetcher\Favicon::class);
    $favicon->shouldReceive('getFaviconUrl')->andReturn('http://127.0.0.1/favicon.png');
    $favicon->shouldReceive('getIconSize')->andReturn(180);
    $favicon->shouldReceive('getIconType')->andReturn('apple-touch-icon');

    Favicon::shouldReceive('driver->fetch')->andReturn($favicon);

    (new FetchFaviconForCompany($company->fresh()))->handle();

    expect($company->fresh()->getMedia('logo'))->toHaveCount(0);
});

test('downloads the favicon through the guarded client and stores it', function (): void {
    Storage::fake('public');

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

    $favicon = Mockery::mock(AshAllenDesign\FaviconFetcher\Favicon::class);
    $favicon->shouldReceive('getFaviconUrl')->andReturn('https://1.1.1.1/favicon.png');
    $favicon->shouldReceive('getIconSize')->andReturn(180);
    $favicon->shouldReceive('getIconType')->andReturn('apple-touch-icon');

    Favicon::shouldReceive('driver->fetch')->andReturn($favicon);

    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    Http::fake(['https://1.1.1.1/favicon.png' => Http::response($pngBytes, 200)]);

    (new FetchFaviconForCompany($company->fresh()))->handle();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://1.1.1.1/favicon.png');
    expect($company->fresh()->getMedia('logo'))->toHaveCount(1);
});
