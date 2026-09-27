<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Laravel\Ai\Storage\StoredMessage;

final readonly class StoredSteps
{
    /** @return list<array<string, mixed>> */
    public static function toolResults(mixed $steps): array
    {
        return StoredMessage::fromArray(['steps' => $steps])->toolResults();
    }

    public static function text(string $content): string
    {
        return json_encode([[
            'content' => $content,
            'tool_calls' => [],
            'reasoning' => '',
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]], JSON_THROW_ON_ERROR);
    }
}
