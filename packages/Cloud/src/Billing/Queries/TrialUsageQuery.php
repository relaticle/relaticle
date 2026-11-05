<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Queries;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Task;
use App\Models\Workspace;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\Cloud\Billing\Data\TrialUsage;

final readonly class TrialUsageQuery
{
    public function forWorkspace(Workspace $workspace): TrialUsage
    {
        $id = (string) $workspace->getKey();

        return new TrialUsage(
            records: $this->records($id),
            questions: AgentConversationMessage::query()
                ->typed()
                ->whereRelation('conversation', 'workspace_id', $id)
                ->count(),
        );
    }

    private function records(string $workspaceId): int
    {
        return Company::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->ownData()->count()
            + People::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->ownData()->count()
            + Opportunity::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->ownData()->count()
            + Task::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->ownData()->count()
            + Note::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->ownData()->count();
    }
}
