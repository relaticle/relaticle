<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\FetchFaviconForCompany;
use App\Models\Company;
use App\Models\User;

final readonly class CompanyObserver
{
    /**
     * Handle the Company "creating" event.
     */
    public function creating(Company $company): void
    {
        if (auth()->check()) {
            /** @var User $user */
            $user = auth()->user();
            $company->creator_id = $user->getKey();
            $company->team_id = $user->currentTeam->getKey();
        }
    }

    /**
     * Handle the Company "created" event.
     */
    public function created(Company $company): void
    {
        FetchFaviconForCompany::dispatch($company)->afterCommit();
    }

    /**
     * Handle the Company "saved" event.
     * Invalidate AI summary when company data changes.
     */
    public function saved(Company $company): void
    {
        $company->invalidateAiSummary();
    }
}
