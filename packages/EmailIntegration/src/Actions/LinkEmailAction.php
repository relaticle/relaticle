<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Team;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailDirection;
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

    /**
     * Link an email to its CRM records exactly once.
     *
     * incrementEmailMetrics() is not idempotent, so a second pass over an email
     * would inflate email_count on every record it touches. The `linked_at` claim
     * and the linking itself share one transaction, taken under a row lock on the
     * email: a crash mid-link rolls the claim back so a retry re-links from
     * scratch, and a retry after a completed link is a no-op. Concurrent workers
     * on the same email serialise on the lock, and the second one finds the claim.
     */
    public function execute(Email $email): void
    {
        $this->claimAndLink($email, reapply: false);
    }

    /**
     * Re-run CRM linking under the current workspace policy.
     *
     * Mailbox history re-import must create people after a None → Selective (or All)
     * switch even though `linked_at` was set on the first pass. autoAttach() still
     * skips metric increments for records already on the email.
     */
    public function reapply(Email $email): void
    {
        $this->claimAndLink($email, reapply: true);
    }

    private function claimAndLink(Email $email, bool $reapply): void
    {
        DB::transaction(function () use ($email, $reapply): void {
            /** @var Email|null $locked */
            $locked = Email::query()
                ->withoutGlobalScopes()
                ->whereKey($email->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            if (! $reapply && $locked->linked_at !== null) {
                return;
            }

            $this->link($email);

            if ($locked->linked_at === null) {
                $locked->updateQuietly(['linked_at' => now()]);
            }
        });
    }

    private function link(Email $email): void
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
                if (! $company && ! $isAutomatedSender && $team?->auto_create_companies) {
                    $company = $this->autoCreateCompany->execute($domain, $teamId, $team);
                }

                if ($company instanceof Company) {
                    $participant->update(['company_id' => $company->getKey()]);

                    if ($this->autoAttach($email->companies(), $company->getKey()) && ! isset($countedCompanies[$company->getKey()])) {
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
            if (! $person && ! $isAutomatedSender && $connectedAccount && $team && $this->shouldCreatePerson($team, $participant->email_address, $email)) {
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

                if ($this->autoAttach($email->people(), $person->getKey()) && ! isset($countedPeople[$person->getKey()])) {
                    $countedPeople[$person->getKey()] = true;
                    $this->incrementEmailMetrics($person, $email);
                }

                if ($person->company_id) {
                    $this->autoAttach($email->companies(), $person->company_id);
                }

                $opportunities = Opportunity::query()->where('team_id', $teamId)
                    ->where('contact_id', $person->getKey())
                    ->get();

                foreach ($opportunities as $opportunity) {
                    if ($this->autoAttach($email->opportunities(), $opportunity->getKey()) && ! isset($countedOpportunities[$opportunity->getKey()])) {
                        $countedOpportunities[$opportunity->getKey()] = true;
                        $this->incrementEmailMetrics($opportunity, $email);
                    }
                }
            }
        }
    }

    /**
     * Determine whether a new Person should be created for the given email address,
     * based on the workspace contact_creation_mode setting.
     *
     * - All:        always create when the address is unknown
     * - Selective:  create when any workspace mailbox has sent to this address
     * - None:       never create
     */
    private function shouldCreatePerson(Team $team, string $emailAddress, Email $email): bool
    {
        return match ($team->contact_creation_mode) {
            ContactCreationMode::All => true,
            // The outbound message being linked is itself history: do not depend on a
            // second query that ActiveAccountScope can hide during mailbox re-import.
            ContactCreationMode::Selective => $email->direction === EmailDirection::OUTBOUND
                || $this->hasTeamOutboundHistory($team, $emailAddress),
            ContactCreationMode::None => false,
        };
    }

    /**
     * True when any connected mailbox on this team has an outbound email involving
     * the address. The email currently being linked already exists in the table,
     * so the first send is enough — a reply is not required.
     */
    private function hasTeamOutboundHistory(Team $team, string $emailAddress): bool
    {
        return Email::query()
            ->where('team_id', $team->getKey())
            ->where('direction', EmailDirection::OUTBOUND)
            ->whereHas(
                'participants',
                fn (Builder $participantQuery) => $participantQuery->where('email_address', $emailAddress),
            )
            ->exists();
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
     * Attaches a record to the email via the given morph relation if not already linked.
     * Returns true only when a new pivot row was created, so metric increments stay
     * one-shot across history re-import of the same message.
     *
     * @template TRelated of Model
     *
     * @param  MorphToMany<TRelated, Email>  $relation
     */
    private function autoAttach(MorphToMany $relation, string $relatedId): bool
    {
        if ($relation->whereKey($relatedId)->exists()) {
            return false;
        }

        $relation->attach($relatedId, ['link_source' => 'auto']);

        return true;
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
