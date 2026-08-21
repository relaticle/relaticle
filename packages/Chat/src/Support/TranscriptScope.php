<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * The single definition of "messages this participant can see in this
 * conversation": the join onto the conversation plus every visibility
 * predicate the transcript applies.
 *
 * Two callers build on it and must stay identical. ListConversationMessages
 * pages the transcript; ChatController::searchMessages returns message ids
 * that the client then pages BACKWARDS to reach. A row search can see but the
 * pager cannot would send that load-until-found loop through the entire
 * history and end on "no longer part of this conversation", with nothing
 * failing anywhere to say why. Defined once so the two cannot drift.
 */
final readonly class TranscriptScope
{
    /**
     * Expects a query already rooted on `agent_conversation_messages as m`.
     * The `agent_conversations as c` join is added here, since the team
     * predicate below depends on it.
     */
    public static function apply(Builder $query, User $user, string $conversationId): Builder
    {
        return $query
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.conversation_id', $conversationId)
            ->where('m.participant_type', $user->getMorphClass())
            ->where('m.participant_id', $user->getKey())
            ->where('c.team_id', $user->current_team_id)
            ->whereNull('m.superseded_at')
            // Approval echoes are internal turn bookkeeping and are never
            // rendered. Excluded in SQL rather than after the fetch so a LIMIT
            // counts visible rows only: dropping one afterwards returned a
            // short page, and the pager reads a short page as "no more
            // history", stranding every older message behind it.
            ->whereNot(function (Builder $inner): void {
                $inner->where('m.role', 'user')->where('m.content', 'like', '[approval]%');
            });
    }
}
