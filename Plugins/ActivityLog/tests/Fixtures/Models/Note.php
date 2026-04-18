<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\ActivityLog\Tests\Fixtures\database\factories\NoteFactory;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

final class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    use LogsActivity;

    protected $fillable = ['person_id', 'body'];

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['body'])->logOnlyDirty();
    }
}
