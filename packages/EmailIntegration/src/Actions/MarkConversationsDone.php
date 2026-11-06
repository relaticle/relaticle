<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Data\TriageOutcome;
use Relaticle\EmailIntegration\Models\EmailConversationState;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

final readonly class MarkConversationsDone
{
    public function __construct(private PrepareConversationTriage $prepare) {}

    /** @param  array<mixed>  $conversationKeys */
    public function execute(User $user, array $conversationKeys): TriageOutcome
    {
        $outcome = $this->prepare->execute($user, $conversationKeys);

        if ($outcome->appliedKeys === []) {
            return $outcome;
        }

        $now = now();

        EmailConversationState::query()->upsert(array_map(fn (string $key): array => [
            'id' => (string) Str::ulid(),
            'workspace_id' => $user->current_workspace_id,
            'user_id' => $user->getKey(),
            'conversation_key' => $key,
            'done_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $outcome->appliedKeys), ['workspace_id', 'user_id', 'conversation_key'], ['done_at', 'updated_at']);

        Cache::forget(ConversationsQuery::countsCacheKey($user));

        return $outcome;
    }
}
