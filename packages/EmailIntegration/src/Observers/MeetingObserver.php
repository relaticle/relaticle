<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Observers;

use Relaticle\EmailIntegration\Models\Meeting;

final class MeetingObserver
{
    public function created(Meeting $meeting): void
    {
        activity()
            ->performedOn($meeting)
            ->withProperties([
                'title' => $meeting->title,
                'starts_at' => $meeting->starts_at->toIso8601String(),
                'attendee_count' => $meeting->attendees()->count(),
            ])
            ->event('meeting.created')
            ->log('meeting.created');
    }

    public function deleted(Meeting $meeting): void
    {
        activity()
            ->performedOn($meeting)
            ->withProperties(['title' => $meeting->title])
            ->event('meeting.cancelled')
            ->log('meeting.cancelled');
    }
}
