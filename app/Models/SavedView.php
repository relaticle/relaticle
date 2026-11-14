<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\ViewStateCast;
use App\Data\BoardStateData;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Enums\SavedViewType;
use App\Models\Concerns\HasWorkspace;
use App\Models\Scopes\WorkspaceScope;
use Database\Factories\SavedViewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property CrmEntity $entity
 * @property SavedViewType $type
 * @property string $name
 * @property TableStateData|BoardStateData $state
 * @property string $user_id
 */
#[ScopedBy(WorkspaceScope::class)]
#[Fillable(['workspace_id', 'user_id', 'entity', 'type', 'name', 'state'])]
final class SavedView extends Model
{
    /** @use HasFactory<SavedViewFactory> */
    use HasFactory;

    use HasUlids;
    use HasWorkspace;

    public const int NAME_MAX_LENGTH = 255;

    public const string BUILT_IN_LIST_LINK = 'all';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity' => CrmEntity::class,
            'type' => SavedViewType::class,
            'state' => ViewStateCast::class,
        ];
    }

    public function tableState(): TableStateData
    {
        return $this->state instanceof TableStateData ? $this->state : throw new LogicException('This view holds a board state.');
    }

    public function boardState(): BoardStateData
    {
        return $this->state instanceof BoardStateData ? $this->state : throw new LogicException('This view holds a table state.');
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
            ->whereBelongsTo($user);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function named(Builder $query, User $user, string $workspaceId, CrmEntity $entity, string $name): void
    {
        $query->visibleTo($user, $workspaceId, $entity)
            ->whereRaw('lower(name) = lower(?)', [trim($name)]);
    }
}
