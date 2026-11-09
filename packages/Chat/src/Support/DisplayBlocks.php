<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

/**
 * The model-replay view of a tool result: the `display_block` key and the write echo.
 */
final readonly class DisplayBlocks
{
    private const string KEY = 'display_block';

    private const string PENDING_ACTION = 'pending_action';

    private const array WRITE_ECHO_KEYS = ['data', 'display'];

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

        $keys = array_key_exists(self::KEY, $decoded) ? [self::KEY] : [];

        if (($decoded['type'] ?? null) === self::PENDING_ACTION) {
            $keys = [...$keys, ...array_filter(self::WRITE_ECHO_KEYS, fn (string $key): bool => array_key_exists($key, $decoded))];
        }

        if ($keys === []) {
            return $result;
        }

        // Same flag the tools encode with.
        return json_encode(array_diff_key($decoded, array_flip($keys)), JSON_UNESCAPED_SLASHES);
    }

    private static function mayCarryStrippable(string $result): bool
    {
        return str_contains($result, self::KEY) || str_contains($result, '"'.self::PENDING_ACTION.'"');
    }
}
