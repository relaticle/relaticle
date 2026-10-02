<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreationSource;
use App\Enums\MediaCollection;
use App\Models\Concerns\BelongsToWorkspaceCreator;
use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasWorkspace;
use App\Models\Scopes\WorkspaceScope;
use App\Support\Media\UploadAllowlist;
use Carbon\CarbonImmutable;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Relaticle\ActivityLog\Concerns\InteractsWithTimeline;
use Relaticle\ActivityLog\Contracts\HasTimeline;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\CustomFields\Models\Concerns\UsesCustomFields;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property CarbonImmutable|null $deleted_at
 * @property CreationSource $creation_source
 */
#[ScopedBy(WorkspaceScope::class)]
#[Fillable([
    'creation_source',
])]
final class Note extends Model implements HasCustomFields, HasMedia, HasTimeline
{
    use BelongsToWorkspaceCreator;
    use HasCreator;

    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    use HasUlids;
    use HasWorkspace;
    use InteractsWithMedia;
    use InteractsWithTimeline;
    use LogsActivity;
    use SoftDeletes;
    use UsesCustomFields;

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'creation_source' => CreationSource::class,
        ];
    }

    /**
     * @return MorphToMany<Company, $this>
     */
    public function companies(): MorphToMany
    {
        return $this->morphedByMany(Company::class, 'noteable');
    }

    /**
     * @return MorphToMany<People, $this>
     */
    public function people(): MorphToMany
    {
        return $this->morphedByMany(People::class, 'noteable');
    }

    /**
     * @return MorphToMany<Opportunity, $this>
     */
    public function opportunities(): MorphToMany
    {
        return $this->morphedByMany(Opportunity::class, 'noteable');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(MediaCollection::Attachments->value)
            ->acceptsMimeTypes(UploadAllowlist::mimeTypes());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->logExcept([
                'id', 'workspace_id', 'creator_id', 'creation_source', 'custom_fields',
                'created_at', 'updated_at', 'deleted_at',
            ])
            ->useLogName('crm')
            ->setDescriptionForEvent(fn (string $eventName): string => $eventName);
    }

    public function timeline(): TimelineBuilder
    {
        return TimelineBuilder::make($this)->fromActivityLog(mergedRenderer: 'merged-activity');
    }
}
