<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

final readonly class UpdateWorkspaceEmailPrivacySettingsAction
{
    public function execute(Workspace $workspace, User $actor, EmailPrivacyTier $defaultTier): void
    {
        // Workspace-wide sharing defaults may only be changed by the workspace owner or an admin,
        // regardless of which caller path reaches this action.
        abort_unless(
            $actor->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::EmailManage),
            403,
        );

        abort_unless($defaultTier->canBeWorkspaceDefault(), 422);

        $workspace->update([
            'default_email_sharing_tier' => $defaultTier->value,
        ]);
    }
}
