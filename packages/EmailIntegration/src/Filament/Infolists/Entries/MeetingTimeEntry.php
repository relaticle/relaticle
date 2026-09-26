<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists\Entries;

use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\Entry;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Models\Meeting;

final class MeetingTimeEntry extends Entry
{
    protected string $view = 'email-integration::filament.infolists.meeting-time';

    /**
     * @return array{start_date: string, start_time: string|null, end_time: string|null, end_date: string|null, duration: string|null, all_day: bool, datetime: string, location: string|null, calendar_url: string|null, calendar_label: string}
     */
    public function getState(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Meeting) {
            return $this->emptyState();
        }

        $user = auth()->user();
        $timezone = $user instanceof User ? $user->effectiveTimezone() : (string) config('app.timezone');

        return $this->present($record, $timezone);
    }

    /**
     * @return array{start_date: string, start_time: string|null, end_time: string|null, end_date: string|null, duration: string|null, all_day: bool, datetime: string, location: string|null, calendar_url: string|null, calendar_label: string}
     */
    private function present(Meeting $meeting, string $timezone): array
    {
        $state = $meeting->all_day
            ? $this->presentAllDay($meeting)
            : $this->presentTimed($meeting, $timezone);

        return $this->withMeta($state, $meeting);
    }

    /**
     * @return array{start_date: string, start_time: string|null, end_time: string|null, end_date: string|null, duration: string|null, all_day: bool, datetime: string}
     */
    private function presentAllDay(Meeting $meeting): array
    {
        $start = Date::parse($meeting->starts_at);
        $inclusiveEnd = Date::parse($meeting->ends_at);

        if ($inclusiveEnd->lt($start)) {
            $inclusiveEnd = $start;
        }

        $crossDay = ! $inclusiveEnd->isSameDay($start);

        return [
            'start_date' => $this->dateLabel($start, $crossDay ? $inclusiveEnd : null),
            'start_time' => null,
            'end_time' => null,
            'end_date' => $crossDay ? $this->dateLabel($inclusiveEnd, $start) : null,
            'duration' => null,
            'all_day' => true,
            'datetime' => $start->toDateString(),
        ];
    }

    /**
     * @return array{start_date: string, start_time: string|null, end_time: string|null, end_date: string|null, duration: string|null, all_day: bool, datetime: string}
     */
    private function presentTimed(Meeting $meeting, string $timezone): array
    {
        $start = Date::parse($meeting->starts_at)->timezone($timezone);
        $end = Date::parse($meeting->ends_at)->timezone($timezone);
        $crossDay = ! $end->isSameDay($start);
        $zeroLength = $end->equalTo($start);

        return [
            'start_date' => $this->dateLabel($start, $crossDay ? $end : null),
            'start_time' => $start->format('g:i A'),
            'end_time' => $zeroLength ? null : $end->format('g:i A'),
            'end_date' => $crossDay ? $this->dateLabel($end, $start) : null,
            'duration' => $zeroLength ? null : $this->compactDuration($start, $end),
            'all_day' => false,
            'datetime' => $start->toIso8601String(),
        ];
    }

    private function dateLabel(CarbonInterface $date, ?CarbonInterface $other): string
    {
        if ($other instanceof CarbonInterface && $date->year !== $other->year) {
            return $date->format('M j, Y');
        }

        return $date->format('M j');
    }

    private function compactDuration(CarbonInterface $start, CarbonInterface $end): string
    {
        $minutes = (int) $start->diffInMinutes($end, true);

        if ($minutes < 60) {
            return $minutes.'m';
        }

        $days = intdiv($minutes, 1440);
        $remainderMinutes = $minutes % 1440;
        $hours = intdiv($remainderMinutes, 60);
        $mins = $remainderMinutes % 60;
        $parts = [];

        if ($days > 0) {
            $parts[] = $days.'d';
        }

        if ($hours > 0) {
            $parts[] = $hours.'h';
        }

        if ($mins > 0 && $days === 0) {
            $parts[] = $mins.'m';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array{start_date: string, start_time: string|null, end_time: string|null, end_date: string|null, duration: string|null, all_day: bool, datetime: string}  $state
     * @return array{start_date: string, start_time: string|null, end_time: string|null, end_date: string|null, duration: string|null, all_day: bool, datetime: string, location: string|null, calendar_url: string|null, calendar_label: string}
     */
    private function withMeta(array $state, Meeting $meeting): array
    {
        $location = $meeting->location;
        $calendarUrl = $meeting->html_link;

        return [
            ...$state,
            'location' => filled($location) ? $location : null,
            'calendar_url' => filled($calendarUrl) ? $calendarUrl : null,
            'calendar_label' => __('filament/resources/meeting.fields.html_link.label'),
        ];
    }

    /**
     * @return array{start_date: string, start_time: string|null, end_time: string|null, end_date: string|null, duration: string|null, all_day: bool, datetime: string, location: string|null, calendar_url: string|null, calendar_label: string}
     */
    private function emptyState(): array
    {
        return [
            'start_date' => '',
            'start_time' => null,
            'end_time' => null,
            'end_date' => null,
            'duration' => null,
            'all_day' => false,
            'datetime' => '',
            'location' => null,
            'calendar_url' => null,
            'calendar_label' => __('filament/resources/meeting.fields.html_link.label'),
        ];
    }
}
