<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Company;
use App\Models\Concerns\HasTeam;
use App\Models\Opportunity;
use App\Models\People;
use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Enums\CalendarVisibility;
use Relaticle\EmailIntegration\Observers\MeetingObserver;

/**
 * @property string $id
 * @property string $team_id
 * @property string $connected_account_id
 * @property string $provider_event_id
 * @property string|null $provider_recurring_event_id
 * @property string|null $ical_uid
 * @property string $title
 * @property string|null $description
 * @property string|null $location
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $all_day
 * @property string|null $organizer_email
 * @property string|null $organizer_name
 * @property CalendarEventStatus $status
 * @property CalendarVisibility $visibility
 * @property AttendeeResponseStatus|null $response_status
 * @property string|null $html_link
 */
#[ObservedBy(MeetingObserver::class)]
final class Meeting extends Model
{
    /** @use HasFactory<MeetingFactory> */
    use HasFactory, HasTeam, HasUlids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'connected_account_id',
        'provider_event_id',
        'provider_recurring_event_id',
        'ical_uid',
        'title',
        'description',
        'location',
        'starts_at',
        'ends_at',
        'all_day',
        'organizer_email',
        'organizer_name',
        'status',
        'visibility',
        'response_status',
        'html_link',
    ];

    protected static function newFactory(): MeetingFactory
    {
        return MeetingFactory::new();
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'status' => CalendarEventStatus::class,
            'visibility' => CalendarVisibility::class,
            'response_status' => AttendeeResponseStatus::class,
        ];
    }

    /** @return BelongsTo<ConnectedAccount, $this> */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }

    /** @return HasMany<MeetingAttendee, $this> */
    public function attendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class);
    }

    /** @return MorphToMany<People, $this> */
    public function people(): MorphToMany
    {
        return $this->morphedByMany(People::class, 'meetingable')
            ->withPivot('link_source')
            ->withTimestamps();
    }

    /** @return MorphToMany<Company, $this> */
    public function companies(): MorphToMany
    {
        return $this->morphedByMany(Company::class, 'meetingable')
            ->withPivot('link_source')
            ->withTimestamps();
    }

    /** @return MorphToMany<Opportunity, $this> */
    public function opportunities(): MorphToMany
    {
        return $this->morphedByMany(Opportunity::class, 'meetingable')
            ->withPivot('link_source')
            ->withTimestamps();
    }

    public function isLinkedTo(Model $record): bool
    {
        $relation = match (true) {
            $record instanceof People => $this->people(),
            $record instanceof Company => $this->companies(),
            $record instanceof Opportunity => $this->opportunities(),
            default => null,
        };

        return $relation?->whereKey($record->getKey())->exists() ?? false;
    }

    /** @return Attribute<string, never> */
    protected function durationLabel(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->starts_at->diffForHumans($this->ends_at, syntax: Carbon::DIFF_ABSOLUTE),
        );
    }
}
