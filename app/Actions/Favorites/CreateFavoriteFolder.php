<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
use App\Models\User;
use App\Models\Workspace;

final readonly class CreateFavoriteFolder
{
    public function execute(User $user, Workspace $workspace, string $name): FavoriteFolder
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        return FavoriteFolder::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'name' => trim($name),
            'position' => FavoriteRecord::nextTopLevelPosition($user, $workspace->getKey()),
        ]);
    }
}
