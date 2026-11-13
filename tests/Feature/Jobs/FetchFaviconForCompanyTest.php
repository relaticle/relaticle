<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Jobs\FetchFaviconForCompany;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Support\Http\HostResolver;
use AshAllenDesign\FaviconFetcher\Exceptions\ConnectionException;
use AshAllenDesign\FaviconFetcher\Facades\Favicon;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Exceptions;
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
    $job = new ReflectionClass(FetchFaviconForCompany::class);

    expect($job->getAttributes(Tries::class)[0]->newInstance()->tries)->toBe(1)
        ->and($job->getAttributes(Timeout::class)[0]->newInstance()->timeout)->toBe(30)
        ->and($job->getAttributes(UniqueFor::class)[0]->newInstance()->uniqueFor)->toBe(600);
});

test('job swallows throwable from favicon driver instead of letting it escape', function (): void {
    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    $domainsField = CustomField::query()
        ->where('code', CompanyField::DOMAINS->value)
        ->forEntity(Company::class)
        ->firstOrFail();

    CustomFieldValue::query()->forceCreate([
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
    new FetchFaviconForCompany($company->fresh())->handle();

    expect(true)->toBeTrue();
});

test('job rejects favicon url that resolves to private address', function (): void {
    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    $domainsField = CustomField::query()
        ->where('code', CompanyField::DOMAINS->value)
        ->forEntity(Company::class)
        ->firstOrFail();

    CustomFieldValue::query()->forceCreate([
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

    new FetchFaviconForCompany($company->fresh())->handle();

    expect($company->fresh()->getMedia('logo'))->toBeEmpty();
});

test('downloads the favicon through the guarded client and stores it', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    $domainsField = CustomField::query()
        ->where('code', CompanyField::DOMAINS->value)
        ->forEntity(Company::class)
        ->firstOrFail();

    CustomFieldValue::query()->forceCreate([
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

    new FetchFaviconForCompany($company->fresh())->handle();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://1.1.1.1/favicon.png');
    expect($company->fresh()->getMedia('logo'))->toHaveCount(1);
});

test('downloads the favicon when the company carries several custom field values', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    foreach ([CompanyField::DOMAINS->value => ['example.com'], CompanyField::LINKEDIN->value => 'www.linkedin.com/company/example'] as $code => $value) {
        CustomFieldValue::query()->forceCreate([
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

    new FetchFaviconForCompany($company->fresh())->handle();

    expect($company->fresh()->getMedia('logo'))->toHaveCount(1);
});

test('refuses a favicon whose body is not a raster image', function (string $url, string $body): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    CustomFieldValue::query()->forceCreate([
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

    new FetchFaviconForCompany($company->fresh())->handle();

    expect($company->fresh()->getMedia('logo'))->toBeEmpty();
})->with([
    'svg with script' => ['https://1.1.1.1/favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>'],
    'html page named png' => ['https://1.1.1.1/favicon.png', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'],
]);

test('names the stored logo after its content rather than its url', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    CustomFieldValue::query()->forceCreate([
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

    new FetchFaviconForCompany($company->fresh())->handle();

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

test('replaces the logo fetched for a previous domain', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['new-domain.com']],
    ]);

    $company->addMediaFromString(onePixelPng())
        ->usingFileName('logo.png')
        ->withCustomProperties(['domain' => 'https://old-domain.com'])
        ->toMediaCollection('logo');

    $favicon = Mockery::mock(AshAllenDesign\FaviconFetcher\Favicon::class);
    $favicon->shouldReceive('getFaviconUrl')->andReturn('https://1.1.1.1/favicon.png');
    $favicon->shouldReceive('getIconSize')->andReturn(180);
    $favicon->shouldReceive('getIconType')->andReturn('apple-touch-icon');

    Favicon::shouldReceive('driver->fetch')->andReturn($favicon);
    Http::fake(['https://1.1.1.1/favicon.png' => Http::response(onePixelPng(), 200)]);

    new FetchFaviconForCompany($company->fresh())->handle();

    $logos = $company->fresh()->getMedia('logo');

    expect($logos)->toHaveCount(1)
        ->and($logos->first()->getCustomProperty('domain'))->toBe('https://new-domain.com');
});

test('drops the logo of a previous domain when the new domain has no favicon', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['new-domain.com']],
    ]);

    $company->addMediaFromString(onePixelPng())
        ->usingFileName('logo.png')
        ->withCustomProperties(['domain' => 'https://old-domain.com'])
        ->toMediaCollection('logo');

    Favicon::shouldReceive('driver->fetch')->andReturn(null);

    new FetchFaviconForCompany($company->fresh())->handle();

    expect($company->fresh()->getMedia('logo'))->toBeEmpty();
});

test('ignores a domain stored against another workspace field', function (): void {
    $otherWorkspace = User::factory()->withWorkspace()->create()->currentWorkspace;

    $foreignDomainsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $otherWorkspace->getKey())
        ->where('entity_type', 'company')
        ->where('code', CompanyField::DOMAINS->value)
        ->firstOrFail();

    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    CustomFieldValue::withoutEvents(function () use ($company, $otherWorkspace, $foreignDomainsField): void {
        $company->customFieldValues()->delete();

        CustomFieldValue::query()->forceCreate([
            'tenant_id' => $otherWorkspace->getKey(),
            'entity_type' => 'company',
            'entity_id' => $company->getKey(),
            'custom_field_id' => $foreignDomainsField->getKey(),
            'json_value' => ['foreign.com'],
        ]);
    });

    Filament::setTenant(null);
    Favicon::shouldReceive('driver')->never();

    new FetchFaviconForCompany($company->fresh())->handle();
});

test('keeps the logo of a previous domain when the fetch for the new domain throws', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['new-domain.com']],
    ]);

    $company->addMediaFromString(onePixelPng())
        ->usingFileName('logo.png')
        ->withCustomProperties(['domain' => 'https://old-domain.com'])
        ->toMediaCollection('logo');

    Favicon::shouldReceive('driver->fetch')->andThrow(new RuntimeException('Could not resolve host'));

    new FetchFaviconForCompany($company->fresh())->handle();

    $logos = $company->fresh()->getMedia('logo');

    expect($logos)->toHaveCount(1)
        ->and($logos->first()->getCustomProperty('domain'))->toBe('https://old-domain.com');
});

