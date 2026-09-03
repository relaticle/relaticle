<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;

final class EmailVisibilityService
{
    /**
     * @var array<string, Collection<int, TeamEmailBlocklist>>
     */
    private array $workspaceEntryCache = [];

    /**
     * @var array<string, array<int, lowercase-string>>
     */
    private array $workspaceDomainCache = [];

    /**
     * @var array<string, array<int, lowercase-string>>
     */
    private array $teamMemberEmailCache = [];

    /**
     * @var array<string, Collection<int, EmailBlocklist>>
     */
    private array $accountBlocklistCache = [];

    public function isHiddenFromOwner(Email $email): bool
    {
        $email->loadMissing('participants');

        return $this->isHiddenFromOwnerFor(
            (string) $email->team_id,
            $email->connected_account_id,
            $email->participants->pluck('email_address')->all(),
        );
    }

    public function isHiddenFromTeammate(Email $email): bool
    {
        if ($email->is_internal) {
            return true;
        }

        if ($this->isHiddenFromOwner($email)) {
            return true;
        }

        return $this->allParticipantsAreProtected($email);
    }

    public function isMeetingHiddenFromViewer(Meeting $meeting, User $viewer): bool
    {
        $meeting->loadMissing(['attendees', 'connectedAccount']);

        $attendeeAddresses = $meeting->attendees
            ->pluck('email_address')
            ->filter()
            ->values()
            ->all();

        $hideAddresses = $attendeeAddresses;

        if (filled($meeting->organizer_email)) {
            $hideAddresses[] = $meeting->organizer_email;
        }

        $isOwner = $meeting->connectedAccount?->user_id === $viewer->getKey();

        if ($this->isHiddenFromOwnerFor(
            (string) $meeting->team_id,
            $meeting->connected_account_id,
            $hideAddresses,
        )) {
            return true;
        }

        if ($isOwner) {
            return false;
        }

        return $this->allAddressesAreProtected($attendeeAddresses, (string) $meeting->team_id);
    }

    /**
     * Blocked workspace entries and mailbox-only blocklists must not spawn CRM records.
     */
    public function suppressesRecordCreation(string $address, string $teamId, ?string $connectedAccountId): bool
    {
        if ($this->matchesCustomEntry($address, $teamId, EmailVisibilityEnforcement::Blocked)) {
            return true;
        }

        return $this->matchesAccountBlocklistAddress($address, $connectedAccountId);
    }

    /**
     * @param  Collection<int, TeamEmailBlocklist>  $customEntries
     * @return array<int, array{key: string, address: string, enforcement: string, enforcement_value: string, source: string, is_system: bool, entry_id?: string, updated_at?: string}>
     */
    public function visibilityTableRows(Team $team, Collection $customEntries): array
    {
        $systemRows = [
            [
                'key' => 'system-members',
                'address' => (string) __('filament/pages/email-privacy-settings.visibility.table.members_row'),
                'enforcement' => EmailVisibilityEnforcement::Protected->getLabel(),
                'enforcement_value' => EmailVisibilityEnforcement::Protected->value,
                'source' => (string) __('filament/pages/email-privacy-settings.visibility.table.system_default'),
                'is_system' => true,
            ],
        ];

        foreach ($this->workspaceDomains($team) as $domain) {
            $systemRows[] = [
                'key' => 'system-domain-'.$domain,
                'address' => $domain,
                'enforcement' => EmailVisibilityEnforcement::Protected->getLabel(),
                'enforcement_value' => EmailVisibilityEnforcement::Protected->value,
                'source' => (string) __('filament/pages/email-privacy-settings.visibility.table.system_default'),
                'is_system' => true,
            ];
        }

        $customRows = $customEntries
            ->map(function (TeamEmailBlocklist $entry): array {
                $enforcement = $entry->enforcement_level;

                return [
                    'key' => 'custom-'.$entry->getKey(),
                    'address' => $entry->value,
                    'enforcement' => $enforcement->getLabel(),
                    'enforcement_value' => $enforcement->value,
                    'source' => $entry->creator->name,
                    'is_system' => false,
                    'entry_id' => $entry->getKey(),
                    'updated_at' => $entry->updated_at?->toFormattedDateString(),
                ];
            })
            ->all();

        return [...$systemRows, ...$customRows];
    }

