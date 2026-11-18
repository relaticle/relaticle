<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasWorkspace;
use App\Models\Scopes\WorkspaceScope;
use Database\Factories\FavoriteRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $user_id
 * @property string $record_type
 * @property string $record_id
 * @property string|null $favorite_folder_id
 * @property int $position
 */
#[ScopedBy(WorkspaceScope::class)]
#[Fillable(['workspace_id', 'user_id', 'record_type', 'record_id', 'favorite_folder_id', 'position'])]
final class FavoriteRecord extends Model
{
    /** @use HasFactory<FavoriteRecordFactory> */
    use HasFactory;

    use HasUlids;
    use HasWorkspace;

    /**
     * @return MorphTo<Model, $this>
     */
    public function record(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<FavoriteFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(FavoriteFolder::class, 'favorite_folder_id');
    }

    public static function nextTopLevelPosition(User $user, string $workspaceId): int
    {
        $folders = FavoriteFolder::query()->ownedBy($user, $workspaceId)->max('position');
        $records = self::query()->ownedBy($user, $workspaceId)->whereNull('favorite_folder_id')->max('position');

        return 1 + (int) max($folders, $records);
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
