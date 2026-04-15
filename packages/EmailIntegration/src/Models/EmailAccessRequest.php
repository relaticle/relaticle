<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\User;
use Database\Factories\EmailAccessRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;

/**
 * @property EmailAccessRequestStatus $status
 */
final class EmailAccessRequest extends Model
{
    /**
     * @use HasFactory<EmailAccessRequestFactory>
     */
    use HasFactory, HasUlids;

    protected $fillable = [
        'requester_id',
        'owner_id',
        'email_id',
        'emailable_type',
        'emailable_id',
        'tier_requested',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmailAccessRequestStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Email, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function emailable(): MorphTo
    {
        return $this->morphTo();
    }
}
