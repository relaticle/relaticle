<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreationSource;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\MediaCollection;
use App\Models\Concerns\BelongsToWorkspaceCreator;
use App\Models\Concerns\HasActivityTimeline;
use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasNotes;
use App\Models\Concerns\HasWorkspace;
use App\Models\Concerns\LogsRelationChanges;
use App\Models\Pivots\Taskable;
use App\Models\Scopes\WorkspaceScope;
use App\Observers\OpportunityObserver;
use App\Support\Media\UploadAllowlist;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\OpportunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Date;
use Relaticle\ActivityLog\Contracts\HasTimeline;
use Relaticle\CustomFields\Models\Concerns\UsesCustomFields;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\EmailIntegration\Models\Concerns\HasEmails;
use Relaticle\EmailIntegration\Models\Concerns\HasMeetings;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\EloquentSortable\SortableTrait;
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
#[ObservedBy(OpportunityObserver::class)]
#[ScopedBy(WorkspaceScope::class)]
#[Fillable([
    'creation_source',
    'last_email_at',
    'last_interaction_at',
    'email_count',
    'inbound_email_count',
    'outbound_email_count',
])]
final class Opportunity extends Model implements HasCustomFields, HasMedia, HasTimeline
{
    use BelongsToWorkspaceCreator;
    use HasActivityTimeline;
    use HasCreator;
    use HasEmails;

    /** @use HasFactory<OpportunityFactory> */
    use HasFactory;

    use HasMeetings;
    use HasNotes;
    use HasUlids;
    use HasWorkspace;
    use InteractsWithMedia;
    use LogsActivity;
    use LogsRelationChanges;
    use SoftDeletes;
    use SortableTrait;
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

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<People, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(People::class);
    }

    public function closeDate(): ?CarbonImmutable
    {
        $workspaceId = $this->workspace_id;
        $field = once(fn (): ?CustomField => CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', $this->getMorphClass())
            ->where('code', OpportunityField::CLOSE_DATE->value)
            ->first());
        $value = $field instanceof CustomField ? $this->loadMissing('customFieldValues.customField')->getCustomFieldValue($field) : null;

        return match (true) {
            $value instanceof CarbonInterface => $value->toImmutable(),
            is_string($value) && $value !== '' => Date::parse($value)->toImmutable(),
            default => null,
        };
    }

    /**
     * @return MorphToMany<Task, $this, Taskable>
     */
    public function tasks(): MorphToMany
    {
        return $this->morphToMany(Task::class, 'taskable')->using(Taskable::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(MediaCollection::Attachments->value)
            ->acceptsMimeTypes(UploadAllowlist::mimeTypes());
    }

    /**
     * @return array<string, string>
     */
    public function relationChangeLabels(): array
    {
        return [
            'company_id' => __('filament/resources/opportunity.fields.company_id.label'),
            'contact_id' => __('filament/resources/opportunity.fields.contact_id.label'),
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->logExcept([
                'id', 'workspace_id', 'creator_id', 'creation_source', 'custom_fields',
                'created_at', 'updated_at', 'deleted_at', 'order_column',
                'last_email_at', 'last_interaction_at', 'email_count', 'inbound_email_count',
                'outbound_email_count', 'meeting_count', 'last_meeting_at', 'company_id', 'contact_id',
            ])
            ->useLogName('crm')
            ->setDescriptionForEvent(fn (string $eventName): string => $eventName);
    }
}
