<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Jobs\Email\SyncSubscriberJob;
use App\Models\User;
use Relaticle\Chat\Models\AgentConversationMessage;

/**
 * Syncs the authenticated user's Mailcoach profile the first time they send a
 * chat message, so has-ai-usage lands immediately instead of waiting for the
 * nightly reconcile sweep.
 *
 * Called from SupersededAwareConversationStore::storeUserMessage() rather than
 * an Eloquent observer: laravel/ai persists chat messages via a raw query
 * builder insert (see Laravel\Ai\Storage\DatabaseConversationStore), so the
 * Eloquent AgentConversationMessage model (a read-only shadow over that same
 * table) never fires model events for real chat traffic.
 */
final readonly class FirstChatUsageTagger
{
    public static function tagIfFirstMessage(string $currentMessageId): void
    {
        // Guarded here too so the request path skips the exists-probe below.
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            return;
        }

        /** @var User|null $user */
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $alreadyUsedChat = AgentConversationMessage::query()
            ->sentBy($user)
            ->whereKeyNot($currentMessageId)
            ->exists();

        if ($alreadyUsedChat) {
            return;
        }

        SyncSubscriberJob::dispatchFor((string) $user->id);
    }
}
