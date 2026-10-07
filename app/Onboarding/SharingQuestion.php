<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class SharingQuestion
{
    public static function appliesTo(User $user, Workspace $workspace): bool
    {
        return $user->default_email_sharing_tier === null
            && ! ConnectedAccount::hasMailboxOutside($user, $workspace);
    }
}
