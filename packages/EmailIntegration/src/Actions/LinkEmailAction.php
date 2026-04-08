<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Team;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;

final readonly class LinkEmailAction
{
    public function __construct(
        private AutoCreateCompanyAction $autoCreateCompany,
        private AutoCreatePersonAction $autoCreatePerson,
    ) {}

    public function execute(Email $email): void
    {
        $participants = $email->participants()->with('contact', 'company')->get();
        $teamId = $email->team_id;
        $connectedAccount = $email->connectedAccount;
        $skippedDomains = $this->buildSkippedDomains($teamId);

        $team = $email->team;

        foreach ($participants as $participant) {
            // 1. Try to match Company by email domain first, so the person can be born already linked.
            $company = null;
            $domain = $this->extractDomain($participant->email_address);

            if ($domain && $skippedDomains->doesntContain($domain)) {
                $company = Company::query()->where('team_id', $teamId)
                    ->whereHas('customFieldValues', fn (Builder $q) => $q->where('string_value', 'like', "%{$domain}%"))
                    ->first();

                // 2. Auto-create Company when no existing record found.
                if (! $company && $connectedAccount?->auto_create_companies) {
                    $company = $this->autoCreateCompany->execute($domain, $teamId, $team);
                }

                if ($company) {
                    $participant->update(['company_id' => $company->getKey()]);
                    $email->companies()->syncWithoutDetaching([$company->getKey()]);
                    $this->updateCompanyMetrics($company, $email);
                }
            }

            // 3. Try to match existing People record by email address.
            // Email values are stored as JSON arrays in json_value (e.g. ["user@example.com"])
            $person = People::query()->where('team_id', $teamId)
                ->whereHas('customFieldValues', fn (Builder $q) => $q
                    ->whereHas('customField', fn (Builder $q) => $q->where('type', 'email'))
                    ->whereJsonContains('json_value', $participant->email_address)
                )
                ->first();

            // 4. Auto-create Person when no existing record found, passing resolved company_id.
            if (! $person && $connectedAccount && $this->shouldCreatePerson($connectedAccount, $participant->email_address)) {
                $person = $this->autoCreatePerson->execute(
                    $participant->name ?? '',
                    $participant->email_address,
                    $teamId,
                    $team,
                    $company?->getKey(),
                );
            }

            if ($person) {
                $participant->update(['contact_id' => $person->getKey()]);
                $email->people()->syncWithoutDetaching([$person->getKey()]);
                $this->updatePersonMetrics($person, $email);

                // Link to person's company if set.
                if ($person->company_id) {
                    $email->companies()->syncWithoutDetaching([$person->company_id]);
                }

                // Link to person's opportunities.
                $opportunities = Opportunity::query()->where('team_id', $teamId)
                    ->where('contact_id', $person->getKey())
                    ->get();

                foreach ($opportunities as $opportunity) {
                    $email->opportunities()->syncWithoutDetaching([$opportunity->getKey()]);
                    $this->updateOpportunityMetrics($opportunity, $email);
                }
            }
        }
    }

    /**
     * Determine whether a new Person should be created for the given email address,
     * based on the account's contact_creation_mode setting.
     *
     * - All:           always create when the address is unknown
     * - Bidirectional: only create when the connected account has exchanged email in
     *                  BOTH directions with this address
     * - None:          never create (default)
     */
    private function shouldCreatePerson(ConnectedAccount $account, string $emailAddress): bool
    {
        return match ($account->contact_creation_mode) {
            ContactCreationMode::All => true,
            ContactCreationMode::Bidirectional => $this->hasBidirectionalHistory($account, $emailAddress),
            ContactCreationMode::None => false,
        };
    }

    /**
     * Returns true if the account already has at least one stored email in each
     * direction involving the given address.
     */
    private function hasBidirectionalHistory(ConnectedAccount $account, string $emailAddress): bool
    {
        $directions = Email::query()->where('connected_account_id', $account->getKey())
            ->whereHas('participants', fn (Builder $q) => $q->where('email_address', $emailAddress))
            ->distinct()
            ->pluck('direction');

        $values = $directions->map(fn ($d) => $d instanceof EmailDirection ? $d->value : $d);

        return $values->contains(EmailDirection::INBOUND->value)
            && $values->contains(EmailDirection::OUTBOUND->value);
    }

    /**
     * Merge config/email-integration.php default list with team-specific public_email_domains table.
     *
     * @return Collection<int, lowercase-string>
     */
    private function buildSkippedDomains(string $teamId): Collection
    {
        $configDomains = collect((array) config('email-integration.public_domains', []))
            ->map(fn (mixed $d): string => strtolower((string) $d));

        $teamDomains = PublicEmailDomain::query()->where('team_id', $teamId)
            ->pluck('domain')
            ->map(fn (mixed $d): string => strtolower((string) $d));

        return $configDomains->merge($teamDomains)->unique()->values();
    }

    private function extractDomain(string $email): ?string
    {
        $parts = explode('@', $email);

        return count($parts) === 2 ? strtolower($parts[1]) : null;
    }

    private function updatePersonMetrics(People $person, Email $email): void
    {
        $isInbound = $email->direction->value === EmailDirection::INBOUND->value;

        $person->updateQuietly([
            'email_count' => DB::raw('email_count + 1'),
            'inbound_email_count' => $isInbound ? DB::raw('inbound_email_count + 1') : DB::raw('inbound_email_count'),
            'outbound_email_count' => $isInbound ? DB::raw('outbound_email_count') : DB::raw('outbound_email_count + 1'),
            'last_email_at' => $email->sent_at,
            'last_interaction_at' => $email->sent_at,
        ]);
    }

    private function updateCompanyMetrics(Company $company, Email $email): void
    {
        $isInbound = $email->direction->value === EmailDirection::INBOUND->value;

        $company->updateQuietly([
            'email_count' => DB::raw('email_count + 1'),
            'inbound_email_count' => $isInbound ? DB::raw('inbound_email_count + 1') : DB::raw('inbound_email_count'),
            'outbound_email_count' => $isInbound ? DB::raw('outbound_email_count') : DB::raw('outbound_email_count + 1'),
            'last_email_at' => $email->sent_at,
            'last_interaction_at' => $email->sent_at,
        ]);
    }

    private function updateOpportunityMetrics(Opportunity $opportunity, Email $email): void
    {
        $isInbound = $email->direction->value === EmailDirection::INBOUND->value;

        $opportunity->updateQuietly([
            'email_count' => DB::raw('email_count + 1'),
            'inbound_email_count' => $isInbound ? DB::raw('inbound_email_count + 1') : DB::raw('inbound_email_count'),
            'outbound_email_count' => $isInbound ? DB::raw('outbound_email_count') : DB::raw('outbound_email_count + 1'),
            'last_email_at' => $email->sent_at,
            'last_interaction_at' => $email->sent_at,
        ]);
    }
}
