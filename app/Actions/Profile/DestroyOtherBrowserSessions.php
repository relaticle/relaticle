<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DestroyOtherBrowserSessions
{
    /**
     * Destroy every database session for the user except the current request's,
     * then rotate the remember token so other devices' "remember me" cookies stop
     * working. Identity is confirmed by the caller before this runs.
     */
    public function execute(User $user, string $currentSessionId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->where('id', '!=', $currentSessionId)
            ->delete();

        $user->setRememberToken(Str::random(60));
        $user->save();
    }
}
