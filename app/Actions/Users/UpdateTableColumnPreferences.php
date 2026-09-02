<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;

final readonly class UpdateTableColumnPreferences
{
    /**
     * Persist a user's per-table column manager state (visibility + order).
     *
     * Column state lives in the session by default in Filament, which resets on
     * session expiry (and never crosses devices). Storing it per user in the DB
     * makes a user's column choices stick across sessions and browsers.
     *
     * @param  array<string, array{columns: array<int, mixed>, has_reordered: bool}>  $preferences
     */
    public function execute(User $user, array $preferences): void
    {
        $user->update(['table_column_preferences' => $preferences]);
    }
}
