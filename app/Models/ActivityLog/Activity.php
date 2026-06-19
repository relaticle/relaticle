<?php

declare(strict_types=1);

namespace App\Models\ActivityLog;

use App\Models\ActivityLog\Scopes\TeamScope;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * @property string|null $team_id
 */
final class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        self::addGlobalScope(new TeamScope);

        self::creating(function (self $activity): void {
            if ($activity->team_id !== null) {
                return;
            }

            $teamId = $activity->subject?->getAttribute('team_id')
                ?? Filament::getTenant()?->getKey();

            if ($teamId !== null) {
                $activity->team_id = $teamId;
            }
        });
    }
}
