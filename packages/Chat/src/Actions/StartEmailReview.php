<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Support\TurnPresence;
use Relaticle\EmailIntegration\Queries\ConversationFactsQuery;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;
use Throwable;

final readonly class StartEmailReview
{
    public function execute(User $user, string $key): ?AgentConversation
    {
        $conversation = resolve(OpenEmailRelaConversation::class)->execute($user, $key);

        return Cache::lock('email-review:'.$conversation->id, 5)->block(2, function () use ($user, $key, $conversation): ?AgentConversation {
            if (TurnPresence::current($conversation->id) !== null) {
                return $conversation;
            }
            $turnId = (string) Str::ulid();
            $workspace = $user->currentWorkspace;
            abort_unless($workspace !== null, 403);
            if (! resolve(CreditService::class)->reserveCredit($workspace, "reserve-{$turnId}", $conversation->id, $user->getKey())) {
                return null;
            }
            $messages = resolve(ConversationsQuery::class)->messages($user, $key);
            $blocker = resolve(ConversationFactsQuery::class)->replyDraftBlocker($user, $key);
            $message = 'Review these email IDs: '.implode(', ', $messages->modelKeys()).'. Reply draft rule: '.($blocker ?? 'allowed').'.';
            $conversation->update(['email_input_hash' => hash('sha256', resolve(EmailThreadSummaryService::class)->prompt($user, $key))]);
            resolve(PendingActionService::class)->supersedePendingForConversation($conversation->id);
            TurnPresence::begin($conversation->id, $turnId, $message, origin: MessageOrigin::EmailReview);
            try {
                resolve(CurrentWorkspace::class)->within($workspace, function () use ($user, $workspace, $conversation, $message, $turnId): void {
                    dispatch(new ProcessChatMessage($user, $workspace, $message, $conversation->id, resolve(AiModelResolver::class)->resolve($user), turnId: $turnId, origin: MessageOrigin::EmailReview));
                });
            } catch (Throwable $exception) {
                resolve(CreditService::class)->refundReservation($workspace, resolutionKey: "resolve-{$turnId}", conversationId: $conversation->id);
                TurnPresence::clear($conversation->id, $turnId);
                throw $exception;
            }

            return $conversation;
        });
    }
}
