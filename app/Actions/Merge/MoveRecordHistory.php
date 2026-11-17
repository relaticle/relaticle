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
use Relaticle\ActivityLog\Support\ActivityLogOperation;

final readonly class MoveRecordHistory
{
    public function execute(User $user, Company|People $kept, Company|People $duplicate): void
    {
        // Rehashed so the duplicate's batches never fold with the kept record's batches from the same import or request.
        // Lifecycle rows stay behind: the timeline names the current subject, so they would read as the kept record's own.
        DB::update(
            'update activity_log set subject_id = ?, batch_uuid = md5(batch_uuid::text || ?)::uuid where subject_type = ? and subject_id = ? and coalesce(event, \'\') not in (?, ?, ?)',
            [
                $kept->getKey(), $duplicate->getKey(), $kept->getMorphClass(), $duplicate->getKey(),
                ActivityLogOperation::Created->value, ActivityLogOperation::Deleted->value, ActivityLogOperation::Restored->value,
            ],
        );

        activity((string) config('activitylog.default_log_name'))
            ->performedOn($kept)
            ->causedBy($user)
            // A batch of its own, so record_merged never folds into the merge's own later `updated` rows in this request.
            ->tap(fn (Activity $activity) => $activity->setAttribute('batch_uuid', (string) Str::uuid()))
            ->event(AppEventPalette::RecordMerged->value)
            ->withProperties(['duplicate_name' => $duplicate->name])
            ->log(AppEventPalette::RecordMerged->value);
    }
}
