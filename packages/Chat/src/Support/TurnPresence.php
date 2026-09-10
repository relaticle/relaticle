<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The in-flight turn marker: what a reload must still know about a turn that
 * has not persisted yet.
 *
 * Nothing about a running turn reaches the database until the stream's then()
 * callback writes the user and assistant rows together, so for the whole turn
 * (queue wait included) a reload would otherwise render the conversation as if
 * the send never happened: the user's own message gone, no streaming
 * indicator, proposals invisible until the next broadcast. ChatInterface reads
 * this at mount to restore that state.
 *
 * Written where the turn is dispatched (ChatController::send,
 * TurnContinuationService::resume) and cleared on every terminal path of
 * ProcessChatMessage. Retries, releases and the failover re-dispatch keep it:
 * the turn is still alive. The TTL is only the dead-worker backstop (a job
 * killed hard enough to skip failed()), sized past the job's worst case
 * (retryUntil + timeout), after which the client watchdog's timeout error has
 * long since told the user the turn is gone.
 */
final readonly class TurnPresence
{
    private const int TTL_SECONDS = 360;

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array{type: string, id: string, label: string}>  $mentions
     * @param  array{type: string, id: string, label: string}|null  $pageContext
     */
    public static function begin(
        string $conversationId,
        string $turnId,
        string $message,
        array $document = ['type' => 'doc', 'content' => []],
        array $mentions = [],
        ?array $pageContext = null,
        bool $isContinuation = false,
    ): void {
        Cache::put(self::key($conversationId), [
            'turn_id' => $turnId,
            'kind' => $isContinuation ? 'continuation' : 'message',
            // A continuation runs on a synthetic prompt the transcript never
            // shows, so nothing worth rendering is stored for it.
            'message' => $isContinuation ? '' : $message,
            'document' => $isContinuation ? ['type' => 'doc', 'content' => []] : $document,
            'mentions' => $isContinuation ? [] : $mentions,
            'page_context' => $isContinuation ? null : $pageContext,
            'started_at' => now()->toISOString(),
        ], self::TTL_SECONDS);
    }

    /**
     * Scoped to the clearing turn: a newer send overwrites the marker before
     * the older job ends (two tabs racing), and the older job's cleanup must
     * not blank a turn that is still waiting behind WithoutOverlapping.
     */
    public static function clear(string $conversationId, string $turnId): void
    {
        $current = self::current($conversationId);

        if ($current !== null && $current['turn_id'] !== $turnId) {
            return;
        }

        Cache::forget(self::key($conversationId));
    }

    /**
     * @return array{turn_id: string, kind: string, message: string, document: array<string, mixed>, mentions: list<array{type: string, id: string, label: string}>, page_context: array{type: string, id: string, label: string}|null, started_at: string}|null
     */
    public static function current(string $conversationId): ?array
    {
        /** @var array{turn_id: string, kind: string, message: string, document: array<string, mixed>, mentions: list<array{type: string, id: string, label: string}>, page_context: array{type: string, id: string, label: string}|null, started_at: string}|null $value */
        $value = Cache::get(self::key($conversationId));

        return is_array($value) ? $value : null;
    }

    private static function key(string $conversationId): string
    {
        return "chat:turn-inflight:{$conversationId}";
    }
}
