<?php

declare(strict_types=1);

namespace Relaticle\Chat\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class FollowUpsSuggested implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    /**
     * @param  array<int, array{label: string, prompt: string}>  $chips
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly array $chips,
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
        return 'follow_ups';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['chips' => $this->chips];
    }
}
