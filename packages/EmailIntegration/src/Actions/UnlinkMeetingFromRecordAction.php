<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Relaticle\EmailIntegration\Models\Meeting;

final readonly class UnlinkMeetingFromRecordAction
{
    public function execute(Meeting $meeting, Model $record): void
    {
        match (true) {
            $record instanceof People => $meeting->people()->detach($record->getKey()),
            $record instanceof Company => $meeting->companies()->detach($record->getKey()),
            $record instanceof Opportunity => $meeting->opportunities()->detach($record->getKey()),
            default => throw new InvalidArgumentException('Unsupported record type: '.$record::class),
        };
    }
}
