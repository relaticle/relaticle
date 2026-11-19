<?php

declare(strict_types=1);

namespace Tests\Evals\Support\ClaudeCode;

use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use RuntimeException;

final class StreamJson
{
    public const string TOOL_PREFIX = 'mcp__rela__';

    public static function step(string ...$lines): StepResponse
    {
        $text = '';
        $reasoning = '';
        $toolCalls = [];
        $model = '';
        $usage = [];

        foreach ($lines as $line) {
            $event = self::decode($line);

            if (($event['type'] ?? '') === 'stream_event') {
                $model = $model === '' ? (string) ($event['event']['message']['model'] ?? '') : $model;
                $usage = array_replace($usage, array_filter((array) ($event['event']['usage'] ?? []), fn (mixed $value): bool => $value !== null && ! is_array($value)));

                continue;
            }

            $message = $event['message'] ?? [];
            $model = (string) ($message['model'] ?? $model);
            $usage = array_replace($usage, array_filter((array) ($message['usage'] ?? []), fn (mixed $value): bool => $value !== null && ! is_array($value)));

            foreach ($message['content'] ?? [] as $block) {
                match ($block['type'] ?? '') {
                    'text' => $text .= (string) ($block['text'] ?? ''),
                    'thinking' => $reasoning .= (string) ($block['thinking'] ?? ''),
                    'tool_use' => $toolCalls[] = new ToolCall(
                        (string) $block['id'],
                        str_starts_with((string) $block['name'], self::TOOL_PREFIX) ? substr((string) $block['name'], strlen(self::TOOL_PREFIX)) : (string) $block['name'],
                        is_array($block['input'] ?? null) ? $block['input'] : [],
                        (string) $block['id'],
                    ),
                    default => null,
                };
            }
        }

        if ($model === '') {
            throw new RuntimeException('Claude Code wrote a message with no model id.');
        }

        return new StepResponse(
            text: $text,
            toolCalls: $toolCalls,
            finishReason: $toolCalls === [] ? FinishReason::Stop : FinishReason::ToolCalls,
            usage: self::usageOf($usage) ?? new TextUsage,
            meta: new Meta('claude-code', $model),
            reasoning: $reasoning,
        );
    }

    public static function isEnd(string $line): bool
    {
        return (self::decode($line)['type'] ?? '') === 'result';
    }

    public static function isStreamEvent(string $line, string $type): bool
    {
        $event = self::decode($line);

        return ($event['type'] ?? '') === 'stream_event' && ($event['event']['type'] ?? '') === $type;
    }

    public static function isAssistant(string $line): bool
    {
        return (self::decode($line)['type'] ?? '') === 'assistant';
    }

    public static function messageId(string $line): ?string
    {
        $id = self::decode($line)['message']['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $line): array
    {
        $decoded = json_decode($line, true);

        return is_array($decoded) ? $decoded : throw new RuntimeException('Claude Code wrote a line that is not JSON: '.mb_substr($line, 0, 300));
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private static function usageOf(array $usage): ?TextUsage
    {
        if ($usage === []) {
            return null;
        }

        $read = $usage['cache_read_input_tokens'] ?? null;
        $write = $usage['cache_creation_input_tokens'] ?? null;

        return new TextUsage(
            inputTokens: (int) ($usage['input_tokens'] ?? 0) + (int) $read + (int) $write,
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            cacheReadInputTokens: $read === null ? null : (int) $read,
            cacheWriteInputTokens: $write === null ? null : (int) $write,
        );
    }
}
