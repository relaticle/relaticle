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

    /** A completed text reply from the given model id, 40 fresh input tokens beside 1,200 cached ones. */
    public static function reply(string $text, string $model, string $stopReason = 'end_turn'): string
    {
        return 'data: {"type":"message_start","message":{"model":'.json_encode($model, JSON_THROW_ON_ERROR).',"usage":{"input_tokens":40,"cache_read_input_tokens":1000,"cache_creation_input_tokens":200}}}'."\n\n"
            ."data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n"
            .'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":'.json_encode($text, JSON_THROW_ON_ERROR)."}}\n\n"
            ."data: {\"type\":\"content_block_stop\",\"index\":0}\n\n"
            .'data: {"type":"message_delta","delta":{"stop_reason":'.json_encode($stopReason, JSON_THROW_ON_ERROR).'},"usage":{"output_tokens":12}}'."\n\n"
            ."data: {\"type\":\"message_stop\"}\n\n";
    }

    public static function thinkingThenReply(string $thinking, string $text, string $model): string
    {
        return 'data: {"type":"message_start","message":{"model":'.json_encode($model, JSON_THROW_ON_ERROR).',"usage":{"input_tokens":40}}}'."\n\n"
            ."data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"thinking\",\"thinking\":\"\"}}\n\n"
            .'data: {"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":'.json_encode($thinking, JSON_THROW_ON_ERROR)."}}\n\n"
            ."data: {\"type\":\"content_block_stop\",\"index\":0}\n\n"
            ."data: {\"type\":\"content_block_start\",\"index\":1,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n"
            .'data: {"type":"content_block_delta","index":1,"delta":{"type":"text_delta","text":'.json_encode($text, JSON_THROW_ON_ERROR)."}}\n\n"
            ."data: {\"type\":\"content_block_stop\",\"index\":1}\n\n"
            ."data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":12}}\n\n"
            ."data: {\"type\":\"message_stop\"}\n\n";
    }

    /** One completed generation step that calls the given tool with no input. */
    public static function toolUseStep(string $tool): string
    {
        return self::MESSAGE_START
            .'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_step_1","name":'.json_encode($tool, JSON_THROW_ON_ERROR).",\"input\":{}}}\n\n"
            ."data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"input_json_delta\",\"partial_json\":\"{}\"}}\n\n"
            ."data: {\"type\":\"content_block_stop\",\"index\":0}\n\n"
            ."data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"tool_use\"},\"usage\":{\"output_tokens\":3}}\n\n"
            ."data: {\"type\":\"message_stop\"}\n\n";
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function toolUseStepWithInput(string $tool, array $input): string
    {
        return self::MESSAGE_START
            .'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_step_1","name":'.json_encode($tool, JSON_THROW_ON_ERROR).",\"input\":{}}}\n\n"
            .'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":'.json_encode(json_encode($input, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR)."}}\n\n"
            ."data: {\"type\":\"content_block_stop\",\"index\":0}\n\n"
            ."data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"tool_use\"},\"usage\":{\"output_tokens\":3}}\n\n"
            ."data: {\"type\":\"message_stop\"}\n\n";
    }

    /**
     * A research reply recorded from claude-sonnet-5 on 2026-11-01 and trimmed: one
     * server-side web_search per query, one web_fetch per url, then a text block
     * citing the first search result.
     *
     * @param  list<string>  $queries
     * @param  list<string>  $fetchUrls
     */
    public static function webResearchReply(array $queries, string $text, string $citedUrl, string $citedTitle, array $fetchUrls = [], string $model = 'claude-sonnet-5', string $stopReason = 'end_turn'): string
    {
        $frames = 'data: {"type":"message_start","message":{"model":'.json_encode($model, JSON_THROW_ON_ERROR).',"usage":{"input_tokens":40}}}'."\n\n";
        $index = 0;

        foreach ($queries as $query) {
            $frames .= self::serverToolFrames($index, 'web_search', ['query' => $query], [
                'type' => 'web_search_tool_result',
                'content' => [['type' => 'web_search_result', 'title' => $citedTitle, 'url' => $citedUrl, 'encrypted_content' => 'x']],
            ]);
            $index += 2;
        }

        foreach ($fetchUrls as $url) {
            $frames .= self::serverToolFrames($index, 'web_fetch', ['url' => $url], [
                'type' => 'web_fetch_tool_result',
                'content' => ['type' => 'web_fetch_result', 'url' => $url, 'content' => ['type' => 'document', 'title' => null]],
            ]);
            $index += 2;
        }

        $citation = ['type' => 'web_search_result_location', 'cited_text' => $text, 'url' => $citedUrl, 'title' => $citedTitle, 'encrypted_index' => 'x'];

        return $frames
            .'data: {"type":"content_block_start","index":'.$index.',"content_block":{"citations":[],"type":"text","text":""}}'."\n\n"
            .'data: {"type":"content_block_delta","index":'.$index.',"delta":{"type":"citations_delta","citation":'.json_encode($citation, JSON_THROW_ON_ERROR).'}}'."\n\n"
            .'data: {"type":"content_block_delta","index":'.$index.',"delta":{"type":"text_delta","text":'.json_encode($text, JSON_THROW_ON_ERROR).'}}'."\n\n"
            .'data: {"type":"content_block_stop","index":'.$index.'}'."\n\n"
            .'data: {"type":"message_delta","delta":{"stop_reason":'.json_encode($stopReason, JSON_THROW_ON_ERROR).'},"usage":{"output_tokens":12}}'."\n\n"
            ."data: {\"type\":\"message_stop\"}\n\n";
    }

    /**
     * @param  array<string, string>  $input
     * @param  array<string, mixed>  $result
     */
    private static function serverToolFrames(int $index, string $name, array $input, array $result): string
    {
        $id = "srvtoolu_{$name}_{$index}";
        $resultIndex = $index + 1;

        return 'data: {"type":"content_block_start","index":'.$index.',"content_block":{"type":"server_tool_use","id":"'.$id.'","name":"'.$name.'","input":{}}}'."\n\n"
            .'data: {"type":"content_block_delta","index":'.$index.',"delta":{"type":"input_json_delta","partial_json":'.json_encode(json_encode($input, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR).'}}'."\n\n"
            .'data: {"type":"content_block_stop","index":'.$index.'}'."\n\n"
            .'data: {"type":"content_block_start","index":'.$resultIndex.',"content_block":'.json_encode(['tool_use_id' => $id, ...$result], JSON_THROW_ON_ERROR).'}'."\n\n"
            .'data: {"type":"content_block_stop","index":'.$resultIndex.'}'."\n\n";
    }

    public static function fake(string $body): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
        ]);
    }
}
