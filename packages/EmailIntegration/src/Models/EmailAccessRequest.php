<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Enums\CrmEntity;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Factories\EmailAccessRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;

/**
 * @property EmailAccessRequestStatus $status
 * @property string|null $note
 * @property string|null $emailable_type
 * @property string|null $emailable_id
 * @property CarbonImmutable|null $expires_at
 */
#[Fillable([
    'requester_id',
    'owner_id',
    'email_id',
    'emailable_type',
    'emailable_id',
    'tier_requested',
    'note',
    'status',
    'workspace_id',
    'decided_at',
    'decided_by',
    'expires_at',
])]
final class EmailAccessRequest extends Model
{
    /**
     * @use HasFactory<EmailAccessRequestFactory>
     */
    use HasFactory, HasUlids;

    public const int EXPIRES_AFTER_DAYS = 30;

    public const int NOTE_MAX_LENGTH = 500;

    protected static function newFactory(): EmailAccessRequestFactory
    {
        return EmailAccessRequestFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => EmailAccessRequestStatus::class,
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function open(Builder $query): Builder
    {
        return $query
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->where(fn (Builder $deadline): Builder => $deadline
                ->where('expires_at', '>', now())
                ->orWhere(fn (Builder $undated): Builder => $undated
                    ->whereNull('expires_at')
                    ->where('created_at', '>', now()->subDays(self::EXPIRES_AFTER_DAYS))));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function overdue(Builder $query): Builder
    {
        return $query
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->where(fn (Builder $deadline): Builder => $deadline
                ->where('expires_at', '<=', now())
                ->orWhere(fn (Builder $undated): Builder => $undated
                    ->whereNull('expires_at')
                    ->where('created_at', '<=', now()->subDays(self::EXPIRES_AFTER_DAYS))));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function pendingIncomingFor(Builder $query, User $owner, Workspace $workspace): Builder
    {
        return $query
            ->where('owner_id', $owner->getKey())
            ->where('workspace_id', $workspace->getKey())
            ->open()
            ->answerable();
    }

    /**
     * A request for one email whose copy is still listed, or a request for a record's mail.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function answerable(Builder $query): Builder
    {
        return $query->where(fn (Builder $answerable): Builder => $answerable
            ->whereHas('email')
            ->orWhere(fn (Builder $forRecord): Builder => $forRecord
                ->whereNull('email_id')
                ->whereNotNull('emailable_type')
                ->whereNotNull('emailable_id')));
    }

    public static function countPendingIncomingFor(User $owner, ?Workspace $workspace): int
    {
        if (! $workspace instanceof Workspace) {
            return 0;
        }

        return self::query()->pendingIncomingFor($owner, $workspace)->count();
    }

    public function deadline(): ?CarbonImmutable
    {
        return $this->expires_at ?? $this->created_at?->addDays(self::EXPIRES_AFTER_DAYS);
    }

    public function isForRecord(): bool
    {
        return $this->email_id === null && $this->emailable_type !== null && $this->emailable_id !== null;
    }

    // Read with the request's own workspace, so a queued notice finds the record with no workspace bound.
    public function record(): Company|People|Opportunity|null
    {
        $query = match (CrmEntity::tryFrom((string) $this->emailable_type)) {
            CrmEntity::Company => Company::query(),
            CrmEntity::People => People::query(),
            CrmEntity::Opportunity => Opportunity::query(),
            default => null,
        };

        if ($query === null || $this->emailable_id === null || $this->workspace_id === null) {
            return null;
        }

        return $query->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $this->workspace_id)->find($this->emailable_id);
    }

    public function isOpen(): bool
    {
        $deadline = $this->deadline();

        return $this->status === EmailAccessRequestStatus::PENDING
            && (! $deadline instanceof CarbonImmutable || $deadline->isFuture());
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
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<Email, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /**
     * @return BelongsTo<Email, $this>
     */
    public function emailIncludingHidden(): BelongsTo
    {
        return $this->email()->withTrashed()->withoutGlobalScope(ActiveAccountScope::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function emailable(): MorphTo
    {
        return $this->morphTo();
    }
}
