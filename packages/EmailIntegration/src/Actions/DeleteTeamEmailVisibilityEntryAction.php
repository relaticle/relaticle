<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;

final readonly class DeleteTeamEmailVisibilityEntryAction
{
    public function execute(Workspace $team, User $actor, TeamEmailBlocklist $entry): void
    {
        abort_unless(
            $actor->hasWorkspaceCapability($team->getKey(), WorkspaceCapability::EmailManage),
            403,
        );

        abort_unless($entry->workspace_id === $team->getKey(), 403);

        $entry->delete();
    }
}
