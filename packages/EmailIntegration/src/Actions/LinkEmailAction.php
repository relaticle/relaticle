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
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\RecordCommunicationMetrics;
use Relaticle\EmailIntegration\Support\AutomatedSenderMatcher;
use Relaticle\EmailIntegration\Support\CompanyDomainMatcher;
use Relaticle\EmailIntegration\Support\PublicDomainList;

final readonly class LinkEmailAction
{
    public function __construct(
        private AutoCreateCompanyAction $autoCreateCompany,
        private AutoCreatePersonAction $autoCreatePerson,
        private CompanyDomainMatcher $domainMatcher,
        private AutomatedSenderMatcher $automatedSender,
        private EmailVisibilityService $visibility,
        private RecordCommunicationMetrics $metrics,
        private PublicDomainList $publicDomainList,
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

            $applyMetricsForPrelinkedRecords = $locked->linked_at === null;

            $this->link($email, $applyMetricsForPrelinkedRecords);

            if ($locked->linked_at === null) {
                $locked->updateQuietly(['linked_at' => now()]);
            }
        });
    }

    private function link(Email $email, bool $applyMetricsForPrelinkedRecords): void
    {
        $participants = $email->participants()->with('contact', 'company')->get();
        $teamId = $email->team_id;
        $connectedAccount = $email->connectedAccount;
        $skippedDomains = $this->publicDomainList->forTeam($teamId);

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
            // records but must never spawn a new Company/Person: there's no real
            // contact behind them.
            $isAutomatedSender = $this->automatedSender->matches($participant->email_address);
            $suppressCreate = $this->visibility->suppressesRecordCreation(
                $participant->email_address,
                $teamId,
                $email->connected_account_id,
            );

            // 1. Resolve the person before deciding whether to create a company.
            // Email values are stored as JSON arrays in json_value (e.g. ["user@example.com"])
            $person = People::query()->where('team_id', $teamId)
                ->whereHas('customFieldValues', fn (Builder $valueQuery) => $valueQuery
                    ->whereHas('customField', fn (Builder $fieldQuery) => $fieldQuery->where('type', 'email'))
                    ->whereJsonContains('json_value', $participant->email_address)
                )
                ->first();

            $wouldCreatePerson = ! $person
                && ! $email->is_internal
                && ! $isAutomatedSender
                && ! $suppressCreate
                && $connectedAccount
                && $team
                && $this->shouldCreatePerson($team, $participant->email_address, $email);

            // 2. Match or create Company by email host so a new person can be born already linked.
            $company = null;
            $rawDomain = $this->extractDomain($participant->email_address);
            $host = $rawDomain !== null ? $this->domainMatcher->host($rawDomain) : null;

            if ($host && $skippedDomains->doesntContain($host)) {
                $company = $this->domainMatcher->firstMatching($host, $teamId);

                // 3. Auto-create Company only when a new person would also be created.
                if (! $company && $wouldCreatePerson && $this->shouldCreateCompany($team, $participant->email_address, $email)) {
                    $company = $this->autoCreateCompany->execute($host, $teamId, $team);
                }

                if ($company instanceof Company) {
                    $participant->update(['company_id' => $company->getKey()]);

                    if ($this->autoAttach($email->companies(), $company->getKey()) && ! isset($countedCompanies[$company->getKey()])) {
                        $countedCompanies[$company->getKey()] = true;
                        $this->metrics->incrementEmailMetrics($company, $email);
                    }
                }
            }

            // 4. Auto-create Person when no existing record found, passing resolved company_id.
            if ($wouldCreatePerson) {
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
                    $this->metrics->incrementEmailMetrics($person, $email);
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
                        $this->metrics->incrementEmailMetrics($opportunity, $email);
                    }
                }
            }
        }

        if ($applyMetricsForPrelinkedRecords) {
            $this->metrics->incrementEmailMetricsForPrelinkedRecords(
                $email,
                $countedCompanies,
                $countedPeople,
                $countedOpportunities,
            );
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
            ContactCreationMode::Selective => $email->direction === EmailDirection::OUTBOUND
                || $this->hasTeamOutboundHistory($team, $emailAddress),
            ContactCreationMode::None => false,
        };
    }

    /**
     * A company is created only when a person would be created for this address
     * and the workspace company toggle is on. None never creates companies.
     * Selective creates them only for addresses the workspace has emailed (or
     * the outbound message being linked). All creates them for every eligible
     * address.
     */
    private function shouldCreateCompany(Team $team, string $emailAddress, Email $email): bool
    {
        return $team->auto_create_companies && $this->shouldCreatePerson($team, $emailAddress, $email);
    }

    /**
     * True when any connected mailbox on this team has an outbound email involving
     * the address. Includes mail stored under disconnected accounts so a reply
     * on an active mailbox still creates the person after account churn.
     */
    private function hasTeamOutboundHistory(Team $team, string $emailAddress): bool
    {
        return Email::query()
            ->withoutGlobalScope(ActiveAccountScope::class)
            ->where('team_id', $team->getKey())
            ->where('direction', EmailDirection::OUTBOUND)
            ->whereHas(
                'participants',
                fn (Builder $participantQuery) => $participantQuery->where('email_address', $emailAddress),
            )
            ->exists();
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
}
