<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Notifications\NotificationReason;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @property ?NotificationReason $reason
 * @property ?string $workspace_id
 * @property ?string $actor_id
 */
final class Notification extends DatabaseNotification
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    use MassPrunable;

    private const int READ_RETENTION_DAYS = 90;

    private const int MAX_RETENTION_DAYS = 365;

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $closed): void {
                        $closed->whereNotNull('read_at')->orWhereNotNull('archived_at');
                    })
                    ->where('updated_at', '<', now()->subDays(self::READ_RETENTION_DAYS));
            })
            ->orWhere('created_at', '<', now()->subDays(self::MAX_RETENTION_DAYS));
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function visibleIn(Builder $query, ?Workspace $workspace): void
    {
        $query
            ->whereNull('archived_at')
            ->where(function (Builder $scope) use ($workspace): void {
                $scope->whereNull('workspace_id');

                if ($workspace instanceof Workspace) {
                    $scope->orWhere('workspace_id', $workspace->getKey());
                }
            });
    }

    protected function casts(): array
    {
        return [
            'reason' => NotificationReason::class,
            'archived_at' => 'immutable_datetime',
        ];
    }
}
