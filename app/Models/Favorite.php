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
use Illuminate\Support\Facades\Validator;
use Relaticle\Flowforge\Services\DecimalPosition;
use WeakMap;

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
    /** @use HasFactory<FavoriteFactory> */
    use HasFactory;

    use HasUlids;
    use HasWorkspace;

    public const int NAME_MAX_LENGTH = 60;

    /** @var WeakMap<Model, array<array-key, ?self>>|null */
    private static ?WeakMap $ownedFor = null;

    protected static function booted(): void
    {
        self::saved(fn (): null => self::$ownedFor = null);
        self::deleted(fn (): null => self::$ownedFor = null);
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

        Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH]])->validate();

        return $name;
    }

    public static function ownedFor(User $user, Company|People|Opportunity $record): ?self
    {
        self::$ownedFor ??= new WeakMap;
        $byUser = self::$ownedFor[$record] ?? [];

        if (! array_key_exists($user->getKey(), $byUser)) {
            $byUser[$user->getKey()] = self::query()->ownedBy($user, $record->workspace_id)->forRecord($record)->first();
            self::$ownedFor[$record] = $byUser;
        }

        return $byUser[$user->getKey()];
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
