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
use Relaticle\Chat\Agents\NextStepSuggester;
use Relaticle\Chat\Events\NextStepsSuggested;
use Relaticle\Chat\Support\ChatTelemetry;
use Throwable;

/**
 * Draft the next steps for the turn that just ended, persist them on the
 * assistant message, and push them to the open page.
 *
 * On the default queue for the same reason GenerateConversationTitle is: the
 * `chat` queue's workers are busy streaming turns for up to two minutes, and
 * suggestions that land a minute after the answer are suggestions nobody sees.
 *
 * Failure is never fatal. The transcript without a suggestion strip is the
 * ordinary state of the UI, so anything that goes wrong here simply leaves the
 * page as it already was.
 */
#[Timeout(30)]
#[MaxExceptions(1)]
final class SuggestNextSteps implements ShouldQueue
{
    use Queueable;

    private const int MAX_MESSAGE_CHARS = 500;

    private const int MAX_REPLY_CHARS = 1500;

    private const int MAX_STEPS = 3;

    private const int MAX_LABEL_CHARS = 48;

    private const int MAX_PROMPT_CHARS = 160;

    /**
     * @param  list<string>  $toolNames
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly string $messageId,
        public readonly string $message,
        public readonly string $reply,
        private readonly ?string $provider = null,
        private readonly array $toolNames = [],
    ) {
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        if (! (bool) config('chat.next_steps.enabled', true)) {
            return;
        }

        $steps = $this->generate();

        if ($steps === []) {
            return;
        }

        // The message row is the durable home: a reload reads them back through
        // ListConversationMessages, so the strip survives a refresh exactly as
        // the transcript above it does.
        if (! $this->persist($steps)) {
            return;
        }

        // Already persisted; a Reverb hiccup must not fail the job over it. The
        // client's turn-end reconcile pulls what a dropped broadcast missed.
        try {
            broadcast(new NextStepsSuggested(
                conversationId: $this->conversationId,
                steps: $steps,
            ));
        } catch (Throwable $e) {
            ChatTelemetry::breadcrumb('broadcast.dropped', [
                'event' => NextStepsSuggested::class,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<array{label: string, prompt: string}>
     */
    private function generate(): array
    {
        try {
            $response = (new NextStepSuggester)->prompt(
                $this->buildPrompt(),
                provider: $this->provider,
            );

            if (! $response instanceof StructuredAgentResponse) {
                return [];
            }

            return $this->sanitize($response->structured['suggestions'] ?? null);
        } catch (Throwable $e) {
            ChatTelemetry::breadcrumb('next_steps.failed', [
                'conversation_id' => $this->conversationId,
                'reason' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * The model is told the shape but is not bound by it: an over-long label
     * would stretch the strip, a blank one would render an invisible button,
     * and three rewordings of one idea would fill the strip with a single
     * suggestion. Everything the UI relies on is enforced here rather than
     * trusted.
     *
     * @return list<array{label: string, prompt: string}>
     */
    private function sanitize(mixed $suggestions): array
    {
        if (! is_array($suggestions)) {
            return [];
        }

        $steps = [];
        $seen = [];

        foreach ($suggestions as $suggestion) {
            if (! is_array($suggestion)) {
                continue;
            }

            $label = is_string($suggestion['label'] ?? null) ? trim($suggestion['label']) : '';
            $prompt = is_string($suggestion['prompt'] ?? null) ? trim($suggestion['prompt']) : '';

            if ($label === '' || $prompt === '') {
                continue;
            }

            $key = Str::lower($prompt);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $steps[] = [
                'label' => Str::limit($label, self::MAX_LABEL_CHARS, ''),
                'prompt' => Str::limit($prompt, self::MAX_PROMPT_CHARS, ''),
            ];

            if (count($steps) === self::MAX_STEPS) {
                break;
            }
        }

        return $steps;
    }

    /**
     * @param  list<array{label: string, prompt: string}>  $steps
     */
    private function persist(array $steps): bool
    {
        $row = DB::table('agent_conversation_messages')
            ->where('id', $this->messageId)
            ->first(['meta']);

        // Regenerating or editing a message deletes the row this job was
        // dispatched for. Writing the steps to whatever assistant row is latest
        // instead would attach them to a different answer.
        if ($row === null) {
            return false;
        }

        $existing = json_decode((string) $row->meta, associative: true);
        $meta = is_array($existing) ? $existing : [];
        $meta['next_steps'] = $steps;

        DB::table('agent_conversation_messages')
            ->where('id', $this->messageId)
            ->update(['meta' => json_encode($meta, JSON_THROW_ON_ERROR)]);

        return true;
    }

    private function buildPrompt(): string
    {
        $prompt = '<reply>'.Str::limit($this->reply, self::MAX_REPLY_CHARS).'</reply>';

        if (trim($this->message) !== '') {
            $prompt .= "\n<message>".Str::limit($this->message, self::MAX_MESSAGE_CHARS).'</message>';
        }

        if ($this->toolNames !== []) {
            $prompt .= "\n<tools>".implode(', ', array_unique($this->toolNames)).'</tools>';
        }

        return $prompt;
    }
}
