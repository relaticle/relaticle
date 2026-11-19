<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FavoriteKind;
use App\Models\Concerns\HasWorkspace;
use App\Models\Scopes\WorkspaceScope;
use Database\Factories\FavoriteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Once;
use Relaticle\Flowforge\Services\DecimalPosition;

/**
 * @property string $user_id
 * @property FavoriteKind $kind
 * @property string|null $parent_id
 * @property string|null $name
 * @property string|null $record_type
 * @property string|null $record_id
 * @property string $position
 */
#[ScopedBy(WorkspaceScope::class)]
#[Fillable(['workspace_id', 'user_id', 'kind', 'parent_id', 'name', 'record_type', 'record_id', 'position'])]
final class Favorite extends Model
{
    public const int NAME_MAX_LENGTH = 60;

    /** @use HasFactory<FavoriteFactory> */
    use HasFactory;

    use HasUlids;
    use HasWorkspace;

    protected static function booted(): void
    {
        self::saved(fn (): null => Once::flush());
        self::deleted(fn (): null => Once::flush());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FavoriteKind::class,
            'position' => 'decimal:'.DecimalPosition::SCALE,
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function record(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFolder(): bool
    {
        return $this->kind === FavoriteKind::Folder;
    }

    public function isOwnedBy(User $user, string $workspaceId): bool
    {
        return $this->user_id === $user->getKey() && $this->workspace_id === $workspaceId;
    }

    public static function folderName(string $name): string
    {
        $name = trim($name);

        abort_if($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH, 422);

        return $name;
    }

    public static function ownedFor(User $user, Company|People|Opportunity $record): ?self
    {
        return once(fn (): ?self => self::query()->ownedBy($user, $record->workspace_id)->forRecord($record)->first());
    }

    public static function nextPosition(User $user, string $workspaceId, ?string $parentId): string
    {
        $last = self::query()->ownedBy($user, $workspaceId)->where('parent_id', $parentId)->max('position');

        return $last === null ? DecimalPosition::forEmptyColumn() : DecimalPosition::after((string) $last);
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

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forRecord(Builder $query, Model $record): void
    {
        $query->where('record_type', $record->getMorphClass())->where('record_id', $record->getKey());
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function folders(Builder $query): void
    {
        $query->where('kind', FavoriteKind::Folder);
    }
}
