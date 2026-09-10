<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists\Entries;

use Filament\Infolists\Components\Entry;
use Relaticle\EmailIntegration\Models\Meeting;

final class MeetingLinkedRecordsEntry extends Entry
{
    protected string $view = 'email-integration::filament.infolists.meeting-linked-records';

    /**
     * @return list<array{name: string, type: string}>
     */
    public function getState(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Meeting) {
            return [];
        }

        $items = [];

        foreach ($record->people as $person) {
            $items[] = [
                'name' => $person->name,
                'type' => __('filament/resources/meeting.linked_record_types.people'),
            ];
        }

        foreach ($record->companies as $company) {
            $items[] = [
                'name' => $company->name,
                'type' => __('filament/resources/meeting.linked_record_types.companies'),
            ];
        }

        foreach ($record->opportunities as $opportunity) {
            $items[] = [
                'name' => $opportunity->name,
                'type' => __('filament/resources/meeting.linked_record_types.opportunities'),
            ];
        }

        return $items;
    }
}
