<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\InvokingTool;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\ProposalPlanService;
use RuntimeException;

final readonly class ApprovesMidTurn
{
    public static function listen(EvalWorkspace $w, string $entity): void
    {
        Event::listen(InvokingTool::class, function (InvokingTool $event) use ($w, $entity): void {
            $step = PendingAction::query()
                ->where('workspace_id', $w->workspace->getKey())
                ->where('entity_type', $entity)
                ->where('status', PendingActionStatus::Pending)
                ->first();

            if (! $step instanceof PendingAction) {
                return;
            }

            $outcome = resolve(ProposalPlanService::class)->approveAll($step, $w->owner);

            if ($outcome['failed'] !== null) {
                throw new RuntimeException('The mid-turn approval failed: '.$outcome['failed']['message']);
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function storedRecords(EvalWorkspace $w, string $entity): array
    {
        return PendingAction::query()
            ->where('workspace_id', $w->workspace->getKey())
            ->where('entity_type', $entity)
            ->get()
            ->flatMap(fn (PendingAction $action): array => is_array($action->action_data['records'] ?? null) ? $action->action_data['records'] : [$action->action_data])
            ->values()
            ->all();
    }

    public static function approved(EvalWorkspace $w, string $entity): bool
    {
        return PendingAction::query()
            ->where('workspace_id', $w->workspace->getKey())
            ->where('entity_type', $entity)
            ->where('status', PendingActionStatus::Approved)
            ->exists();
    }
}
