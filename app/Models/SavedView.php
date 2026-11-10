<?php

declare(strict_types=1);

namespace App\Models;

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

/**
 * @property CrmEntity $entity
 * @property SavedViewType $type
 * @property string $name
 * @property bool $is_shared
 * @property TableStateData $state
 * @property string $user_id
 */
#[ScopedBy(WorkspaceScope::class)]
#[Fillable(['workspace_id', 'user_id', 'entity', 'type', 'name', 'is_shared', 'state'])]
final class SavedView extends Model
{
    /** @use HasFactory<SavedViewFactory> */
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
            'type' => SavedViewType::class,
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

    public function nameTakenMessage(User $actor): string
    {
        return $this->user_id === $actor->getKey()
            ? __('filament/panel.tables.views.name_taken')
            : __('filament/panel.tables.views.name_taken_by_owner', ['owner' => $this->user->name]);
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
            ->whereRaw('lower(name) = lower(?)', [trim($name)]);
    }
}
