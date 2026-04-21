<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Contracts\Database\Query\Builder;
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
                    $meeting->companies()->syncWithoutDetaching([
                        $company->getKey() => ['link_source' => 'auto'],
                    ]);
                    $this->updateCompanyMetrics($company, $meeting);
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
                $meeting->people()->syncWithoutDetaching([
                    $person->getKey() => ['link_source' => 'auto'],
                ]);
                $this->updatePersonMetrics($person, $meeting);

                if ($person->company_id) {
                    $meeting->companies()->syncWithoutDetaching([
                        $person->company_id => ['link_source' => 'auto'],
                    ]);
                }

                $opportunities = Opportunity::query()->where('team_id', $teamId)
                    ->where('contact_id', $person->getKey())
                    ->get();

                foreach ($opportunities as $opportunity) {
                    $meeting->opportunities()->syncWithoutDetaching([
                        $opportunity->getKey() => ['link_source' => 'auto'],
                    ]);
                    $this->updateOpportunityMetrics($opportunity, $meeting);
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

    private function updatePersonMetrics(People $person, Meeting $meeting): void
    {
        $person->updateQuietly([
            'meeting_count' => DB::raw('meeting_count + 1'),
            'last_meeting_at' => $meeting->starts_at,
            'last_interaction_at' => $meeting->starts_at,
        ]);
    }

    private function updateCompanyMetrics(Company $company, Meeting $meeting): void
    {
        $company->updateQuietly([
            'meeting_count' => DB::raw('meeting_count + 1'),
            'last_meeting_at' => $meeting->starts_at,
            'last_interaction_at' => $meeting->starts_at,
        ]);
    }

    private function updateOpportunityMetrics(Opportunity $opportunity, Meeting $meeting): void
    {
        $opportunity->updateQuietly([
            'meeting_count' => DB::raw('meeting_count + 1'),
            'last_meeting_at' => $meeting->starts_at,
            'last_interaction_at' => $meeting->starts_at,
        ]);
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
