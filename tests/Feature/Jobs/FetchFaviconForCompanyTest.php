<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Exceptions\SsrfGuardException;
use App\Jobs\FetchFaviconForCompany;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Services\Favicon\Drivers\LogoFinderDriver;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

mutates(FetchFaviconForCompany::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);

    config()->set('services.logo_finder.url', 'https://logos.example.test');
    config()->set('services.logo_finder.key', 'worker-secret');
    resolveHostsTo(['93.184.216.34']);
    Http::preventStrayRequests();
    Storage::fake('public');
    Queue::fake();

    $this->companyWith = fn (string $domain): Company => Company::factory()->for($this->user->currentWorkspace)->create([
        'custom_fields' => [CompanyField::DOMAINS->value => [$domain]],
    ]);
});

test('job declares timeout, tries, uniqueFor consistent with horizon worker timeout', function (): void {
    $job = new ReflectionClass(FetchFaviconForCompany::class);

    expect($job->getAttributes(Tries::class)[0]->newInstance()->tries)->toBe(1)
        ->and($job->getAttributes(Timeout::class)[0]->newInstance()->timeout)->toBe(30)
        ->and($job->getAttributes(UniqueFor::class)[0]->newInstance()->uniqueFor)->toBe(600);
});

test('asks only the logo finder', function (): void {
    Http::fake(['https://logos.example.test/*' => Http::response(onePixelPng(), 200, ['Content-Type' => 'image/png'])]);
    $company = ($this->companyWith)('https://www.Example.com/about');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    Http::assertSentCount(1);
    Http::assertSent(fn (HttpClientRequest $request): bool => $request->url() === 'https://logos.example.test/logo?domain=example.com'
        && $request->header('X-Logo-Finder-Key') === ['worker-secret']);

    $logo = $company->fresh()->getFirstMedia('logo');

    expect($logo?->file_name)->toBe('logo.png')
        ->and($logo?->getCustomProperty('domain'))->toBe('example.com');
});

test('stores nothing when the logo finder has no icon', function (): void {
    Http::fake(['https://logos.example.test/*' => Http::response('', 204)]);
    $company = ($this->companyWith)('example.com');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    expect($company->fresh()->getMedia('logo'))->toBeEmpty();
    Exceptions::assertNothingReported();
});

test('does nothing when no logo finder is configured', function (): void {
    config()->set('services.logo_finder.url', null);
    $company = ($this->companyWith)('example.com');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    Http::assertNothingSent();
    expect($company->fresh()->getMedia('logo'))->toBeEmpty();
});

test('does nothing when the company has no usable domain', function (): void {
    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    Http::assertNothingSent();
});

test('refuses a body that is not a raster image', function (string $contentType, string $body): void {
    Http::fake(['https://logos.example.test/*' => Http::response($body, 200, ['Content-Type' => $contentType])]);
    $company = ($this->companyWith)('example.com');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    expect($company->fresh()->getMedia('logo'))->toBeEmpty();
})->with([
    'svg with script' => ['image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'html page claiming png' => ['image/png', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'],
]);

test('keeps the logo when the body is not an image', function (): void {
    Http::fake(['https://logos.example.test/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html'])]);
    $company = ($this->companyWith)('example.com');
    $company->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection('logo');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    expect($company->fresh()->getMedia('logo'))->toHaveCount(1);
});

test('decides the type by the bytes, whatever the header says', function (): void {
    Http::fake(['https://logos.example.test/*' => Http::response(onePixelPng(), 200, ['Content-Type' => 'text/html'])]);
    $company = ($this->companyWith)('example.com');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    expect($company->fresh()->getFirstMedia('logo')?->file_name)->toBe('logo.png');
});

test('the company logo collection refuses svg content from any writer', function (): void {
    $company = Company::factory()->for($this->user->currentWorkspace)->create();

    expect(fn () => $company->addMediaFromString('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')
        ->usingFileName('logo.svg')
        ->toMediaCollection(Company::LOGO_MEDIA_COLLECTION))
        ->toThrow(FileUnacceptableForCollection::class);
});

test('replaces the logo fetched for a previous domain', function (): void {
    Http::fake(['https://logos.example.test/*' => Http::response(onePixelPng(), 200, ['Content-Type' => 'image/png'])]);
    $company = ($this->companyWith)('new-domain.com');
    $company->addMediaFromString(onePixelPng())
        ->usingFileName('logo.png')
        ->withCustomProperties(['domain' => 'https://old-domain.com'])
        ->toMediaCollection('logo');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    $logos = $company->fresh()->getMedia('logo');

    expect($logos)->toHaveCount(1)
        ->and($logos->first()->getCustomProperty('domain'))->toBe('new-domain.com');
});

test('drops the logo of a previous domain when the new domain has no icon', function (): void {
    Http::fake(['https://logos.example.test/*' => Http::response('', 204)]);
    $company = ($this->companyWith)('new-domain.com');
    $company->addMediaFromString(onePixelPng())
        ->usingFileName('logo.png')
        ->withCustomProperties(['domain' => 'https://old-domain.com'])
        ->toMediaCollection('logo');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    expect($company->fresh()->getMedia('logo'))->toBeEmpty();
});

test('keeps a logo already fetched for the current domain when the finder has no icon', function (): void {
    Http::fake(['https://logos.example.test/*' => Http::response('', 204)]);
    $company = ($this->companyWith)('example.com');
    $company->addMediaFromString(onePixelPng())
        ->usingFileName('logo.png')
        ->withCustomProperties(['domain' => 'https://www.example.com'])
        ->toMediaCollection('logo');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    expect($company->fresh()->getMedia('logo'))->toHaveCount(1);
});

test('keeps the logo of a previous domain when the finder fails', function (int $status): void {
    Http::fake(['https://logos.example.test/*' => Http::response('', $status)]);
    $company = ($this->companyWith)('new-domain.com');
    $company->addMediaFromString(onePixelPng())
        ->usingFileName('logo.png')
        ->withCustomProperties(['domain' => 'https://old-domain.com'])
        ->toMediaCollection('logo');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    expect($company->fresh()->getMedia('logo'))->toHaveCount(1);
})->with([400, 401, 429, 500]);

test('a rate limited or unreachable finder is not reported', function (Closure $answer): void {
    Http::fake(['https://logos.example.test/*' => $answer]);
    $company = ($this->companyWith)('example.com');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    Exceptions::assertNothingReported();
})->with([
    'rate limited' => [fn () => Http::response('', 429)],
    'timeout' => [fn () => throw new ConnectionException('cURL error 28: timeout')],
]);

test('a finder that refuses the key is reported', function (): void {
    Http::fake(['https://logos.example.test/*' => Http::response('', 401)]);
    $company = ($this->companyWith)('example.com');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    Exceptions::assertReported(RequestException::class);
});

test('a finder host that resolves to a private address is never called', function (): void {
    resolveHostsTo(['10.10.0.77']);
    $company = ($this->companyWith)('example.com');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    Http::assertNothingSent();
    Exceptions::assertReported(SsrfGuardException::class);
});

test('never follows a redirect from the finder', function (): void {
    Http::fake(['https://logos.example.test/*' => Http::response('', 302, ['Location' => 'https://1.1.1.1/logo.png'])]);
    $company = ($this->companyWith)('example.com');

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    Http::assertSentCount(1);
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

    new FetchFaviconForCompany($company->fresh())->handle(resolve(LogoFinderDriver::class));

    Http::assertNothingSent();
});
