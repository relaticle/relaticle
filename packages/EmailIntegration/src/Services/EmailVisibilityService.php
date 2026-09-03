<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;

final class EmailVisibilityService
{
    /**
     * @var array<string, Collection<int, TeamEmailBlocklist>>
     */
    private array $workspaceEntryCache = [];

    /**
     * @var array<string, list<lowercase-string>>
     */
    private array $workspaceDomainCache = [];

    /**
     * @var array<string, list<lowercase-string>>
     */
    private array $teamMemberEmailCache = [];

    public function isHiddenFromOwner(Email $email): bool
    {
        if ($this->matchesAccountBlocklist($email)) {
            return true;
        }

        return $this->matchesWorkspaceEnforcement($email, EmailVisibilityEnforcement::Blocked);
    }

    public function isHiddenFromTeammate(Email $email): bool
    {
        if ($email->is_internal) {
            return true;
        }

        if ($this->matchesWorkspaceEnforcement($email, EmailVisibilityEnforcement::Blocked)) {
            return true;
        }

        return $this->allParticipantsAreProtected($email);
    }

    /**
     * @return list<array{key: string, address: string, enforcement: string, enforcement_value: string, source: string, is_system: bool, entry_id?: string, updated_at?: string}>
     */
    public function visibilityTableRows(Team $team, Collection $customEntries): array
    {
        $systemRows = [
            [
                'key' => 'system-members',
                'address' => __('filament/pages/email-privacy-settings.visibility.table.members_row'),
                'enforcement' => EmailVisibilityEnforcement::Protected->getLabel(),
                'enforcement_value' => EmailVisibilityEnforcement::Protected->value,
                'source' => __('filament/pages/email-privacy-settings.visibility.table.system_default'),
                'is_system' => true,
            ],
        ];

        foreach ($this->workspaceDomains($team) as $domain) {
            $systemRows[] = [
                'key' => 'system-domain-'.$domain,
                'address' => $domain,
                'enforcement' => EmailVisibilityEnforcement::Protected->getLabel(),
                'enforcement_value' => EmailVisibilityEnforcement::Protected->value,
                'source' => __('filament/pages/email-privacy-settings.visibility.table.system_default'),
                'is_system' => true,
            ];
        }

        $customRows = $customEntries
            ->map(function (TeamEmailBlocklist $entry): array {
                $enforcement = $entry->enforcement_level ?? EmailVisibilityEnforcement::Blocked;

                return [
                    'key' => 'custom-'.$entry->getKey(),
                    'address' => $entry->value,
                    'enforcement' => $enforcement->getLabel(),
                    'enforcement_value' => $enforcement->value,
                    'source' => $entry->creator?->name ?? __('filament/pages/email-privacy-settings.visibility.table.unknown_user'),
                    'is_system' => false,
                    'entry_id' => $entry->getKey(),
                    'updated_at' => $entry->updated_at?->toFormattedDateString(),
                ];
            })
            ->all();

        return [...$systemRows, ...$customRows];
    }

    /**
     * @return list<lowercase-string>
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

        $statuses = $email->participants
            ->map(fn ($participant): ?EmailVisibilityEnforcement => $this->participantEnforcement(
                (string) $participant->email_address,
                $email->team_id,
            ))
            ->filter();

        if ($statuses->isEmpty()) {
            return false;
        }

        return $statuses->every(
            fn (?EmailVisibilityEnforcement $enforcement): bool => $enforcement === EmailVisibilityEnforcement::Protected,
        );
    }

    private function matchesWorkspaceEnforcement(Email $email, EmailVisibilityEnforcement $level): bool
    {
        $email->loadMissing('participants');

        foreach ($email->participants as $participant) {
            if ($this->matchesCustomEntry((string) $participant->email_address, $email->team_id, $level)) {
                return true;
            }
        }

        return false;
    }

    private function matchesAccountBlocklist(Email $email): bool
    {
        if ($email->connected_account_id === null) {
            return false;
        }

        $email->loadMissing('participants');

        $rows = EmailBlocklist::query()
            ->where('connected_account_id', $email->connected_account_id)
            ->get();

        foreach ($email->participants as $participant) {
            if ($this->matchesRows((string) $participant->email_address, $rows)) {
                return true;
            }
        }

        return false;
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
            ->filter(fn (mixed $row): bool => ($row->type instanceof EmailBlocklistType ? $row->type : EmailBlocklistType::from((string) $row->type)) === EmailBlocklistType::EMAIL)
            ->pluck('value')
            ->map(fn (mixed $value): string => strtolower((string) $value));
        $domains = $rows
            ->filter(fn (mixed $row): bool => ($row->type instanceof EmailBlocklistType ? $row->type : EmailBlocklistType::from((string) $row->type)) === EmailBlocklistType::DOMAIN)
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
     * @return list<lowercase-string>
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
     * @return list<lowercase-string>
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
