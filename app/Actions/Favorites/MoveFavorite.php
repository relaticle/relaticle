<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Models\Favorite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Flowforge\Services\DecimalPosition;

final readonly class MoveFavorite
{
    public function execute(User $user, Favorite $favorite, ?Favorite $folder, ?Favorite $after = null, ?Favorite $before = null): void
    {
        $workspaceId = $favorite->workspace_id;

        abort_unless($favorite->isOwnedBy($user, $workspaceId) && $user->belongsToWorkspaceId($workspaceId), 403);
        abort_if($folder instanceof Favorite && ($favorite->isFolder() || ! $folder->isFolder() || $folder->parent_id !== null || ! $folder->isOwnedBy($user, $workspaceId)), 403);

        $parentId = $folder?->getKey();
        $after = $this->sibling($user, $after, $favorite, $parentId);
        $before = $this->sibling($user, $before, $favorite, $parentId);

        if ($after instanceof Favorite && $before instanceof Favorite && DecimalPosition::greaterThan($after->position, $before->position)) {
            $before = null;
        }

        if (! $after instanceof Favorite && ! $before instanceof Favorite) {
            if ($favorite->parent_id !== $parentId) {
                $favorite->update(['parent_id' => $parentId, 'position' => Favorite::nextPosition($user, $workspaceId, $parentId)]);
            }

            return;
        }

        DB::transaction(function () use ($user, $favorite, $parentId, $after, $before): void {
            if ($after instanceof Favorite && $before instanceof Favorite && DecimalPosition::needsRebalancing($after->position, $before->position)) {
                $this->rebalance($user, $favorite, $parentId);
                $after->refresh();
                $before->refresh();
            }

            $favorite->update([
                'parent_id' => $parentId,
                'position' => DecimalPosition::calculate($after?->position, $before?->position),
            ]);
        });
    }

    private function sibling(User $user, ?Favorite $neighbour, Favorite $favorite, ?string $parentId): ?Favorite
    {
        return $neighbour instanceof Favorite
            && ! $neighbour->is($favorite)
            && $neighbour->parent_id === $parentId
            && $neighbour->isOwnedBy($user, $favorite->workspace_id)
                ? $neighbour
                : null;
    }

    private function rebalance(User $user, Favorite $favorite, ?string $parentId): void
    {
        $siblings = Favorite::query()->ownedBy($user, $favorite->workspace_id)
            ->where('parent_id', $parentId)
            ->whereKeyNot($favorite->getKey())
            ->orderBy('position')
            ->get();

        $positions = DecimalPosition::generateSequence($siblings->count());

        foreach ($siblings as $index => $sibling) {
            $sibling->update(['position' => $positions[$index]]);
        }
    }
}
