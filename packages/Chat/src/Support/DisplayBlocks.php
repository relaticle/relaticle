<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

/**
 * The `display_block` key on a read-tool result, in one place.
 *
 * A block is presentation, not reasoning. It travels to the UI two ways
 * (`ListConversationMessages` on reload, `ChatInterface::latestAssistantMessage()`
 * at stream-end reconcile) and travels back to the model zero ways: the store
 * strips it from the replayed history, because tool results are replayed on
 * every later turn and a block re-read is pure token cost forever.
 */
final readonly class DisplayBlocks
{
    private const string KEY = 'display_block';

    /**
     * Every block carried by one persisted `tool_results` column.
     *
     * @return list<array<string, mixed>>
     */
    public static function collect(?string $toolResults): array
    {
        if ($toolResults === null) {
            return [];
        }

        $parsed = json_decode($toolResults, true);

        if (! is_array($parsed)) {
            return [];
        }

        $blocks = [];

        foreach (array_values($parsed) as $callIndex => $toolResult) {
            if (! is_array($toolResult) || ! isset($toolResult['result'])) {
                continue;
            }

            $inner = json_decode((string) $toolResult['result'], true);

            if (is_array($inner) && is_array($inner[self::KEY] ?? null)) {
                // Marker numbering includes tool calls without display blocks.
                $blocks[] = [...$inner[self::KEY], 'tool_call_order' => $callIndex + 1];
            }
        }

        return $blocks;
    }

    /**
     * The same tool result with its block removed. Anything that is not a JSON
     * object carrying a block is returned untouched, so a plain-string result
     * never round-trips through json_encode.
     */
    public static function strip(mixed $result): mixed
    {
        if (! is_string($result) || ! str_contains($result, self::KEY)) {
            return $result;
        }

        $decoded = json_decode($result, true);

        if (! is_array($decoded) || ! array_key_exists(self::KEY, $decoded)) {
            return $result;
        }

        unset($decoded[self::KEY]);

        return json_encode($decoded);
    }
}
