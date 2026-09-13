<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\URL;

final class MailboxOAuthWorkspace
{
    public static function redirectUrl(string $provider, Team $team): string
    {
        return URL::temporarySignedRoute(
            'email-accounts.redirect',
            now()->addHour(),
            [
                'provider' => $provider,
                'team' => $team->getKey(),
            ],
        );
    }

    public static function forUser(User $user, mixed $teamId): ?Team
    {
        if (! is_string($teamId) || $teamId === '' || ! $user->belongsToTeamId($teamId)) {
            return null;
        }

        $team = Team::query()->find($teamId);

        return $team instanceof Team ? $team : null;
    }
}
