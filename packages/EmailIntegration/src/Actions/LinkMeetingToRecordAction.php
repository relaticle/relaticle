<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Relaticle\EmailIntegration\Models\Meeting;

final readonly class LinkMeetingToRecordAction
{
    public function execute(Meeting $meeting, Model $record): void
    {
        // Tenant boundary: a meeting may only be linked to a record in its own team.
        // This is the authoritative guard — callers (Filament, future API/chat) must
        // not be trusted to have pre-scoped the record.
        throw_if(
            (string) $record->getAttribute('team_id') !== (string) $meeting->team_id,
            InvalidArgumentException::class,
            'Cannot link a meeting to a record from another team.',
        );

        $relation = match (true) {
            $record instanceof People => $meeting->people(),
            $record instanceof Company => $meeting->companies(),
            $record instanceof Opportunity => $meeting->opportunities(),
            default => throw new InvalidArgumentException('Unsupported record type: '.$record::class),
        };

        $relation->syncWithoutDetaching([
            $record->getKey() => ['link_source' => 'manual'],
        ]);
    }
}
