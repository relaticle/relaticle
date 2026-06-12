<?php

declare(strict_types=1);

namespace App\Ai\Anthropic;

use Laravel\Ai\Gateway\Anthropic\AnthropicGateway as BaseAnthropicGateway;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;

final class AnthropicGateway extends BaseAnthropicGateway
{
    /**
     * Anthropic's Messages API rejects `tool_use.input` when it is a JSON array.
     * Empty tool arguments round-tripped through PHP arrays serialise as `[]`
     * instead of `{}`, which trips a 400. Coerce empty arguments to an object
     * before the request body is built.
     *
     * Upstream bug: empty `ToolCall::$arguments` (typed `array`) cannot
     * preserve the original JSON object semantic across persistence.
     *
     * @param  array<int, array<string, mixed>>  $mapped
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$mapped): void
    {
        // @phpstan-ignore parameterByRef.type (vendor signature has no shape docblock)
        parent::mapAssistantMessage($message, $mapped);
        /** @var array<int, array<string, mixed>> $mapped */
        $lastIndex = array_key_last($mapped);

        if ($lastIndex === null || ($mapped[$lastIndex]['role'] ?? null) !== 'assistant') {
            return;
        }

        $mapped[$lastIndex]['content'] = array_map(
            static function (array $block): array {
                if (($block['type'] ?? null) === 'tool_use') {
                    $input = $block['input'] ?? [];
                    $block['input'] = is_array($input) && $input === [] ? (object) [] : $input;
                }

                return $block;
            },
            $mapped[$lastIndex]['content'],
        );
    }
}
