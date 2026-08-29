<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Illuminate\Support\Facades\Http;

/**
 * Canned Anthropic SSE transports, so the real streaming pipeline (agent,
 * gateway, ProcessChatMessage::handle()) runs for real and only the network
 * response is faked.
 *
 * One home for the wire format: the frame shapes, the `text/event-stream`
 * header and the model id used to be restated in every test that needed a
 * stream to fail, so a laravel/ai parser change meant hunting all of them.
 * `data:` lines are all `parseServerSentEvents()` reads; `event:` lines are
 * ignored by the parser, so they are omitted here.
 */
final class AnthropicSse
{
    private const string ERROR = "data: {\"type\":\"error\",\"error\":{\"type\":\"invalid_request_error\",\"message\":\"bad request\"}}\n\n";

    private const string MESSAGE_START = "data: {\"type\":\"message_start\",\"message\":{\"model\":\"claude-sonnet-4-6\",\"usage\":{\"input_tokens\":5}}}\n\n";

    /** The provider rejects the turn before any event reaches the consumer. */
    public const string TERMINAL_ERROR = self::ERROR;

    /** The turn starts, so hasYielded() is true, and then dies. */
    public const string STREAM_STARTED_THEN_ERROR = self::MESSAGE_START.self::ERROR;

    /** The turn emits visible text and then dies: the provider has billed us. */
    public static function streamedThenError(string $text): string
    {
        return self::MESSAGE_START
            .'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":'.json_encode($text, JSON_THROW_ON_ERROR)."}}\n\n"
            .self::ERROR;
    }

    public static function fake(string $body): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
        ]);
    }
}
