<?php

declare(strict_types=1);

namespace App\Queries\Favorites;

use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class FavoritesQuery
{
    /**
     * @return Collection<int, FavoriteFolder|FavoriteRecord>
     */
    public function tree(User $user, string $workspaceId): Collection
    {
        $folders = FavoriteFolder::query()
            ->ownedBy($user, $workspaceId)
            ->with('favoriteRecords.record')
            ->get()
            ->each(fn (FavoriteFolder $folder): FavoriteFolder => $folder->setRelation(
                'favoriteRecords',
                $folder->favoriteRecords->filter(fn (FavoriteRecord $favorite): bool => $favorite->record !== null)->values(),
            ));

        $favorites = FavoriteRecord::query()
            ->ownedBy($user, $workspaceId)
            ->whereNull('favorite_folder_id')
            ->with('record')
            ->get()
            ->filter(fn (FavoriteRecord $favorite): bool => $favorite->record !== null);

        /** @var Collection<int, FavoriteFolder|FavoriteRecord> */
        return collect([...$folders, ...$favorites])
            ->sortBy(fn (FavoriteFolder|FavoriteRecord $item): int => $item->position)
            ->values();
    }
}
