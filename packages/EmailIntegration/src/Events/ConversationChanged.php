<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\Attributes\Queue;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

#[Queue('default')]
final class ConversationChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use InteractsWithSockets;

    /** @param  list<string>  $alsoNotify  members who could see it before the change */
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $conversationKey,
        public readonly array $alsoNotify = [],
    ) {}

    public function broadcastWhen(): bool
    {
        return config('broadcasting.default') !== 'null' && EmailIntegrationServiceProvider::enabled();
    }

    public function broadcastAs(): string
    {
        return 'email.conversation.changed';
    }

    /** @return array{workspace_id: string, conversation_key: string} */
    public function broadcastWith(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'conversation_key' => $this->conversationKey,
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            fn (string $memberId): PrivateChannel => new PrivateChannel("App.Models.User.{$memberId}"),
            resolve(ConversationsQuery::class)->audience($this->workspaceId, $this->conversationKey, $this->alsoNotify),
        );
    }
}
