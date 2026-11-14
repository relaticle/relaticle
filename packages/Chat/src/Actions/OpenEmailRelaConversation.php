<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Illuminate\Support\Str;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;

final readonly class OpenEmailRelaConversation
{
    public function execute(User $user, string $conversationKey): AgentConversation
    {
        abort_if(resolve(ConversationsQuery::class)->messages($user, $conversationKey)->isEmpty(), 404);

        $conversation = AgentConversation::query()->firstOrCreate([
            'participant_type' => $user->getMorphClass(), 'participant_id' => $user->getKey(),
            'workspace_id' => $user->current_workspace_id, 'purpose' => AgentConversation::PURPOSE_EMAIL,
            'email_conversation_key' => $conversationKey,
        ], ['id' => (string) Str::uuid7(), 'title' => __('Email review')]);
        if ($conversation->email_input_hash !== null && $conversation->email_input_hash !== hash('sha256', resolve(EmailThreadSummaryService::class)->prompt($user, $conversationKey))) {
            resolve(PendingActionService::class)->supersedePendingForConversation($conversation->id);
        }

        return $conversation;
    }
}
