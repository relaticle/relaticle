<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Models\Favorite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Flowforge\Services\DecimalPosition;

final readonly class DeleteFavoriteFolder
{
    public function execute(User $user, Favorite $folder): void
    {
        abort_unless($folder->isFolder() && $folder->isOwnedBy($user, $folder->workspace_id) && $user->belongsToWorkspaceId($folder->workspace_id), 403);

        DB::transaction(function () use ($user, $folder): void {
            $position = Favorite::nextPosition($user, $folder->workspace_id, null);

            foreach (Favorite::query()->ownedBy($user, $folder->workspace_id)->where('parent_id', $folder->getKey())->orderBy('position')->get() as $child) {
                $child->update(['parent_id' => null, 'position' => $position]);
                $position = DecimalPosition::after($position);
            }

            $folder->delete();
        });
    }
}
