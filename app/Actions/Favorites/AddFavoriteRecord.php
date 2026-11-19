<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Models\Company;
use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class AddFavoriteRecord
{
    public function execute(User $user, Company|People|Opportunity $record, ?FavoriteFolder $folder = null): FavoriteRecord
    {
        abort_unless($user->can('view', $record), 403);
        abort_if($folder instanceof FavoriteFolder && ! $folder->isOwnedBy($user, $record->workspace_id), 403);

        $keys = [
            'workspace_id' => $record->workspace_id,
            'user_id' => $user->getKey(),
            'record_type' => $record->getMorphClass(),
            'record_id' => $record->getKey(),
        ];

        try {
            return FavoriteRecord::query()->withoutGlobalScopes()->firstOrCreate($keys, [
                'favorite_folder_id' => $folder?->getKey(),
                'position' => FavoriteRecord::nextPosition($user, $record->workspace_id, $folder),
            ]);
        } catch (UniqueConstraintViolationException) {
            return FavoriteRecord::query()->withoutGlobalScopes()->where($keys)->firstOrFail();
        }
    }
}
