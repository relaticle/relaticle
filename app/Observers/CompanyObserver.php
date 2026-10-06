<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\FetchFaviconForCompany;
use App\Models\Company;
use App\Observers\Concerns\TagsFirstCrmData;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class CompanyObserver
{
    use TagsFirstCrmData;

    public function created(Company $company): void
    {
        $this->tagFirstCrmDataIfNeeded($company);
    }

    public function saved(Company $company): void
    {
        $this->dispatchFaviconFetchIfNeeded($company);
    }

    private function dispatchFaviconFetchIfNeeded(Company $company): void
    {
        $sourceUrl = FetchFaviconForCompany::sourceUrl($company);

        if ($sourceUrl === null) {
            return;
        }

        $logo = $company->getFirstMedia(Company::LOGO_MEDIA_COLLECTION);

        // Company saves are frequent and the fetch hits slow remote sites, so a logo
        // already fetched from the current domain is never fetched again.
        if ($logo instanceof Media && FetchFaviconForCompany::fetchedFrom($logo, $sourceUrl)) {
            return;
        }

        dispatch(new FetchFaviconForCompany($company))->afterCommit();
    }
}
