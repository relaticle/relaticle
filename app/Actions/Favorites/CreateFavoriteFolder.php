<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Enums\FavoriteKind;
use App\Models\Favorite;
use App\Models\User;
use App\Models\Workspace;

final readonly class CreateFavoriteFolder
{
    public function execute(User $user, Workspace $workspace, string $name): Favorite
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        return Favorite::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'kind' => FavoriteKind::Folder,
            'name' => Favorite::folderName($name),
            'position' => Favorite::nextPosition($user, $workspace->getKey(), null),
        ]);
    }
}
