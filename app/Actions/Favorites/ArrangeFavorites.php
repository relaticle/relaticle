<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class ArrangeFavorites
{
    private const string TOP = 'top';

    /**
     * @param  array<mixed>  $tree
     */
    public function execute(User $user, Workspace $workspace, array $tree): void
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        DB::transaction(function () use ($user, $workspace, $tree): void {
            $folders = FavoriteFolder::query()->ownedBy($user, $workspace->getKey())->orderBy('position')->get();
            $favorites = FavoriteRecord::query()->ownedBy($user, $workspace->getKey())->orderBy('position')->get();

            /** @var Collection<string, FavoriteFolder|FavoriteRecord> $items */
            $items = collect([...$folders, ...$favorites])->keyBy(fn (FavoriteFolder|FavoriteRecord $item): string => $this->key($item));

            $current = $this->currentContainers($folders, $favorites);
            $posted = $this->postedContainers($tree, $items);

            foreach ($current as $container => $stored) {
                $order = $this->keepUnpostedInPlace($container, $stored, $posted);

                foreach ($order as $index => $key) {
                    $this->place($items[$key], $container === self::TOP ? null : $container, $index + 1);
                }
            }
        });
    }

    /**
     * @param  Collection<int, FavoriteFolder>  $folders
     * @param  Collection<int, FavoriteRecord>  $favorites
     * @return array<string, list<string>>
     */
    private function currentContainers(Collection $folders, Collection $favorites): array
    {
        $current = [self::TOP => array_values(collect([...$folders, ...$favorites->whereNull('favorite_folder_id')])
            ->sortBy(fn (FavoriteFolder|FavoriteRecord $item): int => $item->position)
            ->map(fn (FavoriteFolder|FavoriteRecord $item): string => $this->key($item))
            ->all())];

        foreach ($folders as $folder) {
            $current[$folder->getKey()] = array_values($favorites->where('favorite_folder_id', $folder->getKey())
                ->map(fn (FavoriteRecord $favorite): string => $this->key($favorite))
                ->all());
        }

        return $current;
    }

    /**
     * @param  array<mixed>  $tree
     * @param  Collection<string, FavoriteFolder|FavoriteRecord>  $items
     * @return array<string, list<string>>
     */
    private function postedContainers(array $tree, Collection $items): array
    {
        $posted = [self::TOP => is_array($tree['top'] ?? null) ? $tree['top'] : []];

        foreach (is_array($tree['folders'] ?? null) ? $tree['folders'] : [] as $folderId => $ids) {
            if (is_string($folderId) && $items->has('folder:'.$folderId) && is_array($ids)) {
                $posted[$folderId] = array_map(fn (mixed $id): ?string => is_string($id) ? 'record:'.$id : null, $ids);
            }
        }

        $seen = [];

        foreach ($posted as $container => $keys) {
            $keys = array_filter($keys, fn (mixed $key): bool => is_string($key)
                && $items->has($key)
                && ! in_array($key, $seen, true)
                && ($container === self::TOP || str_starts_with($key, 'record:')));

            $posted[$container] = array_values(array_unique($keys));
            $seen = [...$seen, ...$posted[$container]];
        }

        return $posted;
    }

    /**
     * @param  list<string>  $stored
     * @param  array<string, list<string>>  $posted
     * @return list<string>
     */
    private function keepUnpostedInPlace(string $container, array $stored, array $posted): array
    {
        $postedHere = $posted[$container] ?? [];
        $postedElsewhere = array_merge(...array_values(array_diff_key($posted, [$container => true])));
        $queue = $postedHere;
        $order = [];

        foreach (array_diff($stored, $postedElsewhere) as $key) {
            $order[] = in_array($key, $postedHere, true) ? array_shift($queue) : $key;
        }

        return [...$order, ...$queue];
    }

    private function place(FavoriteFolder|FavoriteRecord $item, ?string $folderId, int $position): void
    {
        $item->position = $position;

        if ($item instanceof FavoriteRecord) {
            $item->favorite_folder_id = $folderId;
        }

        $item->save();
    }

    private function key(FavoriteFolder|FavoriteRecord $item): string
    {
        return ($item instanceof FavoriteFolder ? 'folder:' : 'record:').$item->getKey();
    }
}
