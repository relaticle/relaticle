<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

final readonly class UpdateTeamEmailPrivacySettingsAction
{
    public function execute(Workspace $team, User $actor, EmailPrivacyTier $defaultTier): void
    {
        // Team-wide sharing defaults may only be changed by the team owner or an admin,
        // regardless of which caller path reaches this action.
        abort_unless(
            $actor->hasWorkspaceCapability($team->getKey(), WorkspaceCapability::EmailManage),
            403,
        );

        $team->update([
            'default_email_sharing_tier' => $defaultTier->value,
        ]);
    }
}
