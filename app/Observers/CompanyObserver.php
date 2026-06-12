<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CustomFields\CompanyField;
use App\Jobs\FetchFaviconForCompany;
use App\Models\Company;
use App\Observers\Concerns\TagsFirstCrmData;

final readonly class CompanyObserver
{
    use TagsFirstCrmData;

    public function created(Company $company): void
    {
        $this->tagFirstCrmDataIfNeeded($company);
    }

    public function saved(Company $company): void
    {
        $company->invalidateAiSummary();
        $this->dispatchFaviconFetchIfNeeded($company);
    }

    private function dispatchFaviconFetchIfNeeded(Company $company): void
    {
        // Once a logo is stored we do not re-fetch on subsequent saves: the observer
        // fires on every Company update and would otherwise flood the queue with
        // redundant favicon dispatches against slow remote sites. If the domain
        // changes, callers must clear the 'logo' media collection to trigger a refetch.
        if ($company->hasMedia(Company::LOGO_MEDIA_COLLECTION)) {
            return;
        }

        $domainField = $company->customFields()
            ->whereBelongsTo($company->team)
            ->where('code', CompanyField::DOMAINS->value)
            ->first();

        if ($domainField === null) {
            return;
        }

        $company->load('customFieldValues.customField.options');

        $domains = $company->getCustomFieldValue($domainField);
        $firstDomain = is_array($domains) ? ($domains[0] ?? null) : $domains;

        if (blank($firstDomain)) {
            return;
        }

        dispatch(new FetchFaviconForCompany($company))->afterCommit();
    }
}
