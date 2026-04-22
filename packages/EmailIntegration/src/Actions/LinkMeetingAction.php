<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;

final readonly class LinkMeetingAction
{
    public function __construct(
        private AutoCreateCompanyAction $autoCreateCompany,
        private AutoCreatePersonAction $autoCreatePerson,
    ) {}

    public function execute(Meeting $meeting): void
    {
        $attendees = $meeting->attendees()->where('is_self', false)->get();
        $teamId = $meeting->team_id;
        $team = $meeting->team;
        $account = $meeting->connectedAccount;
        $skippedDomains = $this->buildSkippedDomains($teamId);

        foreach ($attendees as $attendee) {
            $company = null;
            $domain = $this->extractDomain($attendee->email_address);

            if ($domain && $skippedDomains->doesntContain($domain)) {
                $company = Company::query()->where('team_id', $teamId)
                    ->whereHas('customFieldValues', fn (Builder $valueQuery) => $valueQuery
                        ->whereHas('customField', fn (Builder $fieldQuery) => $fieldQuery->where('code', 'domains'))
                        ->whereRaw('json_value::text like ?', ["%{$domain}%"])
                    )
                    ->first();

                if (! $company && $account?->auto_create_companies) {
                    $company = $this->autoCreateCompany->execute($domain, $teamId, $team);
                }

                if ($company) {
                    $attendee->update(['company_id' => $company->getKey()]);
                    if ($this->autoAttach($meeting->companies(), $company->getKey())) {
                        $this->updateCompanyMetrics($company, $meeting);
                    }
                }
            }

            $person = People::query()->where('team_id', $teamId)
                ->whereHas('customFieldValues', fn (Builder $valueQuery) => $valueQuery
                    ->whereHas('customField', fn (Builder $fieldQuery) => $fieldQuery->where('type', 'email'))
                    ->whereJsonContains('json_value', $attendee->email_address)
                )
                ->first();

            if (! $person && $account && $this->shouldCreatePerson($account, $attendee->email_address, $meeting)) {
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
                if ($this->autoAttach($meeting->people(), $person->getKey())) {
                    $this->updatePersonMetrics($person, $meeting);
                }

                if ($person->company_id) {
                    $this->autoAttach($meeting->companies(), $person->company_id);
                }

                $opportunities = Opportunity::query()->where('team_id', $teamId)
                    ->where('contact_id', $person->getKey())
                    ->get();

                foreach ($opportunities as $opportunity) {
                    if ($this->autoAttach($meeting->opportunities(), $opportunity->getKey())) {
                        $this->updateOpportunityMetrics($opportunity, $meeting);
                    }
                }
            }
        }
    }

    private function shouldCreatePerson(ConnectedAccount $account, string $emailAddress, Meeting $currentMeeting): bool
    {
        return match ($account->contact_creation_mode) {
            ContactCreationMode::All => true,
            ContactCreationMode::Bidirectional => $this->hasPriorMeetingWith($account, $emailAddress, $currentMeeting),
            ContactCreationMode::None => false,
        };
    }

    private function hasPriorMeetingWith(ConnectedAccount $account, string $emailAddress, Meeting $currentMeeting): bool
    {
        return Meeting::query()
            ->where('connected_account_id', $account->getKey())
            ->where('id', '!=', $currentMeeting->getKey())
            ->whereHas('attendees', fn (Builder $q) => $q->where('email_address', $emailAddress))
            ->exists();
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
        $result = $relation->syncWithoutDetaching([
            $relatedId => ['link_source' => 'auto'],
        ]);

        return in_array($relatedId, $result['attached'], true);
    }

    private function updatePersonMetrics(People $person, Meeting $meeting): void
    {
        $this->advanceMetrics($person->getTable(), $person->getKey(), $meeting->starts_at);
    }

    private function updateCompanyMetrics(Company $company, Meeting $meeting): void
    {
        $this->advanceMetrics($company->getTable(), $company->getKey(), $meeting->starts_at);
    }

    private function updateOpportunityMetrics(Opportunity $opportunity, Meeting $meeting): void
    {
        $this->advanceMetrics($opportunity->getTable(), $opportunity->getKey(), $meeting->starts_at);
    }

    /**
     * Increments `meeting_count` and monotonically advances `last_meeting_at` / `last_interaction_at`.
     * The timestamps are guarded at the SQL level so parallel workers processing events out of
     * chronological order (e.g. the 90-day backfill) cannot regress a newer value to an older one.
     */
    private function advanceMetrics(string $table, string $id, Carbon $startsAt): void
    {
        DB::table($table)
            ->where('id', $id)
            ->update(['meeting_count' => DB::raw('meeting_count + 1')]);

        $this->advanceTimestamp($table, $id, 'last_meeting_at', $startsAt);
        $this->advanceTimestamp($table, $id, 'last_interaction_at', $startsAt);
    }

    private function advanceTimestamp(string $table, string $id, string $column, Carbon $startsAt): void
    {
        DB::table($table)
            ->where('id', $id)
            ->where(fn (QueryBuilder $q) => $q
                ->whereNull($column)
                ->orWhere($column, '<', $startsAt)
            )
            ->update([$column => $startsAt]);
    }

    /** @return Collection<int, lowercase-string> */
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
}
