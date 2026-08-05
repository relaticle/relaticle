<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Enums\SubscriberTagEnum;
use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tags the authenticated user's Mailcoach subscriber with "has-ai-usage" the
 * first time they send a chat message.
 *
 * Called from SupersededAwareConversationStore::storeUserMessage() rather than
 * an Eloquent observer: laravel/ai persists chat messages via a raw query
 * builder insert (see Laravel\Ai\Storage\DatabaseConversationStore), so the
 * Eloquent AgentConversationMessage model — a read-only shadow over that same
 * table — never fires model events for real chat traffic.
 */
final readonly class FirstChatUsageTagger
{
    public static function tagIfFirstMessage(string $currentMessageId): void
    {
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        /** @var User|null $user */
        $user = auth()->user();

        if (! $user instanceof User || ! $user->mailcoach_subscriber_uuid) {
            return;
        }

        $alreadyUsedChat = DB::table('agent_conversation_messages')
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', (string) $user->getKey())
            ->where('role', 'user')
            ->where('id', '!=', $currentMessageId)
            ->exists();

        if ($alreadyUsedChat) {
            return;
        }

        dispatch(new ModifySubscriberTagsJob(
            $user->mailcoach_subscriber_uuid,
            [SubscriberTagEnum::HasAiUsage->value],
            TagAction::Add,
        ))->afterCommit();
    }
}
