<?php

declare(strict_types=1);

namespace Relaticle\Chat\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class ChatStreamRetrying implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly string $conversationId,
        public readonly int $attempt,
        public readonly int $maxAttempts,
        public readonly int $delaySeconds,
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
        return 'stream.retrying';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'conversationId' => $this->conversationId,
            'attempt' => $this->attempt,
            'maxAttempts' => $this->maxAttempts,
            'delaySeconds' => $this->delaySeconds,
        ];
    }
}
