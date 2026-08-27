<?php

declare(strict_types=1);

namespace Relaticle\Chat\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A conversation was auto-titled. Rides the conversation channel the chat page
 * is already subscribed to, so the sidebar, page heading, and browser tab all
 * update mid-turn without a reload.
 */
final class ConversationTitleGenerated implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $title,
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
        return 'conversation.title';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'conversationId' => $this->conversationId,
            'title' => $this->title,
        ];
    }
}
