<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBeUnique;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Gate;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

#[Queue('default')]
#[UniqueFor(10)]
final class ConversationChanged implements ShouldBeUnique, ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $conversationKey,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->workspaceId}:{$this->conversationKey}";
    }

    public function broadcastWhen(): bool
    {
        return EmailIntegrationServiceProvider::enabled();
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

    // The visibility scope binds the viewer's current workspace, so a member active elsewhere cannot be checked.
    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $conversations = resolve(ConversationsQuery::class);

        return array_values(User::query()
            ->inWorkspace($this->workspaceId)
            ->where('current_workspace_id', $this->workspaceId)
            ->with('currentWorkspace')
            ->get()
            ->filter(fn (User $member): bool => Gate::forUser($member)->allows('viewAny', Email::class)
                && $conversations->contains($member, $this->conversationKey, new ConversationFilters))
            ->map(fn (User $member): PrivateChannel => new PrivateChannel("App.Models.User.{$member->getKey()}"))
            ->all());
    }
}
