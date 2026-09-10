<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\Meeting;

final readonly class MeetingParticipantStackPresenter
{
    public function __construct(
        private MeetingAttendeePresenter $attendeePresenter,
    ) {}

    /**
     * @return array{
     *     attendees: list<array{name: string, email: string, avatar: string, has_name: bool, is_organizer: bool, response_status: AttendeeResponseStatus|null}>,
     *     avatars: list<array{src: string, alt: string, has_name: bool, tooltip: string}>,
     *     overflow: int,
     *     overflow_tooltip: string|null
     * }
     */
    public function forMeeting(Meeting $meeting, int $visible = 3): array
    {
        $states = $this->uniqueAttendeeStates($meeting);
        $visibleStates = array_slice($states, 0, $visible);
        $overflowStates = array_slice($states, $visible);

        return [
            'attendees' => $states,
            'avatars' => array_map(
                fn (array $state): array => [
                    'src' => $state['avatar'],
                    'alt' => $state['name'],
                    'has_name' => $state['has_name'],
                    'tooltip' => $this->tooltip($state),
                ],
                $visibleStates,
            ),
            'overflow' => count($overflowStates),
            'overflow_tooltip' => $overflowStates === []
                ? null
                : implode("\n", array_map(
                    fn (array $state): string => $this->tooltip($state),
                    $overflowStates,
                )),
        ];
    }

    /**
     * @param  array{name: string, email: string, avatar: string, has_name: bool, is_organizer: bool, response_status: AttendeeResponseStatus|null}  $state
     */
    private function tooltip(array $state): string
    {
        $email = $state['email'];
        $name = $state['name'];

        if ($state['has_name'] && $email !== '' && mb_strtolower($name) !== $email) {
            return "{$name}\n{$email}";
        }

        if ($email !== '') {
            return $email;
        }

        return $name;
    }

    /**
     * @return list<array{name: string, email: string, avatar: string, has_name: bool, is_organizer: bool, response_status: AttendeeResponseStatus|null}>
     */
    private function uniqueAttendeeStates(Meeting $meeting): array
    {
        $states = [];

        foreach ($meeting->attendees as $attendee) {
            $state = $this->attendeePresenter->present($attendee);
            $key = $state['email'] !== '' ? $state['email'] : $state['name'];

            if (! isset($states[$key])) {
                $states[$key] = $state;
            }
        }

        return array_values($states);
    }
}
