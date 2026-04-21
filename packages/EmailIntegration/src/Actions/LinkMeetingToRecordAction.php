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
