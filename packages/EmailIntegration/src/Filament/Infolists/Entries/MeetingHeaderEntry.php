<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists\Entries;

use App\Models\User;
use Filament\Infolists\Components\Entry;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\MeetingRespondentResolver;

final class MeetingHeaderEntry extends Entry
{
    protected string $view = 'email-integration::filament.infolists.meeting-header';

    /**
     * @return array{title: string, month: string, day: string, response_status: AttendeeResponseStatus|null, can_respond: bool}
     */
    public function getState(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Meeting) {
            return [
                'title' => '',
                'month' => '',
                'day' => '',
                'response_status' => null,
                'can_respond' => false,
            ];
        }

        $user = auth()->user();
        $responseStatus = $user instanceof User
            ? resolve(MeetingRespondentResolver::class)->viewerResponseStatus($user, $record)
            : ($record->response_status ?? AttendeeResponseStatus::NEEDS_ACTION);

        return [
            'title' => $record->title,
            'month' => strtoupper($record->starts_at->format('M')),
            'day' => $record->starts_at->format('j'),
            'response_status' => $responseStatus,
            'can_respond' => $user instanceof User && $user->can('respond', $record),
        ];
    }
}
