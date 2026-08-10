<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Team;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;
use Relaticle\EmailIntegration\Support\AutomatedSenderMatcher;
use Relaticle\EmailIntegration\Support\CompanyDomainMatcher;

final readonly class LinkEmailAction
{
    public function __construct(
        private AutoCreateCompanyAction $autoCreateCompany,
        private AutoCreatePersonAction $autoCreatePerson,
        private CompanyDomainMatcher $domainMatcher,
        private AutomatedSenderMatcher $automatedSender,
    ) {}

    public function execute(Email $email): void
    {
        $participants = $email->participants()->with('contact', 'company')->get();
        $teamId = $email->team_id;
        $connectedAccount = $email->connectedAccount;
        $skippedDomains = $this->buildSkippedDomains($teamId);

        $team = $email->team;

        // A single email can resolve to the same company/person/opportunity through
        // multiple participants (e.g. two recipients at the same domain). Metrics
        // must be counted once per email, so track which records were already
        // incremented during this run.
        $countedCompanies = [];
        $countedPeople = [];
        $countedOpportunities = [];

        foreach ($participants as $participant) {
            // Machine-sent senders (no-reply@, notice@, bounce@) still link to existing
            // records but must never spawn a new Company/Person — there's no real
            // contact behind them.
            $isAutomatedSender = $this->automatedSender->matches($participant->email_address);

            // 1. Try to match Company by email domain first, so the person can be born already linked.
            $company = null;
            $domain = $this->extractDomain($participant->email_address);

            if ($domain && $skippedDomains->doesntContain($domain)) {
                $company = $this->domainMatcher->firstMatching($domain, $teamId);

                // 2. Auto-create Company when no existing record found.
                if (! $company && ! $isAutomatedSender && $connectedAccount?->auto_create_companies) {
                    $company = $this->autoCreateCompany->execute($domain, $teamId, $team);
                }

                if ($company instanceof Company) {
                    $participant->update(['company_id' => $company->getKey()]);
                    $email->companies()->syncWithoutDetaching([$company->getKey()]);

                    if (! isset($countedCompanies[$company->getKey()])) {
                        $countedCompanies[$company->getKey()] = true;
                        $this->incrementEmailMetrics($company, $email);
                    }
                }
            }

            // 3. Try to match existing People record by email address.
            // Email values are stored as JSON arrays in json_value (e.g. ["user@example.com"])
            $person = People::query()->where('team_id', $teamId)
                ->whereHas('customFieldValues', fn (Builder $valueQuery) => $valueQuery
                    ->whereHas('customField', fn (Builder $fieldQuery) => $fieldQuery->where('type', 'email'))
                    ->whereJsonContains('json_value', $participant->email_address)
                )
                ->first();

            // 4. Auto-create Person when no existing record found, passing resolved company_id.
            if (! $person && ! $isAutomatedSender && $connectedAccount && $this->shouldCreatePerson($connectedAccount, $participant->email_address)) {
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

                if (! isset($countedPeople[$person->getKey()])) {
                    $countedPeople[$person->getKey()] = true;
                    $this->incrementEmailMetrics($person, $email);
                }

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

                    if (! isset($countedOpportunities[$opportunity->getKey()])) {
                        $countedOpportunities[$opportunity->getKey()] = true;
                        $this->incrementEmailMetrics($opportunity, $email);
                    }
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
            ->whereHas('participants', fn (Builder $participantQuery) => $participantQuery->where('email_address', $emailAddress))
            ->distinct()
            ->pluck('direction');

        $values = $directions->map(fn (mixed $direction): mixed => $direction instanceof EmailDirection ? $direction->value : $direction);

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

    /**
     * Increment the shared email-interaction counters on a linked CRM record
     * (People, Company, or Opportunity — all expose the same metric columns).
     *
     * Counters use atomic SQL increments so concurrent StoreEmailJob workers
     * don't lose updates. The timestamps use GREATEST so an older email linked
     * after a newer one (out-of-order parallel backfill) never moves
     * last_email_at backwards.
     */
    private function incrementEmailMetrics(Model $record, Email $email): void
    {
        $isInbound = $email->direction->value === EmailDirection::INBOUND->value;

        // Raw, parameterised UPDATE: counters increment atomically (no lost updates
        // under concurrent StoreEmailJob workers) and the timestamps use GREATEST so
        // an older email linked after a newer one (out-of-order parallel backfill)
        // never moves last_email_at backwards. Bindings keep the date out of the SQL
        // string; the table/key come from model metadata, never user input.
        $sets = [
            'email_count = email_count + 1',
            'inbound_email_count = inbound_email_count + ?',
            'outbound_email_count = outbound_email_count + ?',
            'updated_at = ?',
        ];

        /** @var list<mixed> $bindings */
        $bindings = [$isInbound ? 1 : 0, $isInbound ? 0 : 1, now()];

        if ($email->sent_at !== null) {
            $sets[] = 'last_email_at = GREATEST(last_email_at, ?)';
            $sets[] = 'last_interaction_at = GREATEST(last_interaction_at, ?)';
            $bindings[] = $email->sent_at;
            $bindings[] = $email->sent_at;
        }

        $bindings[] = $record->getKey();

        DB::update(
            'update '.$record->getTable().' set '.implode(', ', $sets).' where '.$record->getKeyName().' = ?',
            $bindings,
        );
    }
}
