<?php

declare(strict_types=1);

namespace Relaticle\Chat\Commands;

use App\Jobs\OpenDataArrivedTurn;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\PendingDispatch;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;

#[Description('Mark expired pending chat actions as expired')]
#[Signature('chat:expire-pending-actions')]
final class ExpirePendingActionsCommand extends Command
{
    public function handle(PendingActionService $service): int
    {
        $conversationIds = PendingAction::query()
            ->expired()
            ->whereNotNull('conversation_id')
            ->distinct()
            ->pluck('conversation_id');

        $count = $service->expireStale();

        AgentConversation::query()
            ->setup()
            ->whereKey($conversationIds)
            ->pluck('workspace_id')
            ->each(fn (string $workspaceId): PendingDispatch => dispatch(new OpenDataArrivedTurn($workspaceId)));

        $this->comment("Expired {$count} pending action(s).");

        return self::SUCCESS;
    }
}
