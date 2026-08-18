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
 * Failure is never fatal: the provisional title is already a usable label, so
 * anything that goes wrong leaves the conversation exactly as it was.
 */
#[Timeout(30)]
#[MaxExceptions(1)]
final class GenerateConversationTitle implements ShouldQueue
{
    use Queueable;

    private const int MAX_PROMPT_CHARS = 500;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $provisionalTitle,
        public readonly string $message,
        private readonly ?string $provider,
    ) {
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        if (! (bool) config('chat.title_generation.enabled', true)) {
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

        broadcast(new ConversationTitleGenerated(
            conversationId: $this->conversationId,
            title: $title,
        ));
    }

    private function generate(): ?string
    {
        try {
            $response = (new ConversationTitler)->prompt(
                Str::limit($this->message, self::MAX_PROMPT_CHARS),
                provider: $this->provider,
            );

            if (! $response instanceof StructuredAgentResponse) {
                return null;
            }

            $title = $response->structured['title'] ?? null;

            return is_string($title) ? TitleSanitizer::generated($title) : null;
        } catch (Throwable $e) {
            ChatTelemetry::breadcrumb('title.generation_failed', ['exception' => $e->getMessage()]);

            return null;
        }
    }
}
