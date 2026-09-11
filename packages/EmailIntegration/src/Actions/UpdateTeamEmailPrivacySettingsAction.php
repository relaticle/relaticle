<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

final readonly class UpdateTeamEmailPrivacySettingsAction
{
    public function execute(Team $team, User $actor, EmailPrivacyTier $defaultTier): void
    {
        // Team-wide sharing defaults may only be changed by the team owner or an admin,
        // regardless of which caller path reaches this action.
        abort_unless(
            $actor->ownsTeam($team) || $actor->hasTeamRole($team, TeamRole::Admin->value),
            403,
        );

        $team->update([
            'default_email_sharing_tier' => $defaultTier->value,
        ]);
    }
}
