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
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\RecordCommunicationMetrics;
use Relaticle\EmailIntegration\Support\AutomatedSenderMatcher;
use Relaticle\EmailIntegration\Support\CompanyDomainMatcher;

final readonly class LinkMeetingAction
{
    public function __construct(
        private AutoCreateCompanyAction $autoCreateCompany,
        private AutoCreatePersonAction $autoCreatePerson,
        private CompanyDomainMatcher $domainMatcher,
        private AutomatedSenderMatcher $automatedSender,
        private EmailVisibilityService $visibility,
        private RecordCommunicationMetrics $metrics,
    ) {}

    public function execute(Meeting $meeting): void
    {
        $countsTowardIntelligence = $this->visibility->meetingCountsTowardCommunicationIntelligence($meeting);
        $attendees = $meeting->attendees()->where('is_self', false)->get();
        $teamId = $meeting->team_id;
        $team = $meeting->team;
        $account = $meeting->connectedAccount;
        $skippedDomains = $this->buildSkippedDomains($teamId);

        foreach ($attendees as $attendee) {
            $isAutomatedSender = $this->automatedSender->matches($attendee->email_address);
            $suppressCreate = $this->visibility->suppressesRecordCreation(
                $attendee->email_address,
                $teamId,
                $meeting->connected_account_id,
            );

            $person = People::query()->where('team_id', $teamId)
                ->whereHas('customFieldValues', fn (Builder $valueQuery) => $valueQuery
                    ->whereHas('customField', fn (Builder $fieldQuery) => $fieldQuery->where('type', 'email'))
                    ->whereJsonContains('json_value', $attendee->email_address)
                )
                ->first();

            $wouldCreatePerson = ! $person
                && ! $isAutomatedSender
                && ! $suppressCreate
                && $account
                && $team
                && $this->shouldCreatePerson($team);

            $company = null;
            $rawDomain = $this->extractDomain($attendee->email_address);
            $host = $rawDomain !== null ? $this->domainMatcher->host($rawDomain) : null;

            if ($host && $skippedDomains->doesntContain($host)) {
                $company = $this->domainMatcher->firstMatching($host, $teamId);

                if (! $company && $wouldCreatePerson && $team->auto_create_companies) {
                    $company = $this->autoCreateCompany->execute($host, $teamId, $team);
                }

                if ($company instanceof Company) {
                    $attendee->update(['company_id' => $company->getKey()]);
                    if ($this->autoAttach($meeting->companies(), $company->getKey()) && $countsTowardIntelligence) {
                        $this->metrics->incrementMeetingMetrics($company, $meeting);
                    }
                }
            }

            if ($wouldCreatePerson) {
                $person = $this->autoCreatePerson->execute(
                    $attendee->name ?? '',
                    $attendee->email_address,
                    $teamId,
                    $team,
                    $company?->getKey(),
                );
            }

            if ($person) {
                $attendee->update(['contact_id' => $person->getKey()]);
                if ($this->autoAttach($meeting->people(), $person->getKey()) && $countsTowardIntelligence) {
                    $this->metrics->incrementMeetingMetrics($person, $meeting);
                }

                if ($person->company_id) {
                    $this->autoAttach($meeting->companies(), $person->company_id);
                }

                $opportunities = Opportunity::query()->where('team_id', $teamId)
                    ->where('contact_id', $person->getKey())
                    ->get();

                foreach ($opportunities as $opportunity) {
                    if ($this->autoAttach($meeting->opportunities(), $opportunity->getKey()) && $countsTowardIntelligence) {
                        $this->metrics->incrementMeetingMetrics($opportunity, $meeting);
                    }
                }
            }
        }
    }

    private function shouldCreatePerson(Team $team): bool
    {
        return match ($team->contact_creation_mode) {
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

    /** @return Collection<int, lowercase-string> */
    private function buildSkippedDomains(string $teamId): Collection
    {
        $configDomains = collect((array) config('email-integration.public_domains', []))
            ->map(fn (mixed $d): string => strtolower($this->domainMatcher->host((string) $d)));

        $teamDomains = PublicEmailDomain::query()->where('team_id', $teamId)
            ->pluck('domain')
            ->map(fn (mixed $d): string => strtolower($this->domainMatcher->host((string) $d)));

        return $configDomains->merge($teamDomains)->unique()->values();
    }

    private function extractDomain(string $email): ?string
    {
        $parts = explode('@', $email);

        return count($parts) === 2 ? strtolower($parts[1]) : null;
    }
}
