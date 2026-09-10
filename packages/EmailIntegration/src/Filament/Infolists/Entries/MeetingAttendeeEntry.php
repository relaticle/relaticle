<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists\Entries;

use Filament\Infolists\Components\Entry;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\MeetingAttendeePresenter;

final class MeetingAttendeeEntry extends Entry
{
    protected string $view = 'email-integration::filament.infolists.meeting-attendee';

    /**
     * @return array{name: string, email: string, avatar: string, is_organizer: bool, response_status: AttendeeResponseStatus|null}
     */
    public function getState(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof MeetingAttendee) {
            return [
                'name' => '',
                'email' => '',
                'avatar' => '',
                'is_organizer' => false,
                'response_status' => null,
            ];
        }

        return resolve(MeetingAttendeePresenter::class)->present($record);
    }
}
