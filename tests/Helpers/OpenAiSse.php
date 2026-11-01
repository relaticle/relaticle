<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Illuminate\Support\Facades\Http;

/**
 * Canned OpenAI Responses API streams, recorded from gpt-5.5 on 2026-11-01 and
 * trimmed. A web_search_call reports completion twice, once as
 * `response.web_search_call.completed` and once as `response.output_item.done`,
 * exactly as the live stream does.
 */
final class OpenAiSse
{
    /**
     * @param  list<array{type: 'search'|'open_page', value: string}>  $calls
     */
    public static function webResearchReply(array $calls, string $text, string $citedUrl, string $citedTitle, string $model = 'gpt-5.5'): string
    {
        $events = [['type' => 'response.created', 'response' => ['id' => 'resp_1', 'status' => 'in_progress', 'model' => $model, 'output' => []]]];
        $output = [];

        foreach ($calls as $position => $call) {
            $item = ['id' => "ws_{$position}", 'type' => 'web_search_call', 'status' => 'completed', 'action' => $call['type'] === 'search'
                ? ['type' => 'search', 'queries' => [$call['value']], 'query' => $call['value']]
                : ['type' => 'open_page', 'url' => $call['value']]];

            $events[] = ['type' => 'response.output_item.added', 'output_index' => $position, 'item' => ['id' => $item['id'], 'type' => 'web_search_call', 'status' => 'in_progress']];
            $events[] = ['type' => 'response.web_search_call.in_progress', 'output_index' => $position, 'item_id' => $item['id']];
            $events[] = ['type' => 'response.web_search_call.completed', 'output_index' => $position, 'item_id' => $item['id']];
            $events[] = ['type' => 'response.output_item.done', 'output_index' => $position, 'item' => $item];
            $output[] = $item;
        }

        $messageIndex = count($calls);
        $annotation = ['type' => 'url_citation', 'start_index' => 0, 'end_index' => mb_strlen($text), 'title' => $citedTitle, 'url' => $citedUrl];
        $message = ['id' => 'msg_1', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'annotations' => [$annotation], 'text' => $text]]];

        $events[] = ['type' => 'response.output_item.added', 'output_index' => $messageIndex, 'item' => ['id' => 'msg_1', 'type' => 'message', 'status' => 'in_progress', 'role' => 'assistant', 'content' => []]];
        $events[] = ['type' => 'response.content_part.added', 'output_index' => $messageIndex, 'item_id' => 'msg_1', 'content_index' => 0, 'part' => ['type' => 'output_text', 'annotations' => [], 'text' => '']];
        $events[] = ['type' => 'response.output_text.delta', 'output_index' => $messageIndex, 'item_id' => 'msg_1', 'content_index' => 0, 'delta' => $text];
        $events[] = ['type' => 'response.output_text.annotation.added', 'output_index' => $messageIndex, 'item_id' => 'msg_1', 'content_index' => 0, 'annotation_index' => 0, 'annotation' => $annotation];
        $events[] = ['type' => 'response.output_text.done', 'output_index' => $messageIndex, 'item_id' => 'msg_1', 'content_index' => 0, 'text' => $text];
        $events[] = ['type' => 'response.output_item.done', 'output_index' => $messageIndex, 'item' => $message];
        $events[] = ['type' => 'response.completed', 'response' => [
            'id' => 'resp_1',
            'status' => 'completed',
            'model' => $model,
            'output' => [...$output, $message],
            'usage' => ['input_tokens' => 40, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 12, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 52],
        ]];

        return implode('', array_map(
            static fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event, JSON_THROW_ON_ERROR)."\n\n",
            $events,
        ));
    }

    public static function fake(string $body): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
        ]);
    }
}
