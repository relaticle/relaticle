<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;

final readonly class MeetingRespondentResolver
{
    public function canRespond(User $user, Meeting $meeting): bool
    {
        return $this->resolveAccount($user, $meeting) instanceof ConnectedAccount;
    }

    public function resolveAccount(User $user, Meeting $meeting): ?ConnectedAccount
    {
        $accounts = ConnectedAccount::query()
            ->where('team_id', $meeting->team_id)
            ->where('user_id', $user->getKey())
            ->get();

        $ownerAccount = $accounts->first(
            fn (ConnectedAccount $account): bool => $account->getKey() === $meeting->connected_account_id
                && $account->isActive()
                && $account->hasCalendar(),
        );

        if ($ownerAccount instanceof ConnectedAccount) {
            return $ownerAccount;
        }

        $listedAttendeeEmails = $this->listedAttendeeEmailsForUser($user, $meeting);

        if ($listedAttendeeEmails === []) {
            return null;
        }

        return $accounts->first(
            fn (ConnectedAccount $account): bool => $account->isActive()
                && $account->hasCalendar()
                && in_array(strtolower($account->email_address), $listedAttendeeEmails, true),
        );
    }

    /**
     * @return array<int, lowercase-string>
     */
    public function identityEmailsForUser(User $user, string $teamId): array
    {
        $emails = collect([strtolower($user->email)]);

        ConnectedAccount::query()
            ->where('team_id', $teamId)
            ->where('user_id', $user->getKey())
            ->pluck('email_address')
            ->each(function (mixed $emailAddress) use ($emails): void {
                $normalized = strtolower(trim((string) $emailAddress));

                if ($normalized !== '') {
                    $emails->push($normalized);
                }
            });

        return $emails->unique()->values()->all();
    }

    /**
     * @return array<int, lowercase-string>
     */
    public function listedAttendeeEmailsForUser(User $user, Meeting $meeting): array
    {
        $identityEmails = $this->identityEmailsForUser($user, (string) $meeting->team_id);

        if ($identityEmails === []) {
            return [];
        }

        return $meeting->attendees()
            ->whereIn(DB::raw('lower(email_address)'), $identityEmails)
            ->pluck('email_address')
            ->map(fn (mixed $emailAddress): string => strtolower((string) $emailAddress))
            ->unique()
            ->values()
            ->all();
    }

    public function listedAttendeeEmailForUser(User $user, Meeting $meeting): ?string
    {
        $listedAttendeeEmails = $this->listedAttendeeEmailsForUser($user, $meeting);

        if ($listedAttendeeEmails === []) {
            return null;
        }

        $workspaceEmail = strtolower($user->email);

        if (in_array($workspaceEmail, $listedAttendeeEmails, true)) {
            return $workspaceEmail;
        }

        return $listedAttendeeEmails[0];
    }

    public function userWorkspaceEmailIsListedAttendee(User $user, Meeting $meeting): bool
    {
        return $meeting->attendees()
            ->whereRaw('lower(email_address) = ?', [strtolower($user->email)])
            ->exists();
    }

    public function userIdentityIsListedAttendee(User $user, Meeting $meeting): bool
    {
        return $this->listedAttendeeEmailsForUser($user, $meeting) !== [];
    }

    public function viewerResponseStatus(User $user, Meeting $meeting): AttendeeResponseStatus
    {
        $meeting->loadMissing('connectedAccount');

        if ($meeting->connectedAccount?->user_id === $user->getKey()) {
            return $meeting->response_status ?? AttendeeResponseStatus::NEEDS_ACTION;
        }

        $userEmail = strtolower($user->email);

        $attendeeStatus = $meeting->attendees()
            ->whereRaw('lower(email_address) = ?', [$userEmail])
            ->value('response_status');

        if ($attendeeStatus instanceof AttendeeResponseStatus) {
            return $attendeeStatus;
        }

        if (is_string($attendeeStatus)) {
            return AttendeeResponseStatus::tryFrom($attendeeStatus) ?? AttendeeResponseStatus::NEEDS_ACTION;
        }

        $identityEmails = $this->identityEmailsForUser($user, (string) $meeting->team_id);

        if ($identityEmails === []) {
            return AttendeeResponseStatus::NEEDS_ACTION;
        }

        $identityStatus = $meeting->attendees()
            ->whereIn(DB::raw('lower(email_address)'), $identityEmails)
            ->value('response_status');

        if (is_string($identityStatus)) {
            return AttendeeResponseStatus::tryFrom($identityStatus) ?? AttendeeResponseStatus::NEEDS_ACTION;
        }

        if ($identityStatus instanceof AttendeeResponseStatus) {
            return $identityStatus;
        }

        return AttendeeResponseStatus::NEEDS_ACTION;
    }

    public function resolveProviderEventId(ConnectedAccount $account, Meeting $meeting): ?string
    {
        if ($this->ownsMeetingMailbox($account, $meeting)) {
            return $meeting->provider_event_id;
        }

        if (blank($meeting->ical_uid)) {
            return null;
        }

        // Google recurring occurrences share ical_uid. Match the viewed start
        // so an RSVP does not land on a different instance in the series.
        $ownCopyEventId = Meeting::query()
            ->where('connected_account_id', $account->getKey())
            ->where('ical_uid', $meeting->ical_uid)
            ->where('starts_at', $meeting->starts_at)
            ->value('provider_event_id');

        if (is_string($ownCopyEventId) && $ownCopyEventId !== '') {
            return $ownCopyEventId;
        }

        return null;
    }

    public function ownsMeetingMailbox(ConnectedAccount $account, Meeting $meeting): bool
    {
        return $account->getKey() === $meeting->connected_account_id;
    }
}
