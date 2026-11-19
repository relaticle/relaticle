<?php

declare(strict_types=1);

namespace App\Queries\Favorites;

use App\Models\Favorite;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class FavoritesQuery
{
    /**
     * @return Collection<int, Favorite>
     */
    public function tree(User $user, string $workspaceId): Collection
    {
        return Favorite::query()
            ->ownedBy($user, $workspaceId)
            ->whereNull('parent_id')
            ->with(['record', 'children.record'])
            ->orderBy('position')
            ->get()
            ->each(fn (Favorite $favorite): Favorite => $favorite->setRelation(
                'children',
                $favorite->children->filter($this->hasRecord(...))->values(),
            ))
            ->filter(fn (Favorite $favorite): bool => $favorite->isFolder() || $this->hasRecord($favorite))
            ->values();
    }

    private function hasRecord(Favorite $favorite): bool
    {
        return $favorite->record !== null;
    }
}
