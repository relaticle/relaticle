<?php

declare(strict_types=1);

namespace App\Models\ActivityLog;

use App\Enums\CreationSource;
use App\Models\ActivityLog\Scopes\WorkspaceScope;
use App\Models\Workspace;
use App\Support\Impersonation\Impersonator;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * @property string|null $workspace_id
 * @property-read CreationSource|null $source
 */
final class Activity extends SpatieActivity
{
    public const string SOURCE_PROPERTY = 'source';

    public const string EMAIL_ACCESS_LOG = 'email_access';

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

    public function causerLabel(): string
    {
        $name = $this->causer?->getAttribute('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return $this->causer_id === null
            ? __('workspaces.activity.system')
            : __('workspaces.activity.former_member');
    }

    /** @return Attribute<CreationSource|null, never> */
    protected function source(): Attribute
    {
        return Attribute::get(fn (): ?CreationSource => self::sourceFrom($this->properties?->toArray() ?? []));
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function fromSource(Builder $query, CreationSource $source): void
    {
        $query->where('properties->'.self::SOURCE_PROPERTY, $source->value);
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function inAuditTrail(Builder $query): void
    {
        $query->where(fn (Builder $log): Builder => $log
            ->whereNull('activity_log.log_name')
            ->orWhere('activity_log.log_name', '!=', self::EMAIL_ACCESS_LOG));
    }

    protected static function booted(): void
    {
        self::addGlobalScope(new WorkspaceScope);

        self::creating(function (self $activity): void {
            if ($activity->workspace_id !== null || $activity->log_name === Impersonator::LOG_NAME) {
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
