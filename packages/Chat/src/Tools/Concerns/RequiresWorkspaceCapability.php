<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

use App\Enums\WorkspaceCapability;
use App\Models\User;

trait RequiresWorkspaceCapability
{
    /**
     * A proposal the approver cannot execute fails at approval with a bare 403,
     * so the refusal belongs here, before the card is ever written.
     */
    protected function capabilityError(User $user, WorkspaceCapability $capability): ?string
    {
        if ($user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), $capability)) {
            return null;
        }

        return (string) json_encode([
            'error' => __('This user\'s workspace role does not allow that, so nothing was proposed. Tell them a workspace owner or admin can do it or change their role. Do not link to any page.'),
        ], JSON_UNESCAPED_SLASHES);
    }
}
