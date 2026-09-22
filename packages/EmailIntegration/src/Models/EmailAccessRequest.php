<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\User;
use Database\Factories\EmailAccessRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    protected static function newFactory(): EmailAccessRequestFactory
    {
        return EmailAccessRequestFactory::new();
    }

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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function pendingIncomingFor(Builder $query, User $owner): Builder
    {
        return $query
            ->where('owner_id', $owner->getKey())
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->whereHas('email', fn (Builder $email): Builder => $email->where('workspace_id', $owner->current_workspace_id));
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
