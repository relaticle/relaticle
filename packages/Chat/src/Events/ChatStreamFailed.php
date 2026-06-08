<?php

declare(strict_types=1);

namespace Relaticle\Chat\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class ChatStreamFailed implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $message,
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
        return 'stream.failed';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'conversationId' => $this->conversationId,
            'message' => $this->message,
        ];
    }
}
