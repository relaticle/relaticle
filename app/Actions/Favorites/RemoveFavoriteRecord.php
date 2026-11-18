<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Models\Company;
use App\Models\FavoriteRecord;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;

final readonly class RemoveFavoriteRecord
{
    public function execute(User $user, Company|People|Opportunity $record): void
    {
        abort_unless($user->belongsToWorkspaceId($record->workspace_id), 403);

        FavoriteRecord::query()
            ->ownedBy($user, $record->workspace_id)
            ->where('record_type', $record->getMorphClass())
            ->where('record_id', $record->getKey())
            ->delete();
    }
}
