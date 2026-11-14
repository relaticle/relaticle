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

final readonly class MoveRecordHistory
{
    public function execute(User $user, Company|People $kept, Company|People $duplicate): void
    {
        DB::update(
            'update activity_log set subject_id = ?, batch_uuid = md5(batch_uuid::text || ?)::uuid where subject_type = ? and subject_id = ?',
            [$kept->getKey(), $duplicate->getKey(), $kept->getMorphClass(), $duplicate->getKey()],
        );

        activity((string) config('activitylog.default_log_name'))
            ->performedOn($kept)
            ->causedBy($user)
            // A batch of its own, so the timeline never folds the merge into the kept record's creation.
            ->tap(fn (Activity $activity) => $activity->setAttribute('batch_uuid', (string) Str::uuid()))
            ->event(AppEventPalette::RecordMerged->value)
            ->withProperties(['duplicate_name' => $duplicate->name])
            ->log(AppEventPalette::RecordMerged->value);
    }
}
