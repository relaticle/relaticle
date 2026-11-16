<?php

declare(strict_types=1);

namespace Relaticle\Chat\Models;

use App\Models\Concerns\HasWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Enums\ProposalEntity;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property string|null $conversation_id
 * @property string|null $turn_id
 * @property string|null $message_id
 * @property string $action_class
 * @property PendingActionOperation $operation
 * @property ProposalEntity $entity_type
 * @property array<string, mixed> $action_data
 * @property array<string, mixed> $display_data
 * @property PendingActionStatus $status
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $resolved_at
 * @property array<string, mixed>|null $result_data
 */
#[Fillable([
    'workspace_id',
    'user_id',
    'conversation_id',
    'turn_id',
    'message_id',
    'action_class',
    'operation',
    'entity_type',
    'action_data',
    'display_data',
    'status',
    'expires_at',
    'resolved_at',
    'result_data',
])]
final class PendingAction extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlids;
    use HasWorkspace;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'operation' => PendingActionOperation::class,
            'entity_type' => ProposalEntity::class,
            'status' => PendingActionStatus::class,
            'action_data' => 'array',
            'display_data' => 'array',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
            'result_data' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === PendingActionStatus::Pending;
    }

    public function createdRecordId(?int $index = null): ?string
    {
        $result = is_array($this->result_data) ? $this->result_data : [];

        if ($index !== null) {
            $items = is_array($result['items'] ?? null) ? $result['items'] : [];
            $result = is_array($items[$index] ?? null) ? $items[$index] : [];
        }

        $id = $result['id'] ?? null;

        return (is_string($id) || is_int($id)) && $id !== '' ? (string) $id : null;
    }

    public function needsOwnApproval(): bool
    {
        return $this->entity_type->needsOwnApproval();
    }

    public function isEmailSend(): bool
    {
        return in_array($this->entity_type, [ProposalEntity::Email, ProposalEntity::EmailDraftSend], true);
    }

    /** @return list<string> */
    public function createdRecordIds(): array
    {
        $result = is_array($this->result_data) ? $this->result_data : [];
        $ids = is_array($result['ids'] ?? null) ? $result['ids'] : [$result['id'] ?? null];

        return array_values(array_map(strval(...), array_filter($ids, static fn (mixed $id): bool => (is_string($id) || is_int($id)) && $id !== '')));
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * @param  Builder<PendingAction>  $query
     * @return Builder<PendingAction>
     */
    #[Scope]
    protected function pending(Builder $query): Builder
    {
        return $query->where('status', PendingActionStatus::Pending);
    }

    /**
     * @param  Builder<PendingAction>  $query
     * @return Builder<PendingAction>
     */
    #[Scope]
    protected function expired(Builder $query): Builder
    {
        return $query
            ->where('status', PendingActionStatus::Pending)
            ->where('expires_at', '<', now());
    }
}
