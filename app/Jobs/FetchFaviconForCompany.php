<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use App\Services\Favicon\SsrfGuard;
use AshAllenDesign\FaviconFetcher\Facades\Favicon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Str;
use Throwable;

#[DeleteWhenMissingModels]
final class FetchFaviconForCompany implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    public function __construct(public readonly Company $company) {}

    public function handle(): void
    {
        try {
            $customFieldDomain = $this->company->customFields()
                ->whereBelongsTo($this->company->team)
                ->where('code', CompanyField::DOMAINS->value)
                ->first();

            $domains = $this->company->getCustomFieldValue($customFieldDomain);
            $domainName = is_array($domains) ? ($domains[0] ?? null) : $domains;

            if ($domainName === null || $domainName === '') {
                return;
            }

            if (! Str::startsWith($domainName, ['http://', 'https://'])) {
                $domainName = 'https://'.$domainName;
            }

            $favicon = Favicon::driver('high-quality')->fetch($domainName);
            $url = $favicon?->getFaviconUrl();

            if ($url === null) {
                return;
            }

            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                return;
            }

            if (! SsrfGuard::isAllowed($url)) {
                return;
            }

            $path = parse_url($url, PHP_URL_PATH);
            $extension = $path ? pathinfo($path, PATHINFO_EXTENSION) : '';

            $filename = match ($extension) {
                'svg' => 'logo.svg',
                'webp' => 'logo.webp',
                'jpg', 'jpeg' => 'logo.jpg',
                default => 'logo.png',
            };

            $response = SsrfGuard::guardedHttpClient()
                ->timeout(15)
                ->get($url);

            if (! $response->successful()) {
                return;
            }

            $logo = $this->company
                ->addMediaFromString($response->body())
                ->usingFileName($filename)
                ->usingName('company_logo')
                ->withCustomProperties([
                    'domain' => $domainName,
                    'original_size' => $favicon->getIconSize(),
                    'icon_type' => $favicon->getIconType(),
                    'fetched_at' => now()->toIso8601String(),
                ])
                ->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);

            $this->company->clearMediaCollectionExcept(Company::LOGO_MEDIA_COLLECTION, $logo);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->company->getKey();
    }
}
