<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\Meeting;

final readonly class MeetingTimePresenter
{
    public function __construct(private MeetingTemporalState $temporalState) {}

    /**
     * @return array{start_date: string, start_time: string|null, end_time: string|null, end_date: string|null, duration: string|null, all_day: bool, datetime: string, relative: string|null, timezone: string|null}
     */
    public function present(Meeting $meeting, string $timezone): array
    {
        return $meeting->all_day
            ? $this->presentAllDay($meeting, $timezone)
            : $this->presentTimed($meeting, $timezone);
    }

    /**
     * @return array{start_date: string, start_time: string|null, end_time: string|null, end_date: string|null, duration: string|null, all_day: bool, datetime: string, relative: string|null, timezone: string|null}
     */
    private function presentAllDay(Meeting $meeting, string $timezone): array
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
            'relative' => $this->dayDistance($start->toDateString(), $timezone),
            'timezone' => null,
        ];
    }

    /**
     * @return array{start_date: string, start_time: string|null, end_time: string|null, end_date: string|null, duration: string|null, all_day: bool, datetime: string, relative: string|null, timezone: string|null}
     */
    private function presentTimed(Meeting $meeting, string $timezone): array
    {
        $start = Date::parse($meeting->starts_at)->timezone($timezone);
        $end = Date::parse($meeting->ends_at)->timezone($timezone);
        $crossDay = ! $end->isSameDay($start);
        $zeroLength = $end->equalTo($start);

        return [
            'start_date' => $this->dateLabel($start, $crossDay ? $end : null),
            'start_time' => $start->isoFormat('LT'),
            'end_time' => $zeroLength ? null : $end->isoFormat('LT'),
            'end_date' => $crossDay ? $this->dateLabel($end, $start) : null,
            'duration' => $zeroLength ? null : $this->compactDuration($start, $end),
            'all_day' => false,
            'datetime' => $start->toIso8601String(),
            'relative' => $this->timedDistance($meeting, $start, $timezone),
            'timezone' => 'GMT'.preg_replace(['/^([+-])0/', '/:00$/'], ['$1', ''], $start->format('P')),
        ];
    }

    private function timedDistance(Meeting $meeting, CarbonInterface $start, string $timezone): string
    {
        if ($this->temporalState->isHappeningNow($meeting, $timezone)) {
            return __('filament/pages/dashboard.meetings.happening_now');
        }

        return $this->dayDistance($start->toDateString(), $timezone) ?? Str::ucfirst($start->diffForHumans());
    }

    /**
     * Counts calendar days, so a meeting 47 hours away reads as two days and not one.
     */
    private function dayDistance(string $date, string $timezone): ?string
    {
        $today = Date::now($timezone)->toDateString();

        if ($date === $today) {
            return null;
        }

        return Str::ucfirst(Date::parse($date)->diffForHumans(Date::parse($today), [
            'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW,
            'options' => CarbonInterface::ONE_DAY_WORDS,
        ]));
    }

    private function dateLabel(CarbonInterface $date, ?CarbonInterface $other): string
    {
        if ($other instanceof CarbonInterface && $date->year !== $other->year) {
            return $date->isoFormat('ddd, ll');
        }

        return $date->isoFormat('ddd, '.__('formats.iso.date_without_year'));
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
}
