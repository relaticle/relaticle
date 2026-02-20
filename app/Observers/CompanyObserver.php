<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\CustomFields\CompanyField;
use App\Jobs\FetchFaviconForCompany;
use App\Models\Company;
use App\Models\User;

final readonly class CompanyObserver
{
    public function creating(Company $company): void
    {
        if (auth()->check()) {
            /** @var User $user */
            $user = auth()->user();
            $company->creator_id = $user->getKey();
            $company->team_id = $user->currentTeam->getKey();
        }
    }

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

        $company->load('customFieldValues.customField');

        $domains = $company->getCustomFieldValue($domainField);
        $firstDomain = is_array($domains) ? ($domains[0] ?? null) : $domains;

        if (blank($firstDomain)) {
            return;
        }

        dispatch(new FetchFaviconForCompany($company))->afterCommit();
    }
}
