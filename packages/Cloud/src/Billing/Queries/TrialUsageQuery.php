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
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\Cloud\Billing\Data\TrialUsage;

final readonly class TrialUsageQuery
{
    public function forWorkspace(Workspace $workspace): TrialUsage
    {
        $id = (string) $workspace->getKey();
        $since = $workspace->pro_trial_used_at;

        return new TrialUsage(
            records: $this->records($id, $since),
            questions: AgentConversationMessage::query()
                ->typed()
                ->whereNull('superseded_at')
                ->inWorkspace($id)
                ->where(self::createdSince($since))
                ->count(),
        );
    }

    private function records(string $workspaceId, ?CarbonInterface $since): int
    {
        $since = self::createdSince($since);

        return Company::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->ownData()->where($since)->count()
            + People::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->ownData()->where($since)->count()
            + Opportunity::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->ownData()->where($since)->count()
            + Task::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->ownData()->where($since)->count()
            + Note::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->ownData()->where($since)->count();
    }

    private static function createdSince(?CarbonInterface $since): Closure
    {
        return static function (Builder $query) use ($since): void {
            if ($since !== null) {
                $query->where('created_at', '>=', $since);
            }
        };
    }
}
