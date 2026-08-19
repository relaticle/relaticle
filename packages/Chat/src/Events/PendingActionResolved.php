<?php

declare(strict_types=1);

namespace Relaticle\Chat\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A pending action, a single proposal or one item of a batch, was approved
 * or rejected. Rides the conversation channel every open tab already
 * subscribes to, so a proposal resolved in one tab (or by approveItem()/
 * rejectItem() mid-batch) reconciles to a terminal state everywhere else
 * instead of leaving a stale card offering Approve/Reject on an action that
 * has already been decided.
 *
 * `status` describes the resolution this event carries: for a single action
 * it is the action's own terminal status; for a batch item it is that item's
 * decision ('approved' or 'rejected'), independent of the parent action's
 * `status` column, which stays 'pending' until every item is resolved.
 */
final class PendingActionResolved implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $pendingActionId,
        public readonly string $status,
        public readonly ?int $index = null,
        public readonly bool $finalized = true,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("chat.conversation.{$this->conversationId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'pending_action.resolved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'pending_action_id' => $this->pendingActionId,
            'status' => $this->status,
            'index' => $this->index,
            'finalized' => $this->finalized,
        ];
    }
}
