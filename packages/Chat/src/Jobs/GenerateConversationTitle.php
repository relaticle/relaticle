<?php

declare(strict_types=1);

namespace Relaticle\Chat\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Relaticle\Chat\Agents\ConversationTitler;
use Relaticle\Chat\Events\ConversationTitleGenerated;
use Relaticle\Chat\Support\ChatTelemetry;
use Relaticle\Chat\Support\TitleSanitizer;
use Throwable;

/**
 * Replace a new conversation's provisional title -- the raw first message,
 * truncated -- with a short model-written one.
 *
 * Deliberately NOT on the `chat` queue: that queue's workers are occupied by
 * streaming turns for up to two minutes, and a title that lands after the
 * answer is pointless. On the default queue it resolves alongside the stream,
 * which is the whole point of titling from the first message rather than
 * waiting for the reply.
 *
 * Dispatched twice per conversation at most, and it writes at most once. The
 * first dispatch races the turn on the message alone; the second runs only if
 * that one produced nothing, and adds the assistant's reply as context (see
 * ProcessChatMessage::maybeTitleFromTurn). Whichever gets there first wins the
 * compare-and-swap; the other reads a title that is no longer the provisional
 * one and stops, so the user never watches the title rewrite itself.
 *
 * Failure is never fatal: the provisional title is already a usable label, so
 * anything that goes wrong leaves the conversation exactly as it was.
 */
#[Timeout(30)]
#[MaxExceptions(1)]
final class GenerateConversationTitle implements ShouldQueue
{
    use Queueable;

    private const int MAX_PROMPT_CHARS = 500;

    private const int MAX_REPLY_CHARS = 400;

    /**
     * @param  array{type: string, id: string, label: string}|null  $pageContext
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly string $provisionalTitle,
        public readonly string $message,
        private readonly ?string $provider,
        public readonly ?array $pageContext = null,
        public readonly ?string $reply = null,
    ) {
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        if (! (bool) config('chat.title_generation.enabled', true)) {
            return;
        }

        // Checked before the model is called as well as after (the write below
        // is a compare-and-swap on the same value). The CAS alone is enough for
        // correctness; this only avoids paying for a title that could no longer
        // be applied -- the common case for the turn-end dispatch, which fires
        // on conversations the first attempt has usually already named.
        if (! $this->stillProvisional()) {
            ChatTelemetry::breadcrumb('title.superseded', ['conversation_id' => $this->conversationId]);

            return;
        }

        $title = $this->generate();

        if ($title === null || $title === '') {
            return;
        }

        // Compare-and-swap: a rename typed while the model was thinking is the
        // user's explicit choice and must not be overwritten by a guess.
        $applied = DB::table('agent_conversations')
            ->where('id', $this->conversationId)
            ->where('title', $this->provisionalTitle)
            ->update(['title' => $title, 'updated_at' => now()]);

        if ($applied === 0) {
            ChatTelemetry::breadcrumb('title.superseded', ['conversation_id' => $this->conversationId]);

            return;
        }

        // The title is already applied; a Reverb hiccup must not fail the job
        // over it. The client's turn-end pull picks up a dropped broadcast.
        try {
            broadcast(new ConversationTitleGenerated(
                conversationId: $this->conversationId,
                title: $title,
            ));
        } catch (Throwable $e) {
            ChatTelemetry::breadcrumb('broadcast.dropped', [
                'event' => ConversationTitleGenerated::class,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function stillProvisional(): bool
    {
        return DB::table('agent_conversations')
            ->where('id', $this->conversationId)
            ->where('title', $this->provisionalTitle)
            ->exists();
    }

    private function generate(): ?string
    {
        try {
            $response = (new ConversationTitler)->prompt(
                $this->buildPrompt(),
                provider: $this->provider,
            );

            if (! $response instanceof StructuredAgentResponse) {
                return null;
            }

            // A greeting or a stray character has nothing to name. Inventing a
            // label for it ("Single Character Message") reads worse than the
            // message itself, so keep the provisional and let a later, more
            // substantive message in this conversation try instead.
            if ($response->structured['has_topic'] !== true) {
                ChatTelemetry::breadcrumb('title.no_topic', ['conversation_id' => $this->conversationId]);

                return null;
            }

            $title = $response->structured['title'] ?? null;

            return is_string($title) ? TitleSanitizer::generated($title) : null;
        } catch (Throwable $e) {
            ChatTelemetry::breadcrumb('title.generation_failed', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The message, plus whatever context this dispatch was given.
     *
     * A mention chip's label is already inlined into the message text by
     * TipTapDocumentParser, so mentions need no block of their own. The record
     * the user was VIEWING is not -- without it "add a note here" names nothing
     * and the titler rightly declines.
     */
    private function buildPrompt(): string
    {
        $blocks = ['<message>'.Str::limit($this->message, self::MAX_PROMPT_CHARS).'</message>'];

        if ($this->pageContext !== null && $this->pageContext['label'] !== '') {
            $blocks[] = '<viewing>'.$this->pageContext['label'].' ('.$this->pageContext['type'].')</viewing>';
        }

        if ($this->reply !== null && trim($this->reply) !== '') {
            $blocks[] = '<reply>'.Str::limit(trim($this->reply), self::MAX_REPLY_CHARS).'</reply>';
        }

        return implode("\n\n", $blocks);
    }
}
