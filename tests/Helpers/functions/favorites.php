<?php

declare(strict_types=1);

use App\Enums\FavoriteKind;
use App\Models\Favorite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

function favoriteFor(User $user, Workspace $workspace, Model $record, int $position, ?Favorite $folder = null): Favorite
{
    return Favorite::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'kind' => FavoriteKind::Record,
        'record_type' => $record->getMorphClass(),
        'record_id' => $record->getKey(),
        'parent_id' => $folder?->getKey(),
        'position' => $position,
    ]);
}

function favoriteFolderFor(User $user, Workspace $workspace, string $name, int $position): Favorite
{
    return Favorite::factory()->folder($name)->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'position' => $position,
    ]);
}

/**
 * @return list<string>
 */
function favoriteOrder(User $user, Workspace $workspace, ?Favorite $folder = null): array
{
    return Favorite::query()
        ->ownedBy($user, $workspace->getKey())
        ->where('parent_id', $folder?->getKey())
        ->orderBy('position')
        ->pluck('id')
        ->all();
}
