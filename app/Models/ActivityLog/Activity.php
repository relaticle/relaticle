<?php

declare(strict_types=1);

namespace App\Models\ActivityLog;

use App\Models\ActivityLog\Scopes\WorkspaceScope;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * @property string|null $workspace_id
 */
final class Activity extends SpatieActivity
{
    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    protected static function booted(): void
    {
        self::addGlobalScope(new WorkspaceScope);

        self::creating(function (self $activity): void {
            if ($activity->workspace_id !== null) {
                return;
            }

            $workspaceId = $activity->subject?->getAttribute('workspace_id')
                ?? Filament::getTenant()?->getKey();

            if ($workspaceId !== null) {
                $activity->workspace_id = $workspaceId;
            }
        });
    }
}
