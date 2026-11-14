<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Relaticle\EmailIntegration\Data\TriageOutcome;
use Relaticle\EmailIntegration\Models\EmailConversationState;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

final readonly class ReopenConversations
{
    public function __construct(private PrepareConversationTriage $prepare) {}

    /** @param  array<mixed>  $conversationKeys */
    public function execute(User $user, array $conversationKeys): TriageOutcome
    {
        $outcome = $this->prepare->execute($user, $conversationKeys);

        if ($outcome->appliedKeys === []) {
            return $outcome;
        }

        EmailConversationState::query()
            ->where('workspace_id', $user->current_workspace_id)
            ->where('user_id', $user->getKey())
            ->whereIn('conversation_key', $outcome->appliedKeys)
            ->delete();

        Cache::forget(ConversationsQuery::countsCacheKey($user));

        return $outcome;
    }
}
