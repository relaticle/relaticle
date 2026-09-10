<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\People;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
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
        $viewer = auth()->user();
        $member = $email !== '' ? $this->teamMember($attendee, $email) : null;
        $contactName = $contact instanceof People ? $this->usableName($contact->name, $email) : null;
        $viewerName = $attendee->is_self && $viewer instanceof User
            ? $this->usableName($viewer->name, $email)
            : null;
        $memberName = $member !== null ? $this->usableName($member['name'], $email) : null;
        $calendarName = $this->usableName($attendee->name, $email);
        $mailboxName = $email !== '' ? $this->mailboxName($attendee, $email) : null;
        $named = $contactName
            ?? $viewerName
            ?? $memberName
            ?? $calendarName
            ?? $mailboxName;

        return [
            'name' => $named ?? ($email !== '' ? $email : __('filament/resources/meeting.attendees.guest')),
            'email' => $email,
            'avatar' => $named !== null ? $this->avatars->generateAuto($named) : '',
            'has_name' => $named !== null,
            'is_organizer' => $attendee->is_organizer,
            'response_status' => $attendee->response_status,
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

    private function mailboxName(MeetingAttendee $attendee, string $email): ?string
    {
        $teamId = $this->teamId($attendee);

        if ($teamId === null) {
            return null;
        }

        return $this->mailboxNames->find($teamId, $email);
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
