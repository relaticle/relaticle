<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Ai\Responses\Data\ToolCall as DataToolCall;
use Laravel\Ai\Responses\Data\ToolResult as DataToolResult;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Relaticle\Chat\Support\StreamEventBroadcaster;

it('skips read-tool results entirely', function (): void {
    $dataToolResult = new DataToolResult(
        id: 'tool-id-1',
        name: 'ListTasksTool',
        arguments: [],
        result: json_encode(['tasks' => array_fill(0, 40, ['id' => 1, 'name' => 'task'])]),
    );

    $event = new ToolResult(
        id: 'evt-1',
        toolResult: $dataToolResult,
        successful: true,
        error: null,
        timestamp: time(),
    );

    $payload = StreamEventBroadcaster::payloadFor($event);

    expect($payload)->toBeNull();
});

it('strips the heavy data echo from pending_action results', function (): void {
    $resultJson = json_encode([
        'type' => 'pending_action',
        'pending_action_id' => 'pa-abc-123',
        'display' => ['summary' => 'Create task "Write tests"'],
        'data' => array_fill(0, 100, ['heavy' => str_repeat('x', 500)]),
    ]);

    $dataToolResult = new DataToolResult(
        id: 'tool-id-2',
        name: 'CreateTaskTool',
        arguments: [],
        result: $resultJson,
    );

    $event = new ToolResult(
        id: 'evt-2',
        toolResult: $dataToolResult,
        successful: true,
        error: null,
        timestamp: time(),
    );

    $payload = StreamEventBroadcaster::payloadFor($event);

    expect($payload)->not->toBeNull();
    expect($payload['as'])->toBe('tool_result');

    $result = json_decode($payload['with']['result'], true);

    expect($result)->not->toHaveKey('data');
    expect($result['pending_action_id'])->toBe('pa-abc-123');
    expect($result['display']['summary'])->toBe('Create task "Write tests"');
});

it('slims tool_call events to name and invocation', function (): void {
    $dataToolCall = new DataToolCall(
        id: 'tool-id-3',
        name: 'CreateTaskTool',
        arguments: ['name' => 'Write tests', 'extra' => str_repeat('x', 2000)],
    );

    $event = new ToolCall(
        id: 'evt-3',
        toolCall: $dataToolCall,
        timestamp: time(),
    );

    $event->withInvocationId('inv-1');

    $payload = StreamEventBroadcaster::payloadFor($event);

    expect($payload)->toBe([
        'as' => 'tool_call',
        'with' => [
            'type' => 'tool_call',
            'invocation_id' => 'inv-1',
            'tool_name' => 'CreateTaskTool',
        ],
    ]);
});

it('passes other events through unchanged', function (): void {
    $textDelta = new TextDelta(
        id: 'evt-4',
        messageId: 'msg-1',
        delta: 'Hello',
        timestamp: time(),
    );

    $payload = StreamEventBroadcaster::payloadFor($textDelta);

    expect($payload)->not->toBeNull();
    expect($payload['as'])->toBe('text_delta');
    expect($payload['with'])->toBe($textDelta->toArray());

    $streamStart = new StreamStart(
        id: 'evt-5',
        provider: 'anthropic',
        model: 'claude-3-5-sonnet',
        timestamp: time(),
    );

    $payload = StreamEventBroadcaster::payloadFor($streamStart);

    expect($payload)->not->toBeNull();
    expect($payload['as'])->toBe('stream_start');
    expect($payload['with'])->toBe($streamStart->toArray());
});

it('swallows BroadcastException and does not rethrow', function (): void {
    $pending = Mockery::mock();
    $pending->shouldReceive('as')->andReturnSelf();
    $pending->shouldReceive('with')->andReturnSelf();
    $pending->shouldReceive('sendNow')->andThrow(new BroadcastException('Payload too large'));

    Broadcast::shouldReceive('on')->andReturn($pending);

    $channel = new PrivateChannel('chat.conversation.test-id');
    $broadcaster = new StreamEventBroadcaster($channel);

    $event = new TextDelta(
        id: 'evt-6',
        messageId: 'msg-2',
        delta: 'chunk',
        timestamp: time(),
    );

    expect(fn () => $broadcaster->broadcast($event))->not->toThrow(BroadcastException::class);
});
