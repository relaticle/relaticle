<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Relaticle\ActivityLog\Concerns\HasTimeline;
use Relaticle\ActivityLog\Tests\Fixtures\database\factories\PersonFactory;
use Relaticle\ActivityLog\Timeline\Sources\RelatedModelSource;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    use HasTimeline;
    use LogsActivity;

    protected $fillable = ['name'];

    /**
     * @return HasMany<Email, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    /**
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name'])->logOnlyDirty();
    }

    public function timeline(): TimelineBuilder
    {
        return TimelineBuilder::make($this)
            ->fromActivityLog()
            ->fromActivityLogOf(['emails', 'notes', 'tasks'])
            ->fromRelation('emails', fn (RelatedModelSource $source): RelatedModelSource => $source
                ->event('sent_at', 'email_sent')
                ->event('received_at', 'email_received'));
    }
}
