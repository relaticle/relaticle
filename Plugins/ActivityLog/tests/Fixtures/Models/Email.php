<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\ActivityLog\Tests\Fixtures\database\factories\EmailFactory;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

final class Email extends Model
{
    /** @use HasFactory<EmailFactory> */
    use HasFactory;

    use LogsActivity;

    protected $fillable = ['person_id', 'subject', 'sent_at', 'received_at'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['subject', 'sent_at', 'received_at'])->logOnlyDirty();
    }
}
