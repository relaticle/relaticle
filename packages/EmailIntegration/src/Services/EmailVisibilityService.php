<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
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
     * Emails this viewer may see on the record. The denormalized `email_count`
     * column is unscoped and would leak private mail.
     */
    public function visibleEmailCount(Company|Opportunity|People $record, User $viewer): int
    {
        if ($this->hidesRecordMailbox($record)) {
            return 0;
        }

        return $record
            ->emails()
            ->withGlobalScope('visible', new VisibleEmailScope($viewer))
            ->count();
    }

    public function visibleEmailCountBadge(Company|Opportunity|People $record, User $viewer): ?string
    {
        $count = $this->visibleEmailCount($record, $viewer);

        if ($count < 1) {
            return null;
        }

        if ($count > 99) {
            return '99+';
        }

        return (string) $count;
    }

    /**
     * Protected people and companies keep their mailbox empty on the record page,
     * even when mixed threads with unprotected contacts stay visible elsewhere.
     */
    public function hidesRecordMailbox(Model $record): bool
    {
        if (! $record instanceof People && ! $record instanceof Company) {
            return false;
        }

        return $this->recordMailboxHiddenEnforcement($record) instanceof EmailVisibilityEnforcement;
    }

    public function recordMailboxHiddenEnforcement(People|Company $record): ?EmailVisibilityEnforcement
    {
        $teamId = (string) $record->team_id;
        $isProtected = false;

        foreach ($this->recordIdentityAddresses($record) as $address) {
            $enforcement = $this->participantEnforcement($address, $teamId);

            if ($enforcement === EmailVisibilityEnforcement::Blocked) {
                return EmailVisibilityEnforcement::Blocked;
            }

            if ($enforcement === EmailVisibilityEnforcement::Protected) {
                $isProtected = true;
            }
        }

        foreach ($this->recordIdentityDomains($record) as $domain) {
            $enforcement = $this->participantEnforcement("mailbox@{$domain}", $teamId);

            if ($enforcement === EmailVisibilityEnforcement::Blocked) {
                return EmailVisibilityEnforcement::Blocked;
            }

            if ($enforcement === EmailVisibilityEnforcement::Protected) {
                $isProtected = true;
            }
        }

        return $isProtected ? EmailVisibilityEnforcement::Protected : null;
    }

    /**
     * @return array{heading: string, description: string}|null
     */
    public function recordMailboxHiddenCopy(People|Company $record): ?array
    {
        $enforcement = $this->recordMailboxHiddenEnforcement($record);

        if (! $enforcement instanceof EmailVisibilityEnforcement) {
            return null;
        }

        $key = match ($enforcement) {
            EmailVisibilityEnforcement::Blocked => 'blocked',
            EmailVisibilityEnforcement::Protected => 'protected',
        };

        return [
            'heading' => (string) __("filament/pages/record-emails.{$key}.heading"),
            'description' => (string) __("filament/pages/record-emails.{$key}.description"),
        ];
    }

    /**
     * Hidden communications must not advance shared communication-intelligence metrics.
     */
    public function countsTowardCommunicationIntelligence(Email $email): bool
    {
        if ($this->isHiddenFromOwner($email)) {
            return false;
        }

        if ($email->is_internal) {
            return false;
        }

        return ! $this->allParticipantsAreProtected($email);
    }

    public function meetingCountsTowardCommunicationIntelligence(Meeting $meeting): bool
    {
        $meeting->loadMissing(['attendees', 'connectedAccount']);

        $addresses = $meeting->attendees
            ->pluck('email_address')
            ->filter()
            ->values()
            ->all();

        if (filled($meeting->organizer_email)) {
            $addresses[] = $meeting->organizer_email;
        }

        if ($this->isHiddenFromOwnerFor(
            (string) $meeting->team_id,
            $meeting->connected_account_id,
            $addresses,
        )) {
            return false;
        }

        return ! $this->allAddressesAreProtected($addresses, (string) $meeting->team_id);
    }

    public function normalizeDomainInput(string $value): ?string
    {
        return $this->hostFromDomainValue($value);
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

        $workspaceDomains = $this->workspaceDomains($team);
        $memberEmails = $this->memberEmailsForTeam($team);

        foreach ($workspaceDomains as $domain) {
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
            ->reject(function (TeamEmailBlocklist $entry) use ($workspaceDomains, $memberEmails): bool {
                $value = strtolower($entry->value);

                return match ($entry->type) {
                    EmailBlocklistType::DOMAIN => in_array($value, $workspaceDomains, true),
                    EmailBlocklistType::EMAIL => in_array($value, $memberEmails, true),
                };
            })
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

    /**
     * @return list<string>
     */
    private function recordIdentityAddresses(People|Company $record): array
    {
        if (! $record instanceof People) {
            return [];
        }

        $field = $this->customFieldFor((string) $record->team_id, 'people', PeopleField::EMAILS->value);

        if (! $field instanceof CustomField) {
            return [];
        }

        $record->loadMissing('customFieldValues.customField');

        return $this->stringList($record->getCustomFieldValue($field));
    }

    /**
     * @return list<string>
     */
    private function recordIdentityDomains(People|Company $record): array
    {
        if (! $record instanceof Company) {
            return [];
        }

        $field = $this->customFieldFor((string) $record->team_id, 'company', CompanyField::DOMAINS->value);

        if (! $field instanceof CustomField) {
            return [];
        }

        $record->loadMissing('customFieldValues.customField');

        $hosts = [];

        foreach ($this->stringList($record->getCustomFieldValue($field)) as $value) {
            $host = $this->hostFromDomainValue($value);

            if ($host !== null) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    private function customFieldFor(string $teamId, string $entityType, string $code): ?CustomField
    {
        $field = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->first();

        return $field instanceof CustomField ? $field : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(
            Arr::wrap($value),
            fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }

    private function hostFromDomainValue(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = (string) Str::of($value)->replaceStart('https://', '')->replaceStart('http://', '');

        if (str_starts_with($value, '@')) {
            $value = substr($value, 1);
        }

        $host = Str::before(Str::before($value, '/'), ':');

        if ($host === '' || ! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
            return null;
        }

        return $host;
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

        $emails = $team->allUsers()
            ->pluck('email')
            ->map(fn (string $email): string => strtolower($email));

        ConnectedAccount::query()
            ->where('team_id', $teamId)
            ->pluck('email_address')
            ->each(function (mixed $emailAddress) use ($emails): void {
                $normalized = strtolower(trim((string) $emailAddress));

                if ($normalized !== '') {
                    $emails->push($normalized);
                }
            });

        TeamInvitation::query()
            ->where('team_id', $teamId)
            ->pluck('email')
            ->each(function (mixed $emailAddress) use ($emails): void {
                $normalized = strtolower(trim((string) $emailAddress));

                if ($normalized !== '') {
                    $emails->push($normalized);
                }
            });

        return $this->teamMemberEmailCache[$teamId] = $emails
            ->unique()
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
