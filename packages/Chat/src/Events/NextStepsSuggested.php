<?php

declare(strict_types=1);

namespace Relaticle\Chat\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class NextStepsSuggested implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    /**
     * @param  list<array{label: string, prompt: string}>  $steps
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly array $steps,
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
        return 'next_steps';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['steps' => $this->steps];
    }
}
