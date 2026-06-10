<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Models\PendingAction;

final readonly class DeleteConversation
{
    public function execute(User $user, string $conversationId): bool
    {
        return DB::transaction(function () use ($user, $conversationId): bool {
            $deleted = DB::table('agent_conversations')
                ->where('id', $conversationId)
                ->where('user_id', $user->getKey())
                ->where('team_id', $user->current_team_id)
                ->delete();

            if ($deleted === 0) {
                return false;
            }

            DB::table('agent_conversation_messages')
                ->where('conversation_id', $conversationId)
                ->delete();

            PendingAction::query()
                ->where('conversation_id', $conversationId)
                ->delete();

            return true;
        });
    }
}
