<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

/**
 * The model-replay view of a tool result: the `display_block` key and the write echo.
 */
final readonly class DisplayBlocks
{
    private const string KEY = 'display_block';

    /**
     * Every block carried by one message's tool results.
     *
     * @param  list<array<string, mixed>>  $toolResults
     * @return list<array<string, mixed>>
     */
    public static function collect(array $toolResults): array
    {
        $blocks = [];

        foreach ($toolResults as $callIndex => $toolResult) {
            if (! isset($toolResult['result'])) {
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
     * The tool result as the model replays it; the original string when nothing is removed.
     */
    public static function strip(mixed $result): mixed
    {
        if (! is_string($result) || ! self::mayCarryStrippable($result)) {
            return $result;
        }

        $decoded = json_decode($result, true);

        if (! is_array($decoded)) {
            return $result;
        }

        $dropped = ($decoded['type'] ?? null) === PendingActionEnvelope::TYPE
            ? [self::KEY, ...PendingActionEnvelope::REPLAY_DROPPED_KEYS]
            : [self::KEY];
        $kept = array_diff_key($decoded, array_flip($dropped));

        if (count($kept) === count($decoded)) {
            return $result;
        }

        // The tools encode with this flag, so the replay keeps their unescaped slashes.
        return json_encode($kept, JSON_UNESCAPED_SLASHES);
    }

    private static function mayCarryStrippable(string $result): bool
    {
        return str_contains($result, self::KEY) || str_contains($result, '"'.PendingActionEnvelope::TYPE.'"');
    }
}
