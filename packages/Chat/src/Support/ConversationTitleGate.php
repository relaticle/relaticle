<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Models\AgentConversationMessage;

/**
 * The single definition of "this conversation may still be auto-titled".
 *
 * Titling is attempted from more than one place, when a message is sent, and
 * again when that turn ends without having produced a title, and both must
 * agree on two things or they will fight each other: which stored title a
 * generated one is allowed to replace, and when to stop trying.
 *
 * The returned provisional is the conversation's opening message, sanitized,
 * which is exactly what ChatController stored at creation. Every write of a
 * generated title is a compare-and-swap against that value, so a title the
 * user typed is never overwritten and the first generated title wins over any
 * later one.
 */
final readonly class ConversationTitleGate
{
    /**
     * How many of a conversation's opening user messages may trigger a titling
     * attempt. More than one because an opener like "hey" carries no topic to
     * name, the titler declines it and the next message gets a turn.
     */
    private const int ATTEMPT_TURNS = 3;

    /**
     * Called before the incoming message is stored, so the message itself is
     * passed in and counts as one of the attempts.
     */
    public static function beforeTurn(string $conversationId, string $incomingMessage): ?string
    {
        return self::provisional($conversationId, null, $incomingMessage, self::ATTEMPT_TURNS - 1);
    }

    /**
     * Called once the turn has ended, by which point the agent's store has
     * persisted the message this turn ran on, so one more stored message is
     * allowed here than in beforeTurn() for the same conversation.
     *
     * Returns the latest typed message alongside the provisional, because the
     * turn that just ended did not necessarily run on something the user typed:
     * a turn resumed by a proposal decision runs on a synthetic prompt. Reading
     * the message back from the transcript rather than from the job keeps the
     * titler on the user's own words in both cases.
     *
     * @return array{provisional: string, latest: string}|null
     */
    public static function afterTurn(string $conversationId): ?array
    {
        $typed = self::typedMessages($conversationId);

        $provisional = self::provisional($conversationId, $typed, null, self::ATTEMPT_TURNS);
        $latest = $typed->last();

        if ($provisional === null || ! is_string($latest) || trim($latest) === '') {
            return null;
        }

        return ['provisional' => $provisional, 'latest' => $latest];
    }

    /**
     * @param  Collection<int, non-empty-string>|null  $typed
     */
    private static function provisional(string $conversationId, ?Collection $typed, ?string $fallbackMessage, int $maxTypedMessages): ?string
    {
        $title = DB::table('agent_conversations')
            ->where('id', $conversationId)
            ->value('title');

        if (! is_string($title)) {
            return null;
        }

        $typed ??= self::typedMessages($conversationId);

        if ($typed->count() > $maxTypedMessages) {
            return null;
        }

        $opener = $typed->first() ?? $fallbackMessage;

        if (! is_string($opener) || trim($opener) === '') {
            return null;
        }

        $provisional = TitleSanitizer::clean($opener);

        return $provisional !== '' && $title === $provisional ? $provisional : null;
    }

    /**
     * The user messages a person actually typed, oldest first.
     *
     * Some rows stored with `role = user` were never typed by anyone: the
     * setup greeting and the prompt a turn resumed by an approval decision runs
     * on. Counting those against the attempt window burns it on a conversation
     * where the user said one thing and then clicked approve twice, leaving the
     * chat stuck under its opening message forever. Worse, the newest of them
     * would become the text handed to the titler.
     *
     * Superseded rows are deliberately still counted. Editing the opening
     * message supersedes it but leaves the stored title on the original text,
     * so skipping superseded rows would make the opener look like the edit and
     * the compare-and-swap would never match again.
     *
     * A row carrying an attachment stores the composed prompt (typed text plus
     * the fenced CSV block) as its content, so the block is stripped back off
     * here. An attachment sent with no typed text has nothing to name the
     * conversation from and is dropped rather than counted as an empty opener.
     *
     * @return Collection<int, non-empty-string>
     */
    private static function typedMessages(string $conversationId): Collection
    {
        return AgentConversationMessage::query()
            ->typed()
            ->where('conversation_id', $conversationId)
            ->orderBy('id')
            ->toBase()
            ->get(['content', 'meta'])
            ->map(function (object $row): string {
                $meta = $row->meta === null ? null : json_decode((string) $row->meta, true);
                $content = (string) $row->content;

                return is_array($meta) && isset($meta['attachment']) ? AttachedRows::typedText($content) : $content;
            })
            ->reject(fn (string $content): bool => trim($content) === '')
            ->values();
    }
}
