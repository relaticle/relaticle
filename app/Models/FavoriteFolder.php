<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasWorkspace;
use App\Models\Scopes\WorkspaceScope;
use Database\Factories\FavoriteFolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $user_id
 * @property string $name
 * @property int $position
 */
#[ScopedBy(WorkspaceScope::class)]
#[Fillable(['workspace_id', 'user_id', 'name', 'position'])]
final class FavoriteFolder extends Model
{
    public const int NAME_MAX_LENGTH = 60;

    /** @use HasFactory<FavoriteFolderFactory> */
    use HasFactory;

    use HasUlids;
    use HasWorkspace;

    /**
     * @return HasMany<FavoriteRecord, $this>
     */
    public function favoriteRecords(): HasMany
    {
        return $this->hasMany(FavoriteRecord::class)->orderBy('position');
    }

    public function isOwnedBy(User $user, string $workspaceId): bool
    {
        return $this->user_id === $user->getKey() && $this->workspace_id === $workspaceId;
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
    protected function ownedBy(Builder $query, User $user, string $workspaceId): void
    {
        $query->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->whereBelongsTo($user);
    }
}
