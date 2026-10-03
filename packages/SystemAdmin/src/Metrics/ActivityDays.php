<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Task;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Models\AgentConversationMessage;

final readonly class ActivityDays
{
    private const array RECORD_MODELS = [Company::class, People::class, Opportunity::class, Task::class, Note::class];

    public static function query(): QueryBuilder
    {
        $union = AgentConversationMessage::query()
            ->typed()
            ->join('agent_conversations', 'agent_conversations.id', '=', 'agent_conversation_messages.conversation_id')
            ->whereNotNull('agent_conversations.workspace_id')
            ->toBase()
            ->selectRaw("agent_conversation_messages.participant_id::text as user_id, agent_conversations.workspace_id as workspace_id, agent_conversation_messages.created_at::date as day, 'message' as kind, 'chat_message' as source");

        foreach (self::RECORD_MODELS as $model) {
            $union->unionAll(
                $model::query()
                    ->withoutGlobalScope(WorkspaceScope::class)
                    ->ownData()
                    ->toBase()
                    ->selectRaw("creator_id::text as user_id, workspace_id as workspace_id, created_at::date as day, 'record' as kind, creation_source::text as source"),
            );
        }

        return $union;
    }

    public static function from(): QueryBuilder
    {
        return DB::query()->fromSub(self::query(), 'activity');
    }

    public static function workspacesWithOwnData(): QueryBuilder
    {
        return self::from()->where('activity.kind', 'record')->select('activity.workspace_id');
    }
}
