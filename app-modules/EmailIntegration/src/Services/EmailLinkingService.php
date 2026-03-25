<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Team;
use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;

final readonly class EmailLinkingService
{
    public function __construct(
        private EmailAutoCreateService $autoCreate,
    ) {}

    public function linkEmail(Email $email): void
    {
        $participants = $email->participants()->with('contact', 'company')->get();
        $teamId = $email->team_id;
        $connectedAccount = $email->connectedAccount;
        $skippedDomains = $this->buildSkippedDomains($teamId);

        /** @var Team $team */
        $team = Team::find($teamId);

        foreach ($participants as $participant) {
            // 1. Try to match existing People record by email address.
            // Email values are stored as JSON arrays in json_value (e.g. ["user@example.com"])
            $person = People::where('team_id', $teamId)
                ->whereHas('customFieldValues', fn ($q) => $q
                    ->whereHas('customField', fn ($q) => $q->where('type', 'email'))
                    ->whereJsonContains('json_value', $participant->email_address)
                )
                ->first();

            // 2. Auto-create Person when no existing record found.
            if (! $person && $connectedAccount && $this->autoCreate->shouldCreatePerson($connectedAccount, $participant->email_address)) {
                $person = $this->autoCreate->createPerson(
                    $participant->name ?? '',
                    $participant->email_address,
                    $teamId,
                    $team,
                );
            }

            if ($person) {
                $participant->update(['contact_id' => $person->getKey()]);
                $email->people()->syncWithoutDetaching([$person->getKey()]);

                $this->updatePersonMetrics($person, $email);

                // Link to person's company if set
                if ($person->company_id) {
                    $email->companies()->syncWithoutDetaching([$person->company_id]);
                }

                // Link to person's opportunities
                $opportunities = Opportunity::where('team_id', $teamId)
                    ->where('contact_id', $person->getKey())
                    ->get();

                foreach ($opportunities as $opportunity) {
                    $email->opportunities()->syncWithoutDetaching([$opportunity->getKey()]);
                    $this->updateOpportunityMetrics($opportunity, $email);
                }
            }

            // 3. Try to match Company by email domain.
            // Checks config/email-integration.php defaults + team-specific public_email_domains table.
            $domain = $this->extractDomain($participant->email_address);
            if ($domain && ! $skippedDomains->contains($domain)) {
                $company = Company::where('team_id', $teamId)
                    ->whereHas('customFieldValues', fn ($q) => $q->where('string_value', 'like', "%{$domain}%"))
                    ->first();

                // 4. Auto-create Company when no existing record found.
                if (! $company && $connectedAccount?->auto_create_companies) {
                    $company = $this->autoCreate->createCompany($domain, $teamId, $team);
                }

                if ($company) {
                    $participant->update(['company_id' => $company->getKey()]);
                    $email->companies()->syncWithoutDetaching([$company->getKey()]);
                    $this->updateCompanyMetrics($company, $email);
                }
            }
        }
    }

    /**
     * Merge config/email-integration.php default list with team-specific public_email_domains table.
     */
    private function buildSkippedDomains(string $teamId): Collection
    {
        $configDomains = collect(config('email-integration.public_domains', []))
            ->map(fn ($d) => strtolower($d));

        $teamDomains = PublicEmailDomain::query()->where('team_id', $teamId)
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

    private function updateOpportunityMetrics(Opportunity $opportunity, Email $email): void
    {
        $opportunity->incrementQuietly('email_count');

        if ($email->direction->value === 'inbound') {
            $opportunity->incrementQuietly('inbound_email_count');
        } else {
            $opportunity->incrementQuietly('outbound_email_count');
        }

        $opportunity->updateQuietly([
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
