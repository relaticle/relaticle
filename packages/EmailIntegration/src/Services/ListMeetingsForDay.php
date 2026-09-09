<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\Scopes\VisibleMeetingScope;

final readonly class ListMeetingsForDay
{
    /**
     * @return Collection<int, Meeting>
     */
    public function execute(User $user, CarbonImmutable $day): Collection
    {
        $timezone = $user->effectiveTimezone();
        $startUtc = $day->timezone($timezone)->startOfDay()->utc();
        $endUtc = $day->timezone($timezone)->endOfDay()->utc();

        $query = Meeting::query()
            ->withGlobalScope('visible', new VisibleMeetingScope($user))
            ->whereBetween('starts_at', [$startUtc, $endUtc]);

        $identity = "COALESCE('uid:' || NULLIF(meetings.ical_uid, ''), 'id:' || meetings.id)";
        $copies = (clone $query)
            ->select('meetings.id')
            ->distinct([DB::raw($identity), 'meetings.starts_at'])
            ->reorder()
            ->orderByRaw($identity)
            ->oldest('meetings.starts_at')
            ->orderByRaw('CASE WHEN meetings.connected_account_id IN (SELECT id FROM connected_accounts WHERE user_id = ?) THEN 0 ELSE 1 END', [$user->getKey()])
            ->orderBy('meetings.id');

        return $query
            ->whereIn('meetings.id', $copies)
            ->with(['team', 'attendees.contact', 'connectedAccount'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }
}
