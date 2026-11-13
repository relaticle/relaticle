<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Actions\Auth\RevokeUserTokens;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DestroyOtherBrowserSessions
{
    public function __construct(private RevokeUserTokens $revokeUserTokens) {}

    public function execute(User $user, string $currentSessionId): bool
    {
        $this->revokeUserTokens->execute($user);

        $user->setRememberToken(Str::random(60));
        $user->save();

        // Only the database driver can find the user's other live sessions. Callers confirm identity first.
        if (config('session.driver') !== 'database') {
            return false;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->where('id', '!=', $currentSessionId)
            ->delete();

        return true;
    }
}
