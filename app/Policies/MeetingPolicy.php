<?php

declare(strict_types=1);

namespace App\Policies;

use App\Features\EmailIntegration;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

final readonly class MeetingPolicy
{
    use HandlesAuthorization;

    public function __construct(private EmailVisibilityService $visibility) {}

    public function viewAny(User $user): bool
    {
        if (! Feature::active(EmailIntegration::class)) {
            return false;
        }

        return $user->hasVerifiedEmail() && $user->currentTeam !== null;
    }

    public function view(User $user, Meeting $meeting): bool
    {
        if (! $user->belongsToTeamId($meeting->team_id)) {
            return false;
        }

        return ! $this->visibility->isMeetingHiddenFromViewer($meeting, $user);
    }

    public function respond(User $user, Meeting $meeting): bool
    {
        if (! Feature::active(EmailIntegration::class)) {
            return false;
        }

        if (! $this->view($user, $meeting)) {
            return false;
        }

        $account = $meeting->connectedAccount;

        if (! $account instanceof ConnectedAccount) {
            return false;
        }

        if ($account->user_id !== $user->getKey()) {
            return false;
        }

        if (! $account->isActive() || ! $account->hasCalendar()) {
            return false;
        }

        if ($meeting->status === CalendarEventStatus::CANCELLED) {
            return false;
        }

        $self = $meeting->attendees->first(
            fn (MeetingAttendee $attendee): bool => $attendee->is_self
                || $attendee->email_address === strtolower($account->email_address),
        );

        return $self instanceof MeetingAttendee;
    }
}
