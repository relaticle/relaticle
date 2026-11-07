<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\Concerns\HasWorkspace;
use App\Models\Scopes\WorkspaceScope;
use Database\Factories\TableLayoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CrmEntity $entity
 * @property string $name
 * @property bool $is_shared
 * @property TableStateData $state
 * @property string $user_id
 */
#[ScopedBy(WorkspaceScope::class)]
#[Fillable(['workspace_id', 'user_id', 'entity', 'name', 'is_shared', 'state'])]
final class TableLayout extends Model
{
    /** @use HasFactory<TableLayoutFactory> */
    use HasFactory;

    use HasUlids;
    use HasWorkspace;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity' => CrmEntity::class,
            'is_shared' => 'boolean',
            'state' => TableStateData::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user, string $workspaceId, CrmEntity $entity): void
    {
        $query->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->where('entity', $entity)
            ->where(fn (Builder $visible): Builder => $visible->whereBelongsTo($user)->orWhere('is_shared', true));
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function named(Builder $query, User $user, string $workspaceId, CrmEntity $entity, string $name): void
    {
        $query->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->where('entity', $entity)
            ->whereBelongsTo($user)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))]);
    }
}
