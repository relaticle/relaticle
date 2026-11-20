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
    public function execute(User $user, Company|People $merged, Company|People $origin, Company|People $priority, Company|People $other): void
    {
        foreach ([$priority, $other] as $original) {
            $this->moveFrom($merged, $original, keepsCreation: ! $original->is($origin));
        }

        activity((string) config('activitylog.default_log_name'))
            ->performedOn($merged)
            ->causedBy($user)
            ->tap(fn (Activity $activity): Activity => $activity->setAttribute('batch_uuid', (string) Str::uuid()))
            ->event(AppEventPalette::RecordMerged->value)
            ->withProperties(['merged_names' => [$priority->name, $other->name]])
            ->log(AppEventPalette::RecordMerged->value);
    }

    private function moveFrom(Company|People $merged, Company|People $original, bool $keepsCreation): void
    {
        $staysBehind = [
            ActivityLogOperation::Deleted->value,
            ActivityLogOperation::Restored->value,
            AppEventPalette::RecordMerged->value,
            ...($keepsCreation ? [ActivityLogOperation::Created->value] : []),
        ];

        DB::update(
            'update activity_log set subject_id = ?, batch_uuid = md5(batch_uuid::text || ?)::uuid where subject_type = ? and subject_id = ? and coalesce(event, \'\') not in ('.implode(', ', array_fill(0, count($staysBehind), '?')).')',
            [$merged->getKey(), $original->getKey(), $merged->getMorphClass(), $original->getKey(), ...$staysBehind],
        );
    }
}
