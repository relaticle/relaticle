<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;

final readonly class RespondToMeetingAction
{
    public function __construct(
        private CalendarServiceFactoryInterface $calendarFactory,
    ) {}

    public function execute(User $user, Meeting $meeting, AttendeeResponseStatus $status): Meeting
    {
        abort_unless($user->can('respond', $meeting), 403);

        throw_if($status === AttendeeResponseStatus::NEEDS_ACTION, InvalidArgumentException::class, 'Cannot reset an RSVP to needsAction.');

        $account = $meeting->connectedAccount;
        abort_unless($account instanceof ConnectedAccount, 403);

        $this->calendarFactory
            ->make($account)
            ->respondToEvent($meeting->provider_event_id, $status);

        $meeting->update(['response_status' => $status]);

        $self = $meeting->attendees()
            ->where(function (Builder $query) use ($account): void {
                $query->where('is_self', true)
                    ->orWhere('email_address', strtolower($account->email_address));
            })
            ->first();

        if ($self instanceof MeetingAttendee) {
            $self->update(['response_status' => $status]);
        } else {
            $meeting->attendees()->create([
                'email_address' => strtolower($account->email_address),
                'name' => $account->display_name,
                'response_status' => $status,
                'is_organizer' => $meeting->organizer_email !== null
                    && strtolower($meeting->organizer_email) === strtolower($account->email_address),
                'is_self' => true,
            ]);
        }

        return $meeting->refresh()->load(['attendees.contact', 'connectedAccount']);
    }
}
