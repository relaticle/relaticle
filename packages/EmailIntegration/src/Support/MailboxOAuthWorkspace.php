<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Contracts\Entitlements;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\URL;

final class MailboxOAuthWorkspace
{
    public static function redirectUrl(string $provider, Workspace $workspace, ?string $returnUrl = null): string
    {
        return URL::temporarySignedRoute(
            'email-accounts.redirect',
            now()->addHour(),
            array_filter([
                'provider' => $provider,
                'workspace' => $workspace->getKey(),
                'return' => $returnUrl,
            ]),
        );
    }

    public static function forUser(User $user, mixed $workspaceId): ?Workspace
    {
        if (! is_string($workspaceId) || $workspaceId === '' || ! $user->belongsToWorkspaceId($workspaceId)) {
            return null;
        }

        $workspace = Workspace::query()->find($workspaceId);

        if (! $workspace instanceof Workspace || ! resolve(Entitlements::class)->allowsAccess($workspace)) {
            return null;
        }

        return $workspace;
    }
}
