<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

final class Note extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = ['person_id', 'body'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['body'])->logOnlyDirty();
    }
}
