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
    public function execute(User $user, Company|People $merged, Company|People $priority, Company|People $other): void
    {
        $this->moveFrom($merged, $priority);
        $this->moveFrom($merged, $other);

        activity((string) config('activitylog.default_log_name'))
            ->performedOn($merged)
            ->causedBy($user)
            // A batch of its own, so record_merged never folds into a moved row from the same request.
            ->tap(fn (Activity $activity) => $activity->setAttribute('batch_uuid', (string) Str::uuid()))
            ->event(AppEventPalette::RecordMerged->value)
            ->withProperties(['merged_names' => [$priority->name, $other->name]])
            ->log(AppEventPalette::RecordMerged->value);
    }

    private function moveFrom(Company|People $merged, Company|People $original): void
    {
        // Rehashed so the two originals' batches never fold together from the same import or request.
        // Lifecycle rows stay behind: the timeline names the current subject, so they would read as the merged record's own.
        DB::update(
            'update activity_log set subject_id = ?, batch_uuid = md5(batch_uuid::text || ?)::uuid where subject_type = ? and subject_id = ? and coalesce(event, \'\') not in (?, ?, ?)',
            [
                $merged->getKey(), $original->getKey(), $merged->getMorphClass(), $original->getKey(),
                ActivityLogOperation::Created->value, ActivityLogOperation::Deleted->value, ActivityLogOperation::Restored->value,
            ],
        );
    }
}
