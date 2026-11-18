<?php

declare(strict_types=1);

use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

function favoriteFor(User $user, Workspace $workspace, Model $record, int $position, ?FavoriteFolder $folder = null): FavoriteRecord
{
    return FavoriteRecord::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'record_type' => $record->getMorphClass(),
        'record_id' => $record->getKey(),
        'favorite_folder_id' => $folder?->getKey(),
        'position' => $position,
    ]);
}
