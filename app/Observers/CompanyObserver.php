<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CustomFields\CompanyField;
use App\Jobs\FetchFaviconForCompany;
use App\Models\Company;

final readonly class CompanyObserver
{
    public function saved(Company $company): void
    {
        $company->invalidateAiSummary();
        $this->dispatchFaviconFetchIfNeeded($company);
    }

    private function dispatchFaviconFetchIfNeeded(Company $company): void
    {
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
