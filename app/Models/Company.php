<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreationSource;
use App\Enums\CustomFields\CompanyField;
use App\Enums\MediaCollection;
use App\Models\Concerns\BelongsToWorkspaceCreator;
use App\Models\Concerns\HasActivityTimeline;
use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasNotes;
use App\Models\Concerns\HasWorkspace;
use App\Models\Concerns\LogsRelationChanges;
use App\Models\Pivots\Taskable;
use App\Models\Scopes\WorkspaceScope;
use App\Observers\CompanyObserver;
use App\Support\Media\UploadAllowlist;
use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use Filament\Models\Contracts\HasAvatar;
use finfo;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
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
 * @property string $name
 * @property CarbonImmutable|null $deleted_at
 * @property CreationSource $creation_source
 * @property CarbonImmutable|null $last_email_at
 * @property CarbonImmutable|null $last_interaction_at
 * @property CarbonImmutable|null $last_meeting_at
 * @property int $email_count
 * @property int $inbound_email_count
 * @property int $outbound_email_count
 * @property int $meeting_count
 * @property-read string $created_by
 */
#[ObservedBy(CompanyObserver::class)]
#[ScopedBy(WorkspaceScope::class)]
#[Fillable([
    'name',
    'creation_source',
])]
final class Company extends Model implements HasAvatar, HasCustomFields, HasMedia, HasTimeline
{
    use BelongsToWorkspaceCreator;
    use HasActivityTimeline;
    use HasCreator;
    use HasEmails;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use HasMeetings;
    use HasNotes;
    use HasUlids;
    use HasWorkspace;
    use InteractsWithMedia;
    use LogsActivity;
    use LogsRelationChanges;
    use SoftDeletes;
    use UsesCustomFields;

    public const string LOGO_MEDIA_COLLECTION = MediaCollection::Logo->value;

    private const int BRANDFETCH_SIZE = 128;

    private const string HOSTNAME_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    /** @var array<string, string> */
    public const array LOGO_MIME_TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/vnd.microsoft.icon' => 'ico',
        'image/x-icon' => 'ico',
    ];

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
     * @return Attribute<string|null, never>
     */
    protected function logo(): Attribute
    {
        // No mark at all shows the shared entity icon, never initials: colour means a real brand mark.
        return Attribute::get(function (): ?string {
            $logo = $this->getFirstMediaUrl(self::LOGO_MEDIA_COLLECTION);

            return $logo === '' || $logo === '0' ? $this->brandfetchUrl() : $logo;
        });
    }

    public function brandfetchUrl(): ?string
    {
        $clientId = config('services.brandfetch.client_id');
        $domain = filled($clientId) ? $this->logoDomain() : null;

        return $domain === null
            ? null
            : sprintf('https://cdn.brandfetch.io/domain/%s/w/%d/h/%d/fallback/404/icon?c=%s', $domain, self::BRANDFETCH_SIZE, self::BRANDFETCH_SIZE, rawurlencode((string) $clientId));
    }

    /**
     * @return MorphOne<CustomFieldValue, $this>
     */
    public function domainValue(): MorphOne
    {
        return $this->morphOne(CustomFieldValue::class, 'entity')
            ->whereHas('customField', fn (Builder $field): Builder => $field->where('code', CompanyField::DOMAINS->value))
            ->with('customField');
    }

    public function logoDomain(): ?string
    {
        $value = $this->domainValue;

        if ($value === null || data_get($value, 'customField.tenant_id') !== $this->workspace_id) {
            return null;
        }

        $stored = $value->getValue();

        return collect(is_iterable($stored) ? $stored : [$stored])
            ->map(self::domainFrom(...))
            ->first(fn (?string $domain): bool => $domain !== null);
    }

    public static function domainFrom(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $host = Str::of($value)->trim()->lower()
            ->replaceMatches('#^[a-z][a-z0-9+.-]*://#', '')
            ->replaceMatches('#[/?\#].*$#', '')
            ->replaceMatches('#^[^@]*@#', '')
            ->replaceMatches('#:\d*$#', '')
            ->rtrim('.')
            ->chopStart('www.')
            ->toString();

        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && preg_match(self::HOSTNAME_PATTERN, $ascii) === 1 ? $ascii : null;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->logo;
    }

    public static function logoExtensionFor(string $contents): ?string
    {
        return self::LOGO_MIME_TYPES[new finfo(FILEINFO_MIME_TYPE)->buffer($contents)] ?? null;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::LOGO_MEDIA_COLLECTION)
            ->acceptsMimeTypes(array_keys(self::LOGO_MIME_TYPES))
            ->useDisk('public');

        $this->addMediaCollection(MediaCollection::Attachments->value)
            ->acceptsMimeTypes(UploadAllowlist::mimeTypes());
    }

    /**
     * Workspace member responsible for managing the company account
     *
     * @return BelongsTo<User, $this>
     */
    public function accountOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_owner_id');
    }

    /**
     * @return HasMany<People, $this>
     */
    public function people(): HasMany
    {
        return $this->hasMany(People::class);
    }

    /**
     * @return HasMany<Opportunity, $this>
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    /**
     * @return MorphToMany<Task, $this, Taskable>
     */
    public function tasks(): MorphToMany
    {
        return $this->morphToMany(Task::class, 'taskable')->using(Taskable::class);
    }

    /**
     * @return array<string, string>
     */
    public function relationChangeLabels(): array
    {
        return [
            'account_owner_id' => __('filament/resources/company.fields.account_owner_id.label'),
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
                'created_at', 'updated_at', 'deleted_at',
                'last_email_at', 'last_interaction_at', 'email_count', 'inbound_email_count',
                'outbound_email_count', 'meeting_count', 'last_meeting_at', 'account_owner_id',
            ])
            ->useLogName('crm')
            ->setDescriptionForEvent(fn (string $eventName): string => $eventName);
    }
}
