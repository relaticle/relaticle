<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreationSource;
use App\Enums\MediaCollection;
use App\Models\Concerns\BelongsToWorkspaceCreator;
use App\Models\Concerns\HasActivityTimeline;
use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasNotes;
use App\Models\Concerns\HasWorkspace;
use App\Models\Scopes\WorkspaceScope;
use App\Observers\PeopleObserver;
use App\Services\AvatarService;
use App\Support\Media\UploadAllowlist;
use Carbon\CarbonImmutable;
use Database\Factories\PeopleFactory;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Relaticle\ActivityLog\Contracts\HasTimeline;
use Relaticle\CustomFields\Models\Concerns\UsesCustomFields;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\EmailIntegration\Models\Concerns\HasEmails;
use Relaticle\EmailIntegration\Models\Concerns\HasMeetings;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property CarbonImmutable|null $deleted_at
 * @property CreationSource $creation_source
 * @property CarbonImmutable|null $last_email_at
 * @property CarbonImmutable|null $last_interaction_at
 * @property CarbonImmutable|null $last_meeting_at
 * @property int $email_count
 * @property int $inbound_email_count
 * @property int $outbound_email_count
 * @property int $meeting_count
 */
#[ObservedBy(PeopleObserver::class)]
#[ScopedBy(WorkspaceScope::class)]
#[Fillable([
    'name',
    'creation_source',
])]
final class People extends Model implements HasAvatar, HasCustomFields, HasMedia, HasTimeline
{
    use BelongsToWorkspaceCreator;
    use HasActivityTimeline;
    use HasCreator;
    use HasEmails;

    /** @use HasFactory<PeopleFactory> */
    use HasFactory;

    use HasMeetings;
    use HasNotes;
    use HasUlids;
    use HasWorkspace;
    use InteractsWithMedia;
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
            'last_email_at' => 'datetime',
            'last_interaction_at' => 'datetime',
            'last_meeting_at' => 'datetime',
        ];
    }

    protected function getAvatarAttribute(): string
    {
        return resolve(AvatarService::class)->generateAuto(name: $this->name, initialCount: 1);
    }

    public function getFilamentAvatarUrl(): string
    {
        return $this->avatar;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return MorphToMany<Task, $this>
     */
    public function tasks(): MorphToMany
    {
        return $this->morphToMany(Task::class, 'taskable');
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
                'last_email_at', 'last_interaction_at', 'email_count', 'inbound_email_count',
                'outbound_email_count', 'meeting_count', 'last_meeting_at', 'company_id',
            ])
            ->useLogName('crm')
            ->setDescriptionForEvent(fn (string $eventName): string => $eventName);
    }
}
