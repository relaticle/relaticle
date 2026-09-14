<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\URL;

final class MailboxOAuthWorkspace
{
    public static function redirectUrl(string $provider, Workspace $team): string
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

    public static function forUser(User $user, mixed $teamId): ?Workspace
    {
        if (! is_string($teamId) || $teamId === '' || ! $user->belongsToWorkspaceId($teamId)) {
            return null;
        }

        $team = Workspace::query()->find($teamId);

        return $team instanceof Workspace ? $team : null;
    }
}