test('an unreachable company site is not reported as an error', function (): void {
    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['dead-host.example']],
    ]);

    Favicon::shouldReceive('driver->fetch')
        ->andThrow(new ConnectionException('Could not resolve host for https://dead-host.example'));

    new FetchFaviconForCompany($company->fresh())->handle();

    Exceptions::assertNothingReported();
});

test('a favicon whose host does not resolve is skipped without being reported', function (): void {
    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['example.com']],
    ]);

    $favicon = Mockery::mock(AshAllenDesign\FaviconFetcher\Favicon::class);
    $favicon->shouldReceive('getFaviconUrl')->andReturn('https://icons.dead-host.example/favicon.png');

    Favicon::shouldReceive('driver->fetch')->andReturn($favicon);
    Http::fake();

    new FetchFaviconForCompany($company->fresh())->handle();

    Http::assertNothingSent();
    Exceptions::assertNothingReported();
    expect($company->fresh()->getMedia('logo'))->toBeEmpty();
});

test('resolves each host once per job run', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['slow.example']],
    ]);

    $lookups = 0;
    resolveHostsTo(['93.184.216.34'], $lookups);

    Http::fake([
        'https://slow.example' => Http::response('<link rel="apple-touch-icon" href="/touch.png">'),
        'https://slow.example/touch.png' => Http::response(onePixelPng()),
    ]);

    new FetchFaviconForCompany($company->fresh())->handle();

    expect($company->fresh()->getMedia('logo'))->toHaveCount(1)
        ->and($lookups)->toBe(1);
});

test('stores an icon from another source when the site answers every path with its own page', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['soft-404.example']],
    ]);

    resolveHostsTo(['93.184.216.34']);

    Http::fake([
        'https://www.google.com/*' => Http::response(onePixelPng(), 200, ['Content-Type' => 'image/png']),
        '*' => Http::response('<html><link rel="icon" href="/icon.png"></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
    ]);

    new FetchFaviconForCompany($company->fresh())->handle();

    expect($company->fresh()->getFirstMedia('logo')?->file_name)->toBe('logo.png');

    Http::assertSent(fn (HttpClientRequest $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://www.google.com/s2/favicons?sz=256&domain=soft-404.example');
});

test('stops following redirects once the time budget has passed', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['example.com']],
    ]);

    $favicon = Mockery::mock(AshAllenDesign\FaviconFetcher\Favicon::class);
    $favicon->shouldReceive('getFaviconUrl')->andReturn('https://1.1.1.1/favicon.png');

    Favicon::shouldReceive('driver->fetch')->andReturn($favicon);
    $this->freezeTime();

    $requested = [];

    Http::fake(function (HttpClientRequest $request) use (&$requested) {
        $requested[] = $request->url();
        $this->travel(14)->seconds();

        return Http::response('', 302, ['Location' => 'https://1.1.1.1/hop-'.count($requested).'.png']);
    });

    new FetchFaviconForCompany($company->fresh())->handle();

    expect($requested)->toBe(['https://1.1.1.1/favicon.png', 'https://1.1.1.1/hop-1.png'])
        ->and($company->fresh()->getMedia('logo'))->toBeEmpty();

    Exceptions::assertNothingReported();
});

