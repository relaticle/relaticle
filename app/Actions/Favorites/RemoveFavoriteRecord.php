<?php

declare(strict_types=1);

namespace App\Actions\Favorites;

use App\Models\Company;
use App\Models\Favorite;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;

final readonly class RemoveFavoriteRecord
{
    public function execute(User $user, Company|People|Opportunity $record): void
    {
        abort_unless($user->belongsToWorkspaceId($record->workspace_id), 403);

        Favorite::ownedFor($user, $record)?->delete();
    }
}
