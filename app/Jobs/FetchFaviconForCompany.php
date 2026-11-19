<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\LogoImage;
use App\Exceptions\SsrfGuardException;
use App\Models\Company;
use App\Services\Favicon\Drivers\LogoFinderDriver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
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
        $domain = $this->company->logoDomain();

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

    public static function fetchedFrom(Media $logo, string $domain): bool
    {
        $stored = $logo->getCustomProperty('domain');

        return $stored === null || Company::domainFrom($stored) === $domain;
    }

    public function uniqueId(): string
    {
        return (string) $this->company->getKey();
    }
}
