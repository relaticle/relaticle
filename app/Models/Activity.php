<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TeamScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * @property string|null $team_id
 * @property-read Team|null $team
 */
#[ScopedBy([TeamScope::class])]
final class Activity extends SpatieActivity
{
    public static function booted(): void
    {
        self::creating(function (self $activity): void {
            if ($activity->team_id !== null) {
                return;
            }

            $subject = $activity->subject;

            if ($subject !== null && isset($subject->team_id)) {
                $activity->team_id = $subject->team_id;

                return;
            }

            $user = auth()->user();

            if ($user instanceof User && $user->currentTeam !== null) {
                $activity->team_id = $user->currentTeam->getKey();
            }
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
