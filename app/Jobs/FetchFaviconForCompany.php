<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use AshAllenDesign\FaviconFetcher\Facades\Favicon;
use Exception;
use Illuminate\Contracts\Broadcasting\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

final class FetchFaviconForCompany implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public bool $deleteWhenMissingModels = true;

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

            $domainName = Str::start($domainName, 'https://');

            $favicon = Favicon::driver('high-quality')->fetch($domainName);
            $url = $favicon?->getFaviconUrl();

            if ($url === null) {
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

            $logo = $this->company
                ->addMediaFromUrl($url)
                ->usingFileName($filename)
                ->usingName('company_logo')
                ->withCustomProperties([
                    'domain' => $domainName,
                    'original_size' => $favicon->getIconSize(),
                    'icon_type' => $favicon->getIconType(),
                    'fetched_at' => now()->toIso8601String(),
                ])
                ->toMediaCollection('logo');

            $this->company->clearMediaCollectionExcept('logo', $logo);
        } catch (Exception $exception) {
            report($exception);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->company->getKey();
    }
}
