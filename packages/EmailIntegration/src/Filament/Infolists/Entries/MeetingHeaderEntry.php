<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists\Entries;

use App\Models\User;
use Filament\Infolists\Components\Entry;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\MeetingRespondentResolver;
use Relaticle\EmailIntegration\Services\MeetingTimePresenter;

final class MeetingHeaderEntry extends Entry
{
    protected string $view = 'email-integration::filament.infolists.meeting-header';

    /**
     * @return array{month: string, day: string, response_status: AttendeeResponseStatus|null, can_respond: bool, time: array{start_date: string, start_time: string|null, end_time: string|null, end_date: string|null, duration: string|null, all_day: bool, datetime: string, relative: string|null, timezone: string|null}|null}
     */
    public function getState(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Meeting) {
            return [
                'month' => '',
                'day' => '',
                'response_status' => null,
                'can_respond' => false,
                'time' => null,
            ];
        }

        $user = auth()->user();
        $timezone = $user instanceof User ? $user->effectiveTimezone() : (string) config('app.timezone');
        $responseStatus = $user instanceof User
            ? resolve(MeetingRespondentResolver::class)->viewerResponseStatus($user, $record)
            : ($record->response_status ?? AttendeeResponseStatus::NEEDS_ACTION);
        $start = $record->all_day
            ? Date::parse($record->starts_at)
            : Date::parse($record->starts_at)->timezone($timezone);

        return [
            'month' => mb_strtoupper(rtrim($start->isoFormat('MMM'), '.')),
            'day' => $start->format('j'),
            'response_status' => $responseStatus,
            'can_respond' => $user instanceof User && $user->can('respond', $record),
            'time' => resolve(MeetingTimePresenter::class)->present($record, $timezone),
        ];
    }
}
