<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class DeleteFavoriteFolder
{
    public function execute(User $user, FavoriteFolder $folder): void
    {
        abort_unless($user->belongsToWorkspaceId($folder->workspace_id) && $folder->user_id === $user->getKey(), 403);

        DB::transaction(function () use ($user, $folder): void {
            $favorites = FavoriteRecord::query()
                ->ownedBy($user, $folder->workspace_id)
                ->where('favorite_folder_id', $folder->getKey())
                ->orderBy('position')
                ->get();

            $favorites->each->update(['favorite_folder_id' => null, 'position' => 0]);

            $folder->delete();

            $position = FavoriteRecord::nextTopLevelPosition($user, $folder->workspace_id);

            foreach ($favorites as $favorite) {
                $favorite->update(['favorite_folder_id' => null, 'position' => $position++]);
            }
        });
    }
}