    /**
     * @return array<int, lowercase-string>
     */
    public function workspaceDomains(Team $team): array
    {
        $teamId = $team->getKey();

        if (isset($this->workspaceDomainCache[$teamId])) {
            return $this->workspaceDomainCache[$teamId];
        }

        $domains = collect();

        foreach ($team->allUsers() as $user) {
            $domain = $this->domainFromEmail((string) $user->email);

            if ($domain !== null && ! $this->isPublicEmailDomain($domain, $team)) {
                $domains->push($domain);
            }
        }

        ConnectedAccount::query()
            ->where('team_id', $teamId)
            ->pluck('email_address')
            ->each(function (mixed $emailAddress) use ($domains, $team): void {
                $domain = $this->domainFromEmail((string) $emailAddress);

                if ($domain !== null && ! $this->isPublicEmailDomain($domain, $team)) {
                    $domains->push($domain);
                }
            });

        return $this->workspaceDomainCache[$teamId] = $domains
            ->map(fn (string $domain): string => strtolower($domain))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function allParticipantsAreProtected(Email $email): bool
    {
        $email->loadMissing('participants');

        return $this->allAddressesAreProtected(
            $email->participants->pluck('email_address')->all(),
            (string) $email->team_id,
        );
    }

    /**
     * @param  array<int, mixed>  $addresses
     */
    private function allAddressesAreProtected(array $addresses, string $teamId): bool
    {
        if ($addresses === []) {
            return false;
        }

        return array_all(
            $addresses,
            fn (mixed $address): bool => $this->participantEnforcement((string) $address, $teamId) === EmailVisibilityEnforcement::Protected,
        );
    }

    /**
     * @param  array<int, mixed>  $addresses
     */
    private function isHiddenFromOwnerFor(string $teamId, ?string $connectedAccountId, array $addresses): bool
    {
        foreach ($addresses as $address) {
            $normalized = (string) $address;

            if ($this->matchesAccountBlocklistAddress($normalized, $connectedAccountId)) {
                return true;
            }

            if ($this->matchesCustomEntry($normalized, $teamId, EmailVisibilityEnforcement::Blocked)) {
                return true;
            }
        }

        return false;
    }

    private function matchesAccountBlocklistAddress(string $address, ?string $connectedAccountId): bool
    {
        if ($connectedAccountId === null) {
            return false;
        }

        return $this->matchesRows($address, $this->accountBlocklistRows($connectedAccountId));
    }

    /**
     * @return Collection<int, EmailBlocklist>
     */
    private function accountBlocklistRows(string $connectedAccountId): Collection
    {
        return $this->accountBlocklistCache[$connectedAccountId] ??= EmailBlocklist::query()
            ->where('connected_account_id', $connectedAccountId)
            ->get();
    }

    private function participantEnforcement(string $address, string $teamId): ?EmailVisibilityEnforcement
    {
        if ($this->matchesCustomEntry($address, $teamId, EmailVisibilityEnforcement::Blocked)) {
            return EmailVisibilityEnforcement::Blocked;
        }

        if ($this->isTeamMemberEmail($address, $teamId)) {
            return EmailVisibilityEnforcement::Protected;
        }

        $team = Team::query()->find($teamId);

        if ($team !== null) {
            $domain = $this->domainFromEmail($address);

            if ($domain !== null && in_array($domain, $this->workspaceDomains($team), true)) {
                return EmailVisibilityEnforcement::Protected;
            }
        }

        if ($this->matchesCustomEntry($address, $teamId, EmailVisibilityEnforcement::Protected)) {
            return EmailVisibilityEnforcement::Protected;
        }

        return null;
    }

    private function matchesCustomEntry(string $address, string $teamId, EmailVisibilityEnforcement $level): bool
    {
        $rows = $this->workspaceEntries($teamId)
            ->where('enforcement_level', $level);

        return $this->matchesRows($address, $rows);
    }

    /**
     * @param  Collection<int, TeamEmailBlocklist>|Collection<int, EmailBlocklist>  $rows
     */
    private function matchesRows(string $address, Collection $rows): bool
    {
        $address = strtolower(trim($address));
        $domain = $this->domainFromEmail($address) ?? '';

        $emails = $rows
            ->filter(fn (TeamEmailBlocklist|EmailBlocklist $row): bool => $row->type === EmailBlocklistType::EMAIL)
            ->pluck('value')
            ->map(fn (mixed $value): string => strtolower((string) $value));
        $domains = $rows
            ->filter(fn (TeamEmailBlocklist|EmailBlocklist $row): bool => $row->type === EmailBlocklistType::DOMAIN)
            ->pluck('value')
            ->map(fn (mixed $value): string => strtolower((string) $value));

        return $emails->contains($address) || ($domain !== '' && $domains->contains($domain));
    }

    /**
     * @return Collection<int, TeamEmailBlocklist>
     */
    private function workspaceEntries(string $teamId): Collection
    {
        if (isset($this->workspaceEntryCache[$teamId])) {
            return $this->workspaceEntryCache[$teamId];
        }

        return $this->workspaceEntryCache[$teamId] = TeamEmailBlocklist::query()
            ->where('team_id', $teamId)
            ->get();
    }

    /**
     * @return array<int, lowercase-string>
     */
    public function memberEmailsForTeam(Team $team): array
    {
        return $this->teamMemberEmails($team->getKey());
    }

    private function isTeamMemberEmail(string $address, string $teamId): bool
    {
        return in_array(strtolower(trim($address)), $this->teamMemberEmails($teamId), true);
    }

    /**
     * @return array<int, lowercase-string>
     */
    private function teamMemberEmails(string $teamId): array
    {
        if (isset($this->teamMemberEmailCache[$teamId])) {
            return $this->teamMemberEmailCache[$teamId];
        }

        $team = Team::query()->find($teamId);

        if ($team === null) {
            return $this->teamMemberEmailCache[$teamId] = [];
        }

        return $this->teamMemberEmailCache[$teamId] = $team->allUsers()
            ->pluck('email')
            ->map(fn (string $email): string => strtolower($email))
            ->values()
            ->all();
    }

    public function isPublicEmailDomain(string $domain, Team $team): bool
    {
        $normalized = strtolower($domain);

        if (collect((array) config('email-integration.public_domains', []))
            ->map(fn (mixed $value): string => strtolower((string) $value))
            ->contains($normalized)) {
            return true;
        }

        return PublicEmailDomain::query()
            ->where('team_id', $team->getKey())
            ->whereRaw('lower(domain) = ?', [$normalized])
            ->exists();
    }

    private function domainFromEmail(string $email): ?string
    {
        $email = strtolower(trim($email));

        if (! str_contains($email, '@')) {
            return null;
        }

        $domain = Str::afterLast($email, '@');

        return $domain !== '' ? $domain : null;
    }
}
