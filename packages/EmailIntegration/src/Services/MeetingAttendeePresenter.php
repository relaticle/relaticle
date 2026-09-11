<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\People;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;

final readonly class MeetingAttendeePresenter
{
    public function __construct(
        private TeamMemberDirectory $teamMembers,
        private MailboxDisplayNameDirectory $mailboxNames,
        private AvatarService $avatars,
    ) {}

    /**
     * @return array{name: string, email: string, avatar: string, has_name: bool, is_organizer: bool, response_status: AttendeeResponseStatus|null}
     */
    public function present(MeetingAttendee $attendee): array
    {
        $contact = $attendee->relationLoaded('contact')
            ? $attendee->getRelation('contact')
            : $attendee->contact;
        $email = Str::lower(trim((string) $attendee->email_address));
        $mailboxPerson = $attendee->is_self ? $this->mailboxPerson($attendee) : null;
        $member = $email !== '' ? $this->teamMember($attendee, $email) : null;
        $contactName = $contact instanceof People ? $this->usableName($contact->name, $email) : null;
        $selfName = $mailboxPerson !== null ? $this->usableName($mailboxPerson['name'], $email) : null;
        $memberName = $member !== null ? $this->usableName($member['name'], $email) : null;
        $calendarName = $this->usableName($attendee->name, $email);
        $mailboxName = $email !== '' ? $this->mailboxName($email) : null;
        $named = $contactName
            ?? $selfName
            ?? $memberName
            ?? $calendarName
            ?? $mailboxName;

        $name = $named ?? ($email !== '' ? $email : __('filament/resources/meeting.attendees.guest'));

        $avatar = ($contact instanceof People ? $contact->avatar : null)
            ?? ($mailboxPerson !== null ? $mailboxPerson['avatar'] : null)
            ?? ($member !== null ? $member['avatar'] : null)
            ?? ($named !== null ? $this->avatars->generateAuto($named) : '');

        return [
            'name' => $name,
            'email' => $email,
            'avatar' => $avatar,
            'has_name' => $named !== null,
            'is_organizer' => $attendee->is_organizer,
            'response_status' => $attendee->response_status,
        ];
    }

    /**
     * @return array{name: string, avatar: string|null}|null
     */
    private function mailboxPerson(MeetingAttendee $attendee): ?array
    {
        $attendee->loadMissing('meeting.connectedAccount.user');

        $meeting = $attendee->relationLoaded('meeting')
            ? $attendee->getRelation('meeting')
            : null;

        if (! $meeting instanceof Meeting) {
            return null;
        }

        $account = $meeting->relationLoaded('connectedAccount')
            ? $meeting->getRelation('connectedAccount')
            : null;

        if (! $account instanceof ConnectedAccount) {
            return null;
        }

        $user = $account->relationLoaded('user')
            ? $account->getRelation('user')
            : null;

        if (! $user instanceof User) {
            return null;
        }

        $mailboxEmail = Str::lower(trim($account->email_address));
        $userEmail = Str::lower(trim($user->email));

        if ($mailboxEmail === '' || $mailboxEmail !== $userEmail || trim($user->name) === '') {
            return null;
        }

        return [
            'name' => trim($user->name),
            'avatar' => $user->profile_photo_url,
        ];
    }

    /**
     * @return array{name: string, avatar: string|null}|null
     */
    private function teamMember(MeetingAttendee $attendee, string $email): ?array
    {
        $teamId = $this->teamId($attendee);

        if ($teamId === null) {
            return null;
        }

        return $this->teamMembers->find($teamId, $email);
    }

    private function mailboxName(string $email): ?string
    {
        $viewer = auth()->user();

        if (! $viewer instanceof User) {
            return null;
        }

        return $this->mailboxNames->find($viewer, $email);
    }

    private function usableName(mixed $name, string $email): ?string
    {
        $trimmed = trim((string) $name);

        if ($trimmed === '' || Str::lower($trimmed) === $email) {
            return null;
        }

        return $trimmed;
    }

    private function teamId(MeetingAttendee $attendee): ?string
    {
        if ($attendee->relationLoaded('meeting')) {
            $meeting = $attendee->getRelation('meeting');

            if ($meeting instanceof Meeting) {
                return (string) $meeting->team_id;
            }
        }

        $user = auth()->user();

        if ($user instanceof User && $user->currentTeam !== null) {
            return (string) $user->currentTeam->getKey();
        }

        return null;
    }
}
