<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * Anthropic's Messages API rejects `tool_use.input` when it is a JSON array, and
 * empty tool arguments round-tripped through PHP arrays serialise as `[]`. We used
 * to patch the gateway ourselves; laravel/ai now coerces empty input to an object
 * on both mapping paths. These cases guard that upstream behaviour so the patch
 * does not have to come back silently.
 */
it('encodes empty tool_use input as JSON object, not array', function (): void {
    $gateway = new AnthropicGateway(new Dispatcher);

    $messages = [
        new UserMessage('summary please'),
        new AssistantMessage('ok', collect([
            new ToolCall(
                id: 'toolu_test_id',
                name: 'GetCrmSummaryTool',
                arguments: [],
                resultId: 'toolu_test_id',
            ),
        ])),
        new ToolResultMessage(collect([
            new ToolResult(
                id: 'toolu_test_id',
                name: 'GetCrmSummaryTool',
                arguments: [],
                result: '{"counts":{}}',
                resultId: 'toolu_test_id',
            ),
        ])),
        new UserMessage('thanks'),
    ];

    $reflection = new ReflectionMethod($gateway, 'mapMessages');
    $reflection->setAccessible(true);

    /** @var array<int, array{role: string, content: array<int, array<string, mixed>>}> $mapped */
    $mapped = $reflection->invoke($gateway, $messages);

    $assistantMessage = collect($mapped)
        ->firstOrFail(fn (array $m): bool => $m['role'] === 'assistant');

    $toolUseBlock = collect($assistantMessage['content'])
        ->firstOrFail(fn (array $b): bool => ($b['type'] ?? '') === 'tool_use');

    expect($toolUseBlock['input'])->toBeInstanceOf(stdClass::class);
    expect(json_encode($toolUseBlock['input']))->toBe('{}');
});

it('encodes empty tool_use input as JSON object when replaying provider content blocks', function (): void {
    $gateway = new AnthropicGateway(new Dispatcher);

    $message = new AssistantMessage('ok', collect([
        new ToolCall('toolu_test_id', 'GetCrmSummaryTool', [], 'toolu_test_id'),
    ]));

    $message->providerContentBlocks = [
        ['type' => 'text', 'text' => 'ok'],
        ['type' => 'tool_use', 'id' => 'toolu_test_id', 'name' => 'GetCrmSummaryTool', 'input' => []],
    ];

    $reflection = new ReflectionMethod($gateway, 'mapMessages');
    $reflection->setAccessible(true);

    /** @var array<int, array{role: string, content: array<int, array<string, mixed>>}> $mapped */
    $mapped = $reflection->invoke($gateway, [$message]);

    $toolUseBlock = collect($mapped)
        ->firstOrFail(fn (array $m): bool => $m['role'] === 'assistant')['content'];

    $toolUse = collect($toolUseBlock)
        ->firstOrFail(fn (array $b): bool => ($b['type'] ?? '') === 'tool_use');

    expect(json_encode($toolUse['input']))->toBe('{}');
});

it('preserves non-empty tool_use input as a dictionary', function (): void {
    $gateway = new AnthropicGateway(new Dispatcher);

    $messages = [
        new UserMessage('list tasks'),
        new AssistantMessage('ok', collect([
            new ToolCall(
                id: 'toolu_test_id',
                name: 'ListTasksTool',
                arguments: ['search' => 'todo', 'page' => 2],
                resultId: 'toolu_test_id',
            ),
        ])),
        new ToolResultMessage(collect([
            new ToolResult(
                id: 'toolu_test_id',
                name: 'ListTasksTool',
                arguments: ['search' => 'todo', 'page' => 2],
                result: '[]',
                resultId: 'toolu_test_id',
            ),
        ])),
    ];

    $reflection = new ReflectionMethod($gateway, 'mapMessages');
    $reflection->setAccessible(true);

    /** @var array<int, array{role: string, content: array<int, array<string, mixed>>}> $mapped */
    $mapped = $reflection->invoke($gateway, $messages);

    $toolUseBlock = collect($mapped)
        ->firstOrFail(fn (array $m): bool => $m['role'] === 'assistant')['content'];

    $toolUse = collect($toolUseBlock)
        ->firstOrFail(fn (array $b): bool => ($b['type'] ?? '') === 'tool_use');

    expect(json_encode($toolUse['input']))->toBe('{"search":"todo","page":2}');
});

it('produces a request body Anthropic accepts as valid JSON dict for empty arguments', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'api.anthropic.com/*' => Http::response("event: message_start\ndata: {}\n\n", 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $gateway = new AnthropicGateway(new Dispatcher);

    $reflection = new ReflectionMethod($gateway, 'mapMessages');
    $reflection->setAccessible(true);

    $mapped = $reflection->invoke($gateway, [
        new AssistantMessage('', collect([
            new ToolCall('toolu_x', 'GetCrmSummaryTool', [], 'toolu_x'),
        ])),
    ]);

    $payload = ['model' => 'claude-haiku-4-5', 'messages' => $mapped, 'max_tokens' => 64];
    $json = json_encode($payload);

    expect($json)->toContain('"input":{}');
    expect($json)->not->toContain('"input":[]');
});
