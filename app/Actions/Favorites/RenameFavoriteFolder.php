<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Models\FavoriteFolder;
use App\Models\User;

final readonly class RenameFavoriteFolder
{
    public function execute(User $user, FavoriteFolder $folder, string $name): FavoriteFolder
    {
        abort_unless($user->belongsToWorkspaceId($folder->workspace_id) && $folder->user_id === $user->getKey(), 403);

        $folder->update(['name' => trim($name)]);

        return $folder;
    }
}
