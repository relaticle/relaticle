<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

final readonly class StreamEventBroadcaster
{
    public function __construct(
        private Channel $channel,
    ) {}

    /**
     * Broadcast a stream event over the channel, applying payload discipline.
     *
     * Read-tool results are silently dropped. Pending-action results have their
     * heavy `data` echo stripped. ToolCall events are slimmed to name + invocation.
     * BroadcastException (Reverb 10 KB cap) is swallowed and recorded as a
     * telemetry breadcrumb so it does not kill the whole streaming turn.
     */
    public function broadcast(StreamEvent $event): void
    {
        $payload = self::payloadFor($event);

        if ($payload === null) {
            return;
        }

        try {
            Broadcast::on($this->channel)
                ->as($payload['as'])
                ->with($payload['with'])
                ->sendNow();
        } catch (BroadcastException $e) {
            ChatTelemetry::breadcrumb('stream.broadcast_dropped', [
                'event_type' => $event->type(),
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Compute the slim broadcast payload for the given event.
     *
     * Returns null when the event should be dropped entirely (read-tool results).
     *
     * @return array{as: string, with: array<string, mixed>}|null
     */
    public static function payloadFor(StreamEvent $event): ?array
    {
        if ($event instanceof ToolResult) {
            return self::payloadForToolResult($event);
        }

        if ($event instanceof ToolCall) {
            return self::payloadForToolCall($event);
        }

        // The abstract StreamEvent doesn't declare toArray(); every concrete
        // laravel/ai event implements it (type() depends on it internally).
        if (! method_exists($event, 'toArray')) {
            return null;
        }

        return [
            'as' => $event->type(),
            'with' => $event->toArray(),
        ];
    }

    /**
     * @return array{as: string, with: array<string, mixed>}|null
     */
    private static function payloadForToolResult(ToolResult $event): ?array
    {
        $result = $event->toolResult->result;
        $raw = is_string($result) ? $result : json_encode($result);

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded) || ($decoded['type'] ?? null) !== 'pending_action') {
            return null;
        }

        unset($decoded['data']);

        return [
            'as' => 'tool_result',
            'with' => [
                'id' => $event->id,
                'invocation_id' => $event->invocationId,
                'type' => 'tool_result',
                'tool_id' => $event->toolResult->id,
                'tool_name' => $event->toolResult->name,
                'result' => json_encode($decoded),
                'successful' => $event->successful,
                'error' => $event->error,
                'timestamp' => $event->timestamp,
            ],
        ];
    }

    /**
     * @return array{as: string, with: array<string, mixed>}
     */
    private static function payloadForToolCall(ToolCall $event): array
    {
        return [
            'as' => 'tool_call',
            'with' => [
                'type' => 'tool_call',
                'invocation_id' => $event->invocationId,
                'tool_name' => $event->toolCall->name,
            ],
        ];
    }
}
