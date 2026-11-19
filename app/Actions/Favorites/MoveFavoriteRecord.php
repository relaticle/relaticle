<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Models\Company;
use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;

final readonly class MoveFavoriteRecord
{
    public function execute(User $user, Company|People|Opportunity $record, ?FavoriteFolder $folder): void
    {
        abort_unless($user->belongsToWorkspaceId($record->workspace_id), 403);
        abort_if($folder instanceof FavoriteFolder && ! $folder->isOwnedBy($user, $record->workspace_id), 403);

        $favorite = FavoriteRecord::ownedFor($user, $record);

        if (! $favorite instanceof FavoriteRecord || $favorite->favorite_folder_id === $folder?->getKey()) {
            return;
        }

        $favorite->update([
            'favorite_folder_id' => $folder?->getKey(),
            'position' => FavoriteRecord::nextPosition($user, $record->workspace_id, $folder),
        ]);
    }
}
