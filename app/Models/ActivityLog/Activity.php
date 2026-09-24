<?php

declare(strict_types=1);

namespace App\Models\ActivityLog;

use App\Enums\CreationSource;
use App\Models\ActivityLog\Scopes\WorkspaceScope;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * @property string|null $workspace_id
 */
final class Activity extends SpatieActivity
{
    public const string SOURCE_PROPERTY = 'source';

    /** @param  array<array-key, mixed>  $properties */
    public static function sourceFrom(array $properties): ?CreationSource
    {
        $source = $properties[self::SOURCE_PROPERTY] ?? null;

        return is_string($source) ? CreationSource::tryFrom($source) : null;
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function fromSource(Builder $query, CreationSource $source): void
    {
        $query->where('properties->'.self::SOURCE_PROPERTY, $source->value);
    }

    protected static function booted(): void
    {
        self::addGlobalScope(new WorkspaceScope);

        self::creating(function (self $activity): void {
            if ($activity->workspace_id !== null) {
                return;
            }

            $workspaceId = $activity->subject?->getAttribute('workspace_id')
                ?? $activity->subject?->getAttribute('tenant_id')
                ?? Filament::getTenant()?->getKey();

            if ($workspaceId !== null) {
                $activity->workspace_id = $workspaceId;
            }
        });
    }
}
