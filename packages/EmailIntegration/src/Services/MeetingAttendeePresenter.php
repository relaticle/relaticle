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
        private AvatarService $avatars,
        private TeamMemberDirectory $teamMembers,
    ) {}

    /**
     * @return array{name: string, email: string, avatar: string, is_organizer: bool, response_status: AttendeeResponseStatus|null}
     */
    public function present(MeetingAttendee $attendee): array
    {
        $contact = $attendee->relationLoaded('contact')
            ? $attendee->getRelation('contact')
            : $attendee->contact;
        $contactName = $contact instanceof People ? trim((string) $contact->name) : '';
        $calendarName = trim((string) ($attendee->name ?? ''));
        $email = Str::lower(trim((string) $attendee->email_address));
        $mailboxPerson = $attendee->is_self ? $this->mailboxPerson($attendee) : null;
        $member = $email !== '' ? $this->teamMember($attendee, $email) : null;

        $name = match (true) {
            $contactName !== '' => $contactName,
            $mailboxPerson !== null => $mailboxPerson['name'],
            $member !== null => $member['name'],
            $calendarName !== '' && Str::lower($calendarName) !== $email => $calendarName,
            // Never invent a name. A title-cased local part reads like a real
            // person we know, and we do not know them. Show the address.
            $email !== '' => $email,
            default => __('filament/resources/meeting.attendees.guest'),
        };

        $avatar = ($contact instanceof People ? $contact->avatar : null)
            ?? ($mailboxPerson !== null ? $mailboxPerson['avatar'] : null)
            ?? ($member !== null ? $member['avatar'] : null)
            ?? $this->avatars->generateAuto(name: $name, initialCount: 2);

        return [
            'name' => $name,
            'email' => $email,
            'avatar' => $avatar,
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
        $name = $user instanceof User && trim($user->name) !== ''
            ? trim($user->name)
            : trim((string) ($account->display_name ?? ''));

        if ($name === '' || Str::lower($name) === Str::lower(trim($account->email_address))) {
            return null;
        }

        return [
            'name' => $name,
            'avatar' => $user instanceof User ? $user->profile_photo_url : null,
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
