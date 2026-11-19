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
                $favorite->children->filter(fn (Favorite $child): bool => $this->isVisible($user, $child))->values(),
            ))
            ->filter(fn (Favorite $favorite): bool => $favorite->isFolder() || $this->isVisible($user, $favorite))
            ->values();
    }

    private function isVisible(User $user, Favorite $favorite): bool
    {
        return $favorite->record !== null && $user->can('view', $favorite->record);
    }
}
