<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Company;
use App\Models\People;
use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;

final readonly class EmailLinkingService
{
    public function linkEmail(Email $email): void
    {
        $participants = $email->participants()->with('contact', 'company')->get();
        $teamId = $email->team_id;
        $skippedDomains = $this->buildSkippedDomains($teamId);

        foreach ($participants as $participant) {
            // 1. Try to match existing People record by email address
            $person = People::where('team_id', $teamId)
                ->whereHas('customFieldValues', fn ($q) => $q->where('value', $participant->email_address))
                ->first();

            if ($person) {
                $participant->update(['contact_id' => $person->getKey()]);
                $email->people()->syncWithoutDetaching([$person->getKey()]);

                // Update communication intelligence
                $this->updatePersonMetrics($person, $email);

                // Link to person's company if set
                if ($person->company_id) {
                    $email->companies()->syncWithoutDetaching([$person->company_id]);
                }
            }

            // 2. Try to match Company by email domain
            // Checks config/email.php defaults + team-specific public_email_domains table
            $domain = $this->extractDomain($participant->email_address);
            if ($domain && ! $skippedDomains->contains($domain)) {
                $company = Company::where('team_id', $teamId)
                    ->whereHas('customFieldValues', fn ($q) => $q->where('value', 'like', "%{$domain}%")
                    )
                    ->first();

                if ($company) {
                    $participant->update(['company_id' => $company->getKey()]);
                    $email->companies()->syncWithoutDetaching([$company->getKey()]);
                    $this->updateCompanyMetrics($company, $email);
                }
            }
        }
    }

    /**
     * Merge config/email.php default list with team-specific public_email_domains table.
     */
    private function buildSkippedDomains(string $teamId): Collection
    {
        $configDomains = collect(config('email-integration.public_domains', []))
            ->map(fn ($d) => strtolower($d));

        $teamDomains = PublicEmailDomain::where('team_id', $teamId)
            ->pluck('domain')
            ->map(fn ($d) => strtolower($d));

        return $configDomains->merge($teamDomains)->unique();
    }

    private function updatePersonMetrics(People $person, Email $email): void
    {
        $person->incrementQuietly('email_count');

        if ($email->direction->value === 'inbound') {
            $person->incrementQuietly('inbound_email_count');
        } else {
            $person->incrementQuietly('outbound_email_count');
        }

        $person->updateQuietly([
            'last_email_at' => $email->sent_at,
            'last_interaction_at' => $email->sent_at,
        ]);
    }

    private function updateCompanyMetrics(Company $company, Email $email): void
    {
        $company->incrementQuietly('email_count');

        if ($email->direction->value === 'inbound') {
            $company->incrementQuietly('inbound_email_count');
        } else {
            $company->incrementQuietly('outbound_email_count');
        }

        $company->updateQuietly([
            'last_email_at' => $email->sent_at,
            'last_interaction_at' => $email->sent_at,
        ]);
    }

    private function extractDomain(string $email): ?string
    {
        $parts = explode('@', $email);

        return count($parts) === 2 ? strtolower($parts[1]) : null;
    }
}
