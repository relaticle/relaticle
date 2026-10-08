<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\RecordCommunicationMetrics;
use Relaticle\EmailIntegration\Support\AutomatedSenderMatcher;
use Relaticle\EmailIntegration\Support\CompanyDomainMatcher;
use Relaticle\EmailIntegration\Support\PersonEmailMatcher;

final readonly class LinkMeetingAction
{
    public function __construct(
        private AutoCreateCompanyAction $autoCreateCompany,
        private AutoCreatePersonAction $autoCreatePerson,
        private CompanyDomainMatcher $domainMatcher,
        private PersonEmailMatcher $personEmailMatcher,
        private AutomatedSenderMatcher $automatedSender,
        private EmailVisibilityService $visibility,
        private RecordCommunicationMetrics $metrics,
    ) {}

    public function execute(Meeting $meeting): void
    {
        $countsTowardIntelligence = $this->visibility->meetingCountsTowardCommunicationIntelligence($meeting);
        $attendees = $meeting->attendees()->where('is_self', false)->get();
        $workspaceId = $meeting->workspace_id;
        $workspace = $meeting->workspace;
        $account = $meeting->connectedAccount;
        $skippedDomains = $this->domainMatcher->skippedHosts($workspaceId);
        $counted = [];

        foreach ($attendees as $attendee) {
            $isAutomatedSender = $this->automatedSender->matches($attendee->email_address);
            $suppressCreate = $this->visibility->suppressesRecordCreation(
                $attendee->email_address,
                $workspaceId,
                $meeting->connected_account_id,
            );

            $person = $this->personEmailMatcher->firstMatching($attendee->email_address, $workspaceId);

            $wouldCreatePerson = ! $person
                && ! $isAutomatedSender
                && ! $suppressCreate
                && $account
                && $workspace
                && $this->shouldCreatePerson($workspace);

            $company = null;
            $rawDomain = $this->extractDomain($attendee->email_address);
            $host = $rawDomain !== null ? $this->domainMatcher->host($rawDomain) : null;

            if ($host && $skippedDomains->doesntContain($host)) {
                $company = $this->domainMatcher->firstMatching($host, $workspaceId);

                if (! $company && $wouldCreatePerson && $workspace->auto_create_companies) {
                    $company = $this->autoCreateCompany->execute($host, $workspaceId);
                }

                if ($company instanceof Company) {
                    $attendee->update(['company_id' => $company->getKey()]);
                    if ($this->autoAttach($meeting->companies(), $company->getKey()) && $countsTowardIntelligence) {
                        $counted[] = $company;
                    }
                }
            }

            if ($wouldCreatePerson) {
                $person = $this->autoCreatePerson->execute(
                    $attendee->name ?? '',
                    $attendee->email_address,
                    $workspaceId,
                    $workspace,
                    $company?->getKey(),
                );
            }

            if ($person instanceof People) {
                $attendee->update(['contact_id' => $person->getKey()]);
                if ($this->autoAttach($meeting->people(), $person->getKey()) && $countsTowardIntelligence) {
                    $counted[] = $person;
                }

                $personCompany = $person->company;

                if ($personCompany instanceof Company
                    && $this->autoAttach($meeting->companies(), $personCompany->getKey())
                    && $countsTowardIntelligence) {
                    $counted[] = $personCompany;
                }

                $attached = $this->attachOpportunitiesOf($person, $meeting);
                $counted = $countsTowardIntelligence ? [...$counted, ...$attached] : $counted;
            }
        }

        $this->metrics->incrementMeetingMetricsInLockOrder($counted, $meeting);
    }

    /**
     * @return Collection<int, Opportunity>
     */
    private function attachOpportunitiesOf(People $person, Meeting $meeting): Collection
    {
        return Opportunity::query()
            ->where('workspace_id', $meeting->workspace_id)
            ->where('contact_id', $person->getKey())
            ->get()
            ->filter(fn (Opportunity $opportunity): bool => $this->autoAttach($meeting->opportunities(), $opportunity->getKey()));
    }

    private function shouldCreatePerson(Workspace $workspace): bool
    {
        return match ($workspace->contact_creation_mode) {
            ContactCreationMode::All, ContactCreationMode::Selective => true,
            ContactCreationMode::None => false,
        };
    }

    /**
     * Attaches a record to the meeting via the given morph relation if not already linked.
     * Returns true only when a new pivot row was created, so callers can gate one-shot side
     * effects (metric increments, activity logs) against re-sync of the same event.
     *
     * @template TRelated of Model
     *
     * @param  MorphToMany<TRelated, Meeting>  $relation
     */
    private function autoAttach(MorphToMany $relation, string $relatedId): bool
    {
        // Only attach (with link_source 'auto') when the record isn't already
        // linked. Never touch an existing pivot, so a prior manual link keeps
        // its 'manual' source instead of being silently downgraded to 'auto'.
        if ($relation->whereKey($relatedId)->exists()) {
            return false;
        }

        $relation->attach($relatedId, ['link_source' => 'auto']);

        return true;
    }

    private function extractDomain(string $email): ?string
    {
        $parts = explode('@', $email);

        return count($parts) === 2 ? strtolower($parts[1]) : null;
    }
}
