<?php

declare(strict_types=1);

namespace Relaticle\Chat\Storage;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Relaticle\Chat\Support\AssistantText;
use Relaticle\Chat\Support\DisplayBlocks;
use Relaticle\Chat\Support\FirstChatUsageTagger;

/**
 * Conversation store that hides superseded turns from the agent's history.
 *
 * Regenerate/edit mark replaced turns with superseded_at (see
 * ChatController::supersedeMessages). Without this filter the model keeps
 * "remembering" turns the user replaced — answering "I already proposed that"
 * against a transcript the user can no longer see.
 */
final class SupersededAwareConversationStore extends DatabaseConversationStore
{
    /**
     * Drop presentation-only display blocks from the history replayed to the model.
     *
     * Read tools persist a `display_block` envelope next to their model-facing
     * payload so the UI can render a real table on reload. Tool results are
     * replayed on every later turn, so leaving the block in would re-bill 1-2 KB
     * per read call for the rest of the conversation, against a prompt prefix we
     * fought to shrink. The persisted row keeps it; only the replay drops it.
     *
     * This post-processes the parent's output rather than rebuilding it, so the
     * ownership note below still holds.
     *
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return parent::getLatestConversationMessages($conversationId, $limit)
            ->each(function (Message $message): void {
                if (! $message instanceof ToolResultMessage) {
                    return;
                }

                foreach ($message->toolResults as $toolResult) {
                    $toolResult->result = DisplayBlocks::strip($toolResult->result);
                }
            });
    }

    /**
     * Scope every message query the store makes to the non-superseded rows.
     *
     * The filter lives here rather than in a getLatestConversationMessages()
     * override so that history rebuilding — attachment rehydration, paused
     * tool-turn reconstruction, approval-result bookkeeping — stays owned by
     * the parent and cannot drift from it.
     *
     * Inserts are unaffected (insert() ignores wheres), but the approval-result
     * update in DatabaseConversationStore::storeApprovalResults() does go
     * through here, so a superseded turn cannot have approvals written back to
     * it — deliberate, and consistent with its lookup query being filtered too.
     */
    protected function table(string $table): Builder
    {
        $builder = parent::table($table);

        if ($table === $this->messagesTable()) {
            $builder->whereNull('superseded_at');
        }

        return $builder;
    }

    /**
     * Tag the "has-ai-usage" marketing segment on the subscriber's first
     * chat message. This is the real write path for user turns (see
     * RememberConversation middleware in laravel/ai), unlike the
     * AgentConversationMessage Eloquent model, which never receives writes.
     */
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
    {
        $messageId = parent::storeUserMessage($conversationId, $participantType, $participantId, $prompt);

        FirstChatUsageTagger::tagIfFirstMessage($messageId);

        return $messageId;
    }

    /**
     * Collapse a fully-repeated combined assistant text before persisting.
     *
     * laravel/ai concatenates the model's text deltas across every agent step,
     * so a model that echoes the same acknowledgment in both the tool-call step
     * and the post-tool-result step yields that text repeated back-to-back. We
     * store the single copy instead of the duplicate.
     */
    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string
    {
        $response->text = AssistantText::collapseRepeated($response->text);

        return parent::storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response);
    }
}
