<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\ActivityLog\AppEventPalette;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class LogRecordMerge
{
    public function execute(User $user, Company|People $merged, Company|People $priority, Company|People $other): void
    {
        $this->carryEarlierMerges($merged, $priority);

        $this->log($user, $merged, ['merged_name' => $other->name]);
        $this->log($user, $priority, ['merged_into_name' => $merged->name]);
        $this->log($user, $other, ['merged_into_name' => $merged->name]);
    }

    // The new record continues the priority record, so the records already merged into it stay listed.
    private function carryEarlierMerges(Company|People $merged, Company|People $priority): void
    {
        DB::table('activity_log')
            ->where('subject_type', $merged->getMorphClass())
            ->where('subject_id', $priority->getKey())
            ->where('event', AppEventPalette::RecordMerged->value)
            ->whereNotNull('properties->merged_name')
            ->update(['subject_id' => $merged->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(User $user, Company|People $record, array $properties): void
    {
        activity((string) config('activitylog.default_log_name'))
            ->performedOn($record)
            ->causedBy($user)
            // A batch of its own, so record_merged never folds into the originals' `deleted` rows from this request.
            ->tap(fn (Activity $activity) => $activity->setAttribute('batch_uuid', (string) Str::uuid()))
            ->event(AppEventPalette::RecordMerged->value)
            ->withProperties($properties)
            ->log(AppEventPalette::RecordMerged->value);
    }
}
