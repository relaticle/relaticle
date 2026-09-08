<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists\Entries;

use App\Models\People;
use App\Services\AvatarService;
use Filament\Infolists\Components\Entry;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\MeetingAttendee;

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

        $name = $record->name ?: $record->email_address;
        $contact = $record->relationLoaded('contact') ? $record->getRelation('contact') : null;

        return [
            'name' => $name,
            'email' => $record->email_address,
            'avatar' => ($contact instanceof People ? $contact->avatar : null) ?? resolve(AvatarService::class)->generateAuto(name: $name, initialCount: 1),
            'is_organizer' => $record->is_organizer,
            'response_status' => $record->response_status,
        ];
    }
}
