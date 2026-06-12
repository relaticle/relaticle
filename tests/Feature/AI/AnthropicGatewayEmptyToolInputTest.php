<?php

declare(strict_types=1);

use App\Ai\AiManager;
use App\Ai\Anthropic\AnthropicGateway as PatchedAnthropicGateway;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager as BaseAiManager;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

mutates(PatchedAnthropicGateway::class);
mutates(AiManager::class);

it('binds our patched manager so the Anthropic driver uses our gateway', function (): void {
    $manager = app(BaseAiManager::class);

    expect($manager)->toBeInstanceOf(AiManager::class);

    $provider = $manager->textProvider('anthropic');

    $reflection = new ReflectionClass($provider);
    $property = $reflection->getParentClass()->getProperty('gateway');
    $property->setAccessible(true);

    expect($property->getValue($provider))->toBeInstanceOf(PatchedAnthropicGateway::class);
});

it('encodes empty tool_use input as JSON object, not array', function (): void {
    $gateway = new PatchedAnthropicGateway(new Dispatcher);

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

it('preserves non-empty tool_use input as a dictionary', function (): void {
    $gateway = new PatchedAnthropicGateway(new Dispatcher);

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

    $gateway = new PatchedAnthropicGateway(new Dispatcher);

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
