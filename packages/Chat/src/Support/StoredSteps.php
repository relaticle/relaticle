<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

final readonly class StoredSteps
{
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
