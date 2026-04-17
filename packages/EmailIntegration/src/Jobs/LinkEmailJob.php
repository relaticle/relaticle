<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\People;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Relaticle\EmailIntegration\Actions\AutoCreateCompanyAction;
use Relaticle\EmailIntegration\Actions\AutoCreatePersonAction;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;

final class LinkEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public function __construct(public readonly Email $email) {}

    public function uniqueId(): string
    {
        return "link-email-{$this->email->getKey()}";
    }

    /**
     * @throws LockTimeoutException
     */
    public function handle(
        AutoCreateCompanyAction $autoCreateCompany,
        AutoCreatePersonAction $autoCreatePerson,
        LinkEmailAction $linkEmail,
    ): void {
        $email = $this->email;
        $teamId = $email->team_id;
        $connectedAccount = $email->connectedAccount;
        $team = $email->team;
        $skippedDomains = $this->buildSkippedDomains($teamId);
        foreach ($email->refresh()->participants()->get() as $participant) {
            $domain = $this->extractDomain($participant->email_address);
            $company = null;

            // Resolve company: cache lock is keyed per team + domain so only one
            // worker creates a company for a given domain. The lock wraps a small
            // standalone operation (no outer transaction), so the re-check always
            // sees committed data from any previously racing worker.
            if ($domain && $skippedDomains->doesntContain($domain)) {
                Cache::lock("link-company:{$teamId}:{$domain}", 30)
                    ->block(15, function () use (&$company, $domain, $teamId, $team, $connectedAccount, $autoCreateCompany): void {
                        $company = $this->findCompanyByDomain($domain, $teamId);

                        if (! $company instanceof Company && $connectedAccount?->auto_create_companies) {
                            $company = $autoCreateCompany->execute($domain, $teamId, $team);
                        }
                    });
            }

            // Resolve person: same pattern, lock keyed per team + email address.
            $person = null;
            $personLockKey = 'link-person:'.$teamId.':'.md5((string) $participant->email_address);

            Cache::lock($personLockKey, 30)
                ->block(15, function () use (&$person, $participant, $teamId, $team, $connectedAccount, $company, $autoCreatePerson): void {
                    $person = $this->findPersonByEmail($participant->email_address, $teamId);

                    if (! $person instanceof People && $connectedAccount && $this->shouldCreatePerson($connectedAccount, $participant->email_address)) {
                        $person = $autoCreatePerson->execute(
                            $participant->name ?? '',
                            $participant->email_address,
                            $teamId,
                            $team,
                            $company?->getKey(),
                        );
                    }
                });
        }

        $linkEmail->execute($email);
    }

    /**
     * Search for a company by domain using two strategies:
     *
     * 1. Custom field value (primary): looks for a stored URL containing the domain.
     * 2. Name + creation source (fallback): handles the case where the `domains`
     *    custom field is not configured for the team.
     */
    private function findCompanyByDomain(string $domain, string $teamId): ?Company
    {
        $byCustomField = Company::query()
            ->where('team_id', $teamId)
            ->whereHas('customFieldValues', fn (Builder $query) => $query->where('string_value', 'like', "%{$domain}%"))
            ->first();

        if ($byCustomField instanceof Company) {
            return $byCustomField;
        }

        return Company::query()
            ->where('team_id', $teamId)
            ->where('name', ucfirst(explode('.', $domain)[0]))
            ->where('creation_source', CreationSource::SYSTEM)
            ->first();
    }

    /**
     * Email values are stored as JSON arrays in json_value (e.g. ["user@example.com"]).
     */
    private function findPersonByEmail(string $emailAddress, string $teamId): ?People
    {
        return People::query()
            ->where('team_id', $teamId)
            ->whereHas('customFieldValues', fn (Builder $query) => $query
                ->whereHas('customField', fn (Builder $query) => $query->where('type', 'email'))
                ->whereJsonContains('json_value', $emailAddress)
            )
            ->first();
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

    private function hasBidirectionalHistory(ConnectedAccount $account, string $emailAddress): bool
    {
        $directions = Email::query()
            ->where('connected_account_id', $account->getKey())
            ->whereHas('participants', fn (Builder $participantQuery) => $participantQuery->where('email_address', $emailAddress))
            ->distinct()
            ->pluck('direction');

        $values = $directions->map(fn (mixed $direction): string => $direction instanceof EmailDirection ? $direction->value : (string) $direction);

        return $values->contains(EmailDirection::INBOUND->value)
            && $values->contains(EmailDirection::OUTBOUND->value);
    }

    /**
     * @return Collection<int, lowercase-string>
     */
    private function buildSkippedDomains(string $teamId): Collection
    {
        $configDomains = collect((array) config('email-integration.public_domains', []))
            ->map(fn (mixed $domain): string => strtolower((string) $domain));

        $teamDomains = PublicEmailDomain::query()
            ->where('team_id', $teamId)
            ->pluck('domain')
            ->map(fn (mixed $domain): string => strtolower((string) $domain));

        return $configDomains->merge($teamDomains)->unique()->values();
    }

    private function extractDomain(string $email): ?string
    {
        $parts = explode('@', $email);

        return count($parts) === 2 ? strtolower($parts[1]) : null;
    }
}
