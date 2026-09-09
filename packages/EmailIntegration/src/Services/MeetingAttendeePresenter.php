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
        private AvatarService $avatars,
        private TeamMemberDirectory $teamMembers,
    ) {}

    /**
     * @return array{name: string, avatar: string, is_organizer: bool, response_status: AttendeeResponseStatus|null}
     */
    public function present(MeetingAttendee $attendee): array
    {
        $contact = $attendee->relationLoaded('contact')
            ? $attendee->getRelation('contact')
            : $attendee->contact;
        $contactName = $contact instanceof People ? trim((string) $contact->name) : '';
        $calendarName = trim((string) ($attendee->name ?? ''));
        $email = Str::lower(trim((string) $attendee->email_address));
        $viewer = auth()->user();
        $member = $email !== '' ? $this->teamMember($attendee, $email) : null;

        $name = match (true) {
            $contactName !== '' => $contactName,
            $attendee->is_self && $viewer instanceof User && trim($viewer->name) !== '' => trim($viewer->name),
            $member !== null => $member['name'],
            $calendarName !== '' && Str::lower($calendarName) !== $email => $calendarName,
            $email !== '' => $this->nameFromEmail($email),
            default => __('filament/resources/meeting.attendees.guest'),
        };

        $avatar = ($contact instanceof People ? $contact->avatar : null)
            ?? ($attendee->is_self && $viewer instanceof User ? $viewer->profile_photo_url : null)
            ?? ($member !== null ? $member['avatar'] : null)
            ?? $this->avatars->generateAuto(name: $name, initialCount: 2);

        return [
            'name' => $name,
            'avatar' => $avatar,
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

    private function nameFromEmail(string $email): string
    {
        $local = Str::before($email, '@');
        $local = Str::before($local, '+');
        $normalized = trim((string) preg_replace('/[._-]+/', ' ', $local));

        if ($normalized === '') {
            return __('filament/resources/meeting.attendees.guest');
        }

        return Str::title($normalized);
    }
}
