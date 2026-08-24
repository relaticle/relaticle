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
 *
 * Replayed tool results are NEVER rewritten to reflect a later decision. A
 * proposal result still says `pending_action` forever in the replayed
 * history. What actually happened to it travels entirely outside this store,
 * in two persistent, per-turn (uncached) system prompt blocks CrmAssistant
 * injects: `<resolved_actions>` for a proposal the user approved, rejected,
 * or let expire (see CrmAssistant::resolvedBlock(), fed by
 * PendingActionService::resolvedForConversation()), and
 * `<superseded_proposals>` for one auto-cancelled because the user moved on
 * without deciding it (a different status from the superseded_at message
 * rows this class filters above; see CrmAssistant::supersededBlock(), fed by
 * PendingActionService::supersededForConversation()). Both blocks query the
 * PendingAction table fresh on every turn rather than depending on what any
 * single turn transitioned, so a proposal decided or superseded many turns
 * ago stays visible for as long as its tool result is still being replayed.
 * These blocks live outside the cached prefix, so carrying them costs
 * nothing. Rewriting a tool result used to carry decided status instead, but
 * it mutated a message earlier in the transcript on every approval, and
 * Anthropic prompt caching keys on an exact prefix: one mutation invalidated
 * the cache for the rest of the conversation. Do not reintroduce stamping.
 */
final class SupersededAwareConversationStore extends DatabaseConversationStore
{
    /**
     * `meta->kind` marking the synthetic user message a resumed turn runs on
     * (see TurnContinuationService). The provider needs a final user turn, so
     * one is stored; the transcript hides it, because the user did not type it.
     */
    public const string CONTINUATION_KIND = 'continuation';

    /**
     * Set by ProcessChatMessage for the single user message a continuation turn
     * is about to store. Consumed on write, so a later turn in the same worker
     * process cannot inherit it.
     */
    public bool $nextUserMessageIsContinuation = false;

    /**
     * Drop presentation-only display blocks from the history replayed to the model.
     *
     * Read tools persist a `display_block` envelope next to their model-facing
     * payload so the UI can render a real table on reload. Tool results are
     * replayed on every later turn, so leaving the block in would re-bill 1-2 KB
     * per read call for the rest of the conversation, against a prompt prefix we
     * fought to shrink. The persisted row keeps it; only the replay drops it.
     *
     * Stripping is safe to keep (unlike stamping, see class docblock) because
     * it is deterministic: the same stored result always strips to the same
     * output, so it never invalidates the prompt cache.
     *
     * This post-processes the parent's output rather than rebuilding it, so the
     * ownership note below still holds.
     *
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $messages = parent::getLatestConversationMessages($conversationId, $limit);

        return $messages->each(function (Message $message): void {
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

        if ($this->nextUserMessageIsContinuation) {
            $this->nextUserMessageIsContinuation = false;

            $this->table($this->messagesTable())
                ->where('id', $messageId)
                ->update(['meta' => json_encode(['kind' => self::CONTINUATION_KIND], JSON_THROW_ON_ERROR)]);

            return $messageId;
        }

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
