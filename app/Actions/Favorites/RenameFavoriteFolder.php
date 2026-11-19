<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Models\Favorite;
use App\Models\User;

final readonly class RenameFavoriteFolder
{
    public function execute(User $user, Favorite $folder, string $name): Favorite
    {
        abort_unless($folder->isFolder() && $folder->isOwnedBy($user, $folder->workspace_id) && $user->belongsToWorkspaceId($folder->workspace_id), 403);

        $folder->update(['name' => Favorite::folderName($name)]);

        return $folder;
    }
}
