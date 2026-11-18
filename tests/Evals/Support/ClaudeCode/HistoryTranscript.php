<?php

declare(strict_types=1);

namespace Tests\Evals\Support\ClaudeCode;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

final class HistoryTranscript
{
    /**
     * @param  array<int, Message>  $messages
     */
    public static function render(array $messages): string
    {
        $lines = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $lines = [...$lines, ...$message->toolResults->map(fn (ToolResult $result): string => 'Tool result: '.$result->text())->all()];
            } elseif ($message instanceof AssistantMessage) {
                $lines = [...$lines, ...self::assistantLines($message)];
            } elseif ($message->role->value === 'user') {
                $lines[] = 'User: '.$message->content;
            }
        }

        return $lines === [] ? '' : "Earlier in this conversation:\n```\n".implode("\n", $lines)."\n```";
    }

    /**
     * @return list<string>
     */
    private static function assistantLines(AssistantMessage $message): array
    {
        $lines = [];

        if (trim((string) $message->content) !== '') {
            $lines[] = 'Rela: '.$message->content;
        }

        foreach ($message->toolCalls as $call) {
            $lines[] = self::callLine($call);
        }

        return $lines;
    }

    private static function callLine(ToolCall $call): string
    {
        return "Rela called {$call->name}(".json_encode($call->arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).')';
    }
}
