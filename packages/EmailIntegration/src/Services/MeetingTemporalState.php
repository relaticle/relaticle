<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Models\Meeting;

final readonly class MeetingTemporalState
{
    public function isHappeningNow(Meeting $meeting, string $timezone): bool
    {
        if ($meeting->all_day) {
            return false;
        }

        $now = Date::now($timezone);
        $start = $meeting->starts_at->timezone($timezone);
        $end = $meeting->ends_at->timezone($timezone);

        return $now->gte($start) && $now->lt($end);
    }

    public function isPast(Meeting $meeting, string $timezone): bool
    {
        $now = Date::now($timezone);

        if ($meeting->all_day) {
            return $now->toDateString() > $meeting->ends_at->utc()->toDateString();
        }

        return $now->gte($meeting->ends_at->timezone($timezone));
    }
}
