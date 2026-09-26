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
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

mutates(FetchFaviconForCompany::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
});

test('job declares timeout, tries, uniqueFor consistent with horizon worker timeout', function (): void {
    $job = new FetchFaviconForCompany(Company::factory()->for($this->user->currentWorkspace)->create());

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(30)
        ->and($job->uniqueFor)->toBe(600);
});

test('job swallows throwable from favicon driver instead of letting it escape', function (): void {
    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    $domainsField = CustomField::query()
        ->where('code', CompanyField::DOMAINS->value)
        ->forEntity(Company::class)
        ->firstOrFail();

    CustomFieldValue::forceCreate([
        'tenant_id' => $this->user->currentWorkspace->getKey(),
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
    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    $domainsField = CustomField::query()
        ->where('code', CompanyField::DOMAINS->value)
        ->forEntity(Company::class)
        ->firstOrFail();

    CustomFieldValue::forceCreate([
        'tenant_id' => $this->user->currentWorkspace->getKey(),
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

    expect($company->fresh()->getMedia('logo'))->toBeEmpty();
});

test('downloads the favicon through the guarded client and stores it', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    $domainsField = CustomField::query()
        ->where('code', CompanyField::DOMAINS->value)
        ->forEntity(Company::class)
        ->firstOrFail();

    CustomFieldValue::forceCreate([
        'tenant_id' => $this->user->currentWorkspace->getKey(),
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

test('downloads the favicon when the company carries several custom field values', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    foreach ([CompanyField::DOMAINS->value => ['example.com'], CompanyField::LINKEDIN->value => 'www.linkedin.com/company/example'] as $code => $value) {
        CustomFieldValue::forceCreate([
            'tenant_id' => $this->user->currentWorkspace->getKey(),
            'entity_type' => 'company',
            'entity_id' => $company->getKey(),
            'custom_field_id' => CustomField::query()->where('code', $code)->forEntity(Company::class)->firstOrFail()->getKey(),
            'json_value' => $value,
        ]);
    }

    $favicon = Mockery::mock(AshAllenDesign\FaviconFetcher\Favicon::class);
    $favicon->shouldReceive('getFaviconUrl')->andReturn('https://1.1.1.1/favicon.png');
    $favicon->shouldReceive('getIconSize')->andReturn(180);
    $favicon->shouldReceive('getIconType')->andReturn('apple-touch-icon');

    Favicon::shouldReceive('driver->fetch')->andReturn($favicon);

    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    Http::fake(['https://1.1.1.1/favicon.png' => Http::response($pngBytes, 200)]);

    (new FetchFaviconForCompany($company->fresh()))->handle();

    expect($company->fresh()->getMedia('logo'))->toHaveCount(1);
});

test('refuses a favicon whose body is not a raster image', function (string $url, string $body): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    CustomFieldValue::forceCreate([
        'tenant_id' => $this->user->currentWorkspace->getKey(),
        'entity_type' => 'company',
        'entity_id' => $company->getKey(),
        'custom_field_id' => CustomField::query()->where('code', CompanyField::DOMAINS->value)->forEntity(Company::class)->firstOrFail()->getKey(),
        'json_value' => ['example.com'],
    ]);

    $favicon = Mockery::mock(AshAllenDesign\FaviconFetcher\Favicon::class);
    $favicon->shouldReceive('getFaviconUrl')->andReturn($url);
    $favicon->shouldReceive('getIconSize')->andReturn(180);
    $favicon->shouldReceive('getIconType')->andReturn('icon');

    Favicon::shouldReceive('driver->fetch')->andReturn($favicon);

    Http::fake([$url => Http::response($body, 200)]);

    (new FetchFaviconForCompany($company->fresh()))->handle();

    expect($company->fresh()->getMedia('logo'))->toBeEmpty();
})->with([
    'svg with script' => ['https://1.1.1.1/favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>'],
    'html page named png' => ['https://1.1.1.1/favicon.png', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'],
]);

test('names the stored logo after its content rather than its url', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    CustomFieldValue::forceCreate([
        'tenant_id' => $this->user->currentWorkspace->getKey(),
        'entity_type' => 'company',
        'entity_id' => $company->getKey(),
        'custom_field_id' => CustomField::query()->where('code', CompanyField::DOMAINS->value)->forEntity(Company::class)->firstOrFail()->getKey(),
        'json_value' => ['example.com'],
    ]);

    $favicon = Mockery::mock(AshAllenDesign\FaviconFetcher\Favicon::class);
    $favicon->shouldReceive('getFaviconUrl')->andReturn('https://1.1.1.1/favicon.svg');
    $favicon->shouldReceive('getIconSize')->andReturn(180);
    $favicon->shouldReceive('getIconType')->andReturn('icon');

    Favicon::shouldReceive('driver->fetch')->andReturn($favicon);

    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    Http::fake(['https://1.1.1.1/favicon.svg' => Http::response($pngBytes, 200)]);

    (new FetchFaviconForCompany($company->fresh()))->handle();

    expect($company->fresh()->getFirstMedia('logo')?->file_name)->toBe('logo.png');
});

test('the company logo collection refuses svg content from any writer', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    expect(fn () => $company->addMediaFromString('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')
        ->usingFileName('logo.svg')
        ->toMediaCollection(Company::LOGO_MEDIA_COLLECTION))
        ->toThrow(FileUnacceptableForCollection::class);
});
