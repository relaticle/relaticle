<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;

final readonly class RespondToMeetingAction
{
    public function __construct(
        private CalendarServiceFactoryInterface $calendarFactory,
    ) {}

    public function execute(User $user, Meeting $meeting, AttendeeResponseStatus $status): Meeting
    {
        abort_unless($user->can('respond', $meeting), 403);

        if ($status === AttendeeResponseStatus::NEEDS_ACTION) {
            throw new InvalidArgumentException('Cannot reset an RSVP to needsAction.');
        }

        $account = $meeting->connectedAccount;
        abort_unless($account instanceof ConnectedAccount, 403);

        $this->calendarFactory
            ->make($account)
            ->respondToEvent($meeting->provider_event_id, $status);

        $meeting->update(['response_status' => $status]);

        $meeting->attendees()
            ->where(function (Builder $query) use ($account): void {
                $query->where('is_self', true)
                    ->orWhere('email_address', strtolower($account->email_address));
            })
            ->update(['response_status' => $status]);

        return $meeting->refresh()->load(['attendees', 'connectedAccount']);
    }
}
