<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Enums\FavoriteKind;
use App\Models\Company;
use App\Models\Favorite;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class AddFavoriteRecord
{
    public function execute(User $user, Company|People|Opportunity $record, ?Favorite $folder = null): Favorite
    {
        abort_unless($user->can('view', $record), 403);
        abort_if($folder instanceof Favorite && (! $folder->isFolder() || ! $folder->isOwnedBy($user, $record->workspace_id)), 403);

        $keys = [
            'workspace_id' => $record->workspace_id,
            'user_id' => $user->getKey(),
            'record_type' => $record->getMorphClass(),
            'record_id' => $record->getKey(),
        ];

        try {
            return Favorite::query()->withoutGlobalScopes()->firstOrCreate($keys, [
                'kind' => FavoriteKind::Record,
                'parent_id' => $folder?->getKey(),
                'position' => Favorite::nextPosition($user, $record->workspace_id, $folder?->getKey()),
            ]);
        } catch (UniqueConstraintViolationException) {
            return Favorite::query()->withoutGlobalScopes()->where($keys)->firstOrFail();
        }
    }
}
