<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\LogoImage;
use App\Enums\CustomFields\CompanyField;
use App\Exceptions\SsrfGuardException;
use App\Models\Company;
use App\Models\CustomFieldValue;
use App\Services\Favicon\Drivers\LogoFinderDriver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

#[DeleteWhenMissingModels]
#[Timeout(30)]
#[Tries(1)]
#[UniqueFor(600)]
final class FetchFaviconForCompany implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    private const string HOSTNAME_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    public function __construct(public readonly Company $company) {}

    public function handle(LogoFinderDriver $finder): void
    {
        try {
            $this->storeLogo($finder);
        } catch (Throwable $exception) {
            report_unless($this->isTransient($exception), $exception);
        }
    }

    private function storeLogo(LogoFinderDriver $finder): void
    {
        $domain = self::domainOf($this->company);

        if ($domain === null || ! $finder->isConfigured()) {
            return;
        }

        $image = $finder->fetch($domain);

        if (! $image instanceof LogoImage) {
            $this->company->getMedia(Company::LOGO_MEDIA_COLLECTION)
                ->reject(fn (Media $logo): bool => self::fetchedFrom($logo, $domain))
                ->each
                ->delete();

            return;
        }

        $extension = Company::logoExtensionFor($image->bytes);

        if ($extension === null) {
            return;
        }

        $logo = $this->company
            ->addMediaFromString($image->bytes)
            ->usingFileName("logo.{$extension}")
            ->usingName('company_logo')
            ->withCustomProperties([
                'domain' => $domain,
                'fetched_at' => now()->toIso8601String(),
            ])
            ->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);

        $this->company->clearMediaCollectionExcept(Company::LOGO_MEDIA_COLLECTION, $logo);
    }

    private function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException && $exception->response->status() === 429) {
            return true;
        }

        return $exception instanceof SsrfGuardException && $exception->hostUnresolved;
    }

    public static function domainOf(Company $company): ?string
    {
        $stored = $company->relationLoaded('customFieldValues')
            ? $company->customFieldValues->first(fn (CustomFieldValue $value): bool => data_get($value, 'customField.code') === CompanyField::DOMAINS->value)
            : $company->customFieldValues()
                ->whereHas('customField', fn (Builder $field): Builder => $field
                    ->where('code', CompanyField::DOMAINS->value)
                    ->where('tenant_id', $company->workspace_id))
                ->with('customField')
                ->first();

        $value = $stored?->getValue();

        return self::hostFrom(is_iterable($value) ? Arr::first($value) : $value);
    }

    public static function hostFrom(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $host = Str::of($value)->trim()->lower()
            ->replaceMatches('#^[a-z][a-z0-9+.-]*://#', '')
            ->replaceMatches('#[/?\#].*$#', '')
            ->replaceMatches('#^[^@]*@#', '')
            ->replaceMatches('#:\d*$#', '')
            ->rtrim('.')
            ->chopStart('www.')
            ->toString();

        return preg_match(self::HOSTNAME_PATTERN, $host) === 1 ? $host : null;
    }

    public static function fetchedFrom(Media $logo, string $domain): bool
    {
        $stored = $logo->getCustomProperty('domain');

        return $stored === null || self::hostFrom($stored) === $domain;
    }

    public function uniqueId(): string
    {
        return (string) $this->company->getKey();
    }
}