test('a favicon download that redirects to a host that does not resolve is not reported', function (): void {
    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['example.com']],
    ]);

    $favicon = Mockery::mock(AshAllenDesign\FaviconFetcher\Favicon::class);
    $favicon->shouldReceive('getFaviconUrl')->andReturn('https://icons.example.com/favicon.png');

    Favicon::shouldReceive('driver->fetch')->andReturn($favicon);
    app()->instance(HostResolver::class, new HostResolver(fn (string $host): array => $host === 'icons.example.com' ? ['93.184.216.34'] : []));
    Http::fake(['https://icons.example.com/favicon.png' => Http::response('', 302, ['Location' => 'https://gone.example/favicon.png'])]);

    new FetchFaviconForCompany($company->fresh())->handle();

    Exceptions::assertNothingReported();
    expect($company->fresh()->getMedia('logo'))->toBeEmpty();
});

test('an unexpected failure while fetching a favicon is reported', function (): void {
    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['example.com']],
    ]);

    Favicon::shouldReceive('driver->fetch')->andThrow(new TypeError('broken driver'));

    new FetchFaviconForCompany($company->fresh())->handle();

    Exceptions::assertReported(TypeError::class);
});

test('a company domain that resolves to a private address is skipped without being reported', function (): void {
    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['intranet.example']],
    ]);

    resolveHostsTo(['10.10.0.77']);
    Http::fake();

    new FetchFaviconForCompany($company->fresh())->handle();

    Http::assertNothingSent();
    Exceptions::assertNothingReported();
});

test('a favicon download that times out is not reported', function (): void {
    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['example.com']],
    ]);

    $favicon = Mockery::mock(AshAllenDesign\FaviconFetcher\Favicon::class);
    $favicon->shouldReceive('getFaviconUrl')->andReturn('https://1.1.1.1/favicon.png');

    Favicon::shouldReceive('driver->fetch')->andReturn($favicon);
    Http::fake(['https://1.1.1.1/favicon.png' => fn () => throw new HttpConnectionException('cURL error 28: SSL connection timeout')]);

    new FetchFaviconForCompany($company->fresh())->handle();

    Exceptions::assertNothingReported();
    expect($company->fresh()->getMedia('logo'))->toBeEmpty();
});

test('stops trying further sources once the time budget has passed', function (): void {
    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['slow.example']],
    ]);

    $company->addMediaFromString(onePixelPng())
        ->usingFileName('logo.png')
        ->withCustomProperties(['domain' => 'https://slow.example'])
        ->toMediaCollection('logo');

    resolveHostsTo(['93.184.216.34']);

    $requested = [];

    Http::fake(function (HttpClientRequest $request) use (&$requested) {
        $requested[] = $request->url();
        $this->travel(13)->seconds();

        return Http::response('', 404);
    });

    new FetchFaviconForCompany($company->fresh())->handle();

    expect($requested)->toBe(['https://slow.example', 'https://slow.example'])
        ->and($company->fresh()->getMedia('logo'))->toHaveCount(1);

    Exceptions::assertNothingReported();
});

test('still stores the logo when the first source answers in time', function (): void {
    Storage::fake('public');

    $company = Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => ['slow.example']],
    ]);

    resolveHostsTo(['93.184.216.34']);
    $this->freezeTime();

    $timeouts = [];

    Http::fake(function (HttpClientRequest $request, array $options) use (&$timeouts) {
        $timeouts[] = [$options['timeout'], $options['connect_timeout']];
        $this->travel(9)->seconds();

        return match ($request->url()) {
            'https://slow.example' => Http::response('<link rel="apple-touch-icon" href="/touch.png">'),
            'https://slow.example/touch.png' => Http::response(onePixelPng()),
            default => Http::response('', 404),
        };
    });

    new FetchFaviconForCompany($company->fresh())->handle();

    expect($company->fresh()->getMedia('logo'))->toHaveCount(1)
        ->and($timeouts)->toEqual([[5, 3], [5, 3], [7, 3]]);

    Exceptions::assertNothingReported();
});
