<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Exceptions\MeetingResponseFailed;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\MeetingRespondentResolver;

final readonly class RespondToMeetingAction
{
    public function __construct(
        private CalendarServiceFactoryInterface $calendarFactory,
        private MeetingRespondentResolver $respondentResolver,
    ) {}

    public function execute(User $user, Meeting $meeting, AttendeeResponseStatus $status): Meeting
    {
        abort_unless($user->can('respond', $meeting), 403);

        throw_if($status === AttendeeResponseStatus::NEEDS_ACTION, InvalidArgumentException::class, 'Cannot reset an RSVP to needsAction.');

        $account = $this->respondentResolver->resolveAccount($user, $meeting);
        abort_unless($account instanceof ConnectedAccount, 403);

        $calendar = $this->calendarFactory->make($account);
        $providerEventId = $this->respondentResolver->resolveProviderEventId($account, $meeting);
        $iCalUid = $meeting->ical_uid;

        if ($providerEventId === null && is_string($iCalUid) && $iCalUid !== '') {
            $providerEventId = $calendar->findEventIdByICalUid($iCalUid);
        }

        if ($providerEventId === null || $providerEventId === '') {
            throw MeetingResponseFailed::missingMailboxCopy();
        }

        $calendar->respondToEvent($providerEventId, $status);

        if ($this->respondentResolver->ownsMeetingMailbox($account, $meeting)) {
            $this->updateMailboxOwnerResponse($meeting, $account, $status);
        } else {
            $this->updateListedAttendeeResponse($meeting, $user, $status);
        }

        return $meeting->refresh()->load(['attendees.contact', 'connectedAccount']);
    }

    private function updateMailboxOwnerResponse(
        Meeting $meeting,
        ConnectedAccount $account,
        AttendeeResponseStatus $status,
    ): void {
        $meeting->update(['response_status' => $status]);

        $self = $meeting->attendees()
            ->where(function (Builder $query) use ($account): void {
                $query->where('is_self', true)
                    ->orWhere('email_address', strtolower($account->email_address));
            })
            ->first();

        if ($self instanceof MeetingAttendee) {
            $self->update(['response_status' => $status]);

            return;
        }

        $meeting->attendees()->create([
            'email_address' => strtolower($account->email_address),
            'name' => $account->display_name,
            'response_status' => $status,
            'is_organizer' => $meeting->organizer_email !== null
                && strtolower($meeting->organizer_email) === strtolower($account->email_address),
            'is_self' => true,
        ]);
    }

    private function updateListedAttendeeResponse(
        Meeting $meeting,
        User $user,
        AttendeeResponseStatus $status,
    ): void {
        $listedAttendeeEmail = $this->respondentResolver->listedAttendeeEmailForUser($user, $meeting);

        if ($listedAttendeeEmail === null) {
            return;
        }

        $attendee = $meeting->attendees()
            ->whereRaw('lower(email_address) = ?', [$listedAttendeeEmail])
            ->first();

        if ($attendee instanceof MeetingAttendee) {
            $attendee->update(['response_status' => $status]);
        }
    }
}
