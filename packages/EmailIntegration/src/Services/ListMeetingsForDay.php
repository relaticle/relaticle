<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\Scopes\VisibleMeetingScope;

final readonly class ListMeetingsForDay
{
    /**
     * The first local day after $day that holds a meeting the user can see.
     */
    public function nextDayWithMeetings(User $user, CarbonImmutable $day): ?CarbonImmutable
    {
        $timezone = $user->effectiveTimezone();
        $localDay = $day->timezone($timezone)->startOfDay();
        $calendarDate = $localDay->toDateString();
        $endUtc = $localDay->endOfDay()->utc();

        $nextTimed = $this->visibleMeetings($user)
            ->where('all_day', false)
            ->where('starts_at', '>', $endUtc)
            ->reorder()
            ->oldest('starts_at')
            ->value('starts_at');

        $nextAllDay = $this->visibleMeetings($user)
            ->where('all_day', true)
            ->whereDate('starts_at', '>', $calendarDate)
            ->reorder()
            ->oldest('starts_at')
            ->value('starts_at');

        $nextDays = [];

        if ($nextTimed instanceof CarbonImmutable) {
            $nextDays[] = $nextTimed->timezone($timezone)->startOfDay();
        }

        if ($nextAllDay instanceof CarbonImmutable) {
            $nextDays[] = Date::parse($nextAllDay->utc()->toDateString(), $timezone)->startOfDay();
        }

        if ($nextDays === []) {
            return null;
        }

        usort($nextDays, fn (CarbonImmutable $left, CarbonImmutable $right): int => $left <=> $right);

        return $nextDays[0];
    }

    /**
     * @return Collection<int, Meeting>
     */
    public function execute(User $user, CarbonImmutable $day): Collection
    {
        $timezone = $user->effectiveTimezone();
        $localDay = $day->timezone($timezone)->startOfDay();
        $calendarDate = $localDay->toDateString();
        $startUtc = $localDay->utc();
        $endUtc = $localDay->endOfDay()->utc();

        $query = $this->visibleMeetings($user)
            ->where(function (Builder $query) use ($startUtc, $endUtc, $calendarDate): void {
                $query->where(function (Builder $timed) use ($startUtc, $endUtc): void {
                    $timed->where('all_day', false)
                        ->whereBetween('starts_at', [$startUtc, $endUtc]);
                })->orWhere(function (Builder $allDay) use ($calendarDate): void {
                    $allDay->where('all_day', true)
                        ->whereDate('starts_at', '<=', $calendarDate)
                        ->whereDate('ends_at', '>=', $calendarDate);
                });
            });

        $identity = "COALESCE('uid:' || NULLIF(meetings.ical_uid, ''), 'id:' || meetings.id)";
        $copies = (clone $query)
            ->select('meetings.id')
            ->distinct([DB::raw($identity), 'meetings.starts_at'])
            ->reorder()
            ->orderByRaw($identity)
            ->oldest('meetings.starts_at')
            ->orderByRaw('CASE WHEN meetings.connected_account_id IN (SELECT id FROM connected_accounts WHERE user_id = ?) THEN 0 ELSE 1 END', [$user->getKey()])
            ->orderBy('meetings.id');

        $meetings = $query
            ->whereIn('meetings.id', $copies)
            ->with(['team', 'attendees.contact', 'connectedAccount.user'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        resolve(MailboxDisplayNameDirectory::class)->primeFromMeetings($user, $meetings);

        return $meetings;
    }

    /**
     * @return Builder<Meeting>
     */
    private function visibleMeetings(User $user): Builder
    {
        return Meeting::query()
            ->withGlobalScope('visible', VisibleMeetingScope::personal($user));
    }
}
