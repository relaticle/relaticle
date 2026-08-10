<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use App\Models\ActivityLog\Activity;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Actions\CleanActivityLogAction as BaseCleanActivityLogAction;

final class CleanActivityLogAction extends BaseCleanActivityLogAction
{
    protected function deleteOldActivities(string $cutOffDate, ?string $logName): int
    {
        return Activity::query()
            ->withoutGlobalScopes()
            ->where('created_at', '<', $cutOffDate)
            ->when($logName !== null, function (Builder $query) use ($logName): void {
                $query->where('log_name', $logName);
            })
            ->delete();
    }
}
