<?php

declare(strict_types=1);

namespace Relaticle\Chat\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class PendingActionsSuperseded implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    /**
     * @param  list<string>  $pendingActionIds
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly array $pendingActionIds,
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
        return 'pending_actions_superseded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['ids' => $this->pendingActionIds];
    }
}
