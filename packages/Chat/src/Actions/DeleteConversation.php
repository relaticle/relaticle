<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\PendingAction;

final readonly class DeleteConversation
{
    public function execute(User $user, string $conversationId): bool
    {
        return DB::transaction(function () use ($user, $conversationId): bool {
            $conversation = AgentConversation::query()->ownedBy($user)->find($conversationId);

            if (! $conversation instanceof AgentConversation) {
                return false;
            }

            DB::table('agent_conversation_messages')
                ->where('conversation_id', $conversationId)
                ->delete();

            PendingAction::query()
                ->where('conversation_id', $conversationId)
                ->delete();

            $conversation->delete();

            return true;
        });
    }
}
