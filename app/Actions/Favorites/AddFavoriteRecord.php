<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Models\Company;
use App\Models\FavoriteRecord;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class AddFavoriteRecord
{
    public function execute(User $user, Company|People|Opportunity $record): FavoriteRecord
    {
        abort_unless($user->can('view', $record), 403);

        $keys = [
            'workspace_id' => $record->workspace_id,
            'user_id' => $user->getKey(),
            'record_type' => $record->getMorphClass(),
            'record_id' => $record->getKey(),
        ];

        try {
            return FavoriteRecord::query()->withoutGlobalScopes()->firstOrCreate($keys, [
                'position' => FavoriteRecord::nextTopLevelPosition($user, $record->workspace_id),
            ]);
        } catch (UniqueConstraintViolationException) {
            return FavoriteRecord::query()->withoutGlobalScopes()->where($keys)->firstOrFail();
        }
    }
}
