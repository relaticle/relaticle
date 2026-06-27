<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Observers;

use Relaticle\EmailIntegration\Models\Meeting;

final class MeetingObserver
{
    // meeting.created is logged from StoreMeetingAction once attendees are inserted, so the
    // attendee_count is accurate — the observer's created() fired too early (count was 0).

    public function deleted(Meeting $meeting): void
    {
        activity()
            ->performedOn($meeting)
            ->withProperties(['title' => $meeting->title])
            ->event('meeting.cancelled')
            ->log('meeting.cancelled');
    }
}
