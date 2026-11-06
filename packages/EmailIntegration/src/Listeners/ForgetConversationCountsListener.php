<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Relaticle\EmailIntegration\Events\ConversationChanged;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

final readonly class ForgetConversationCountsListener
{
    // Every member active in the workspace, a superset of the audience that skips its visibility queries.
    public function handle(ConversationChanged $event): void
    {
        $this->forWorkspace($event->workspaceId);
    }

    public function forWorkspace(string $workspaceId): void
    {
        $members = User::query()
            ->inWorkspace($workspaceId)
            ->where('current_workspace_id', $workspaceId)
            ->get(['id', 'current_workspace_id']);

        foreach ($members as $member) {
            Cache::forget(ConversationsQuery::countsCacheKey($member));
        }
    }
}
