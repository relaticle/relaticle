<?php

declare(strict_types=1);

use App\Health\ChatProviderCheck;
use Illuminate\Support\Facades\Http;
use Spatie\Health\Checks\Check;
use Spatie\Health\Enums\Status;

mutates(ChatProviderCheck::class);

/**
 * @return array<string, mixed>
 */
function chatProviderCatalogueEntry(
    string $provider,
    string $model,
    string $minPlan = 'free',
    bool $supportsTools = true,
    bool $selfHosted = false,
): array {
    return [
        'id' => $model,
        'label' => $model,
        'provider' => $provider,
        'model' => $model,
        'min_plan' => $minPlan,
        'credit_multiplier' => 1.0,
        'supports_tools' => $supportsTools,
        'write_guard' => 'api',
        'self_hosted' => $selfHosted,
    ];
}

function configuredAnthropicCheck(): ChatProviderCheck
{
    config()->set('chat.models', [chatProviderCatalogueEntry('anthropic', 'claude-sonnet-4-6')]);
    config()->set('ai.providers.anthropic.key', 'test-key');

    return ChatProviderCheck::forConfiguredProviders()[0];
}

it('only warns when a provider is overloaded', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
        ], 529),
    ]);

    expect(configuredAnthropicCheck()->run()->status)->toBe(Status::warning());
});

it('only warns when a provider rate limits us', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limited'],
        ], 429),
    ]);

    expect(configuredAnthropicCheck()->run()->status)->toBe(Status::warning());
});

it('fails when the model is no longer available', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'not_found_error', 'message' => 'model: claude-sonnet-4-6'],
        ], 404),
    ]);

    expect(configuredAnthropicCheck()->run()->status)->toBe(Status::failed());
});

it('fails when the api key is rejected', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
        ], 401),
    ]);

    expect(configuredAnthropicCheck()->run()->status)->toBe(Status::failed());
});

it('passes when the provider responds', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 1],
        ]),
    ]);

    expect(configuredAnthropicCheck()->run()->status)->toBe(Status::ok());
});

it('registers one check per provider that has a key configured', function (): void {
    config()->set('chat.models', [
        chatProviderCatalogueEntry('anthropic', 'claude-sonnet-4-6'),
        chatProviderCatalogueEntry('openai', 'gpt-5.5', minPlan: 'pro'),
    ]);
    config()->set('ai.providers.anthropic.key', 'test-key');
    config()->set('ai.providers.openai.key', null);

    expect(ChatProviderCheck::forConfiguredProviders())->toHaveCount(1);

    config()->set('ai.providers.openai.key', 'test-key');

    $names = array_map(
        static fn (Check $check): string => $check->getName(),
        ChatProviderCheck::forConfiguredProviders(),
    );

    expect($names)->toBe(['Chat provider: anthropic', 'Chat provider: openai']);
});

it('skips models chat can never select', function (): void {
    config()->set('chat.models', [
        chatProviderCatalogueEntry('gemini', 'gemini-3-flash', supportsTools: false),
        chatProviderCatalogueEntry('ollama', 'llama', selfHosted: true),
    ]);
    config()->set('ai.providers.gemini.key', 'test-key');
    config()->set('ai.providers.ollama.key', 'test-key');

    expect(ChatProviderCheck::forConfiguredProviders())->toBeEmpty();
});

it('prefers the model available on every plan', function (): void {
    Http::fake();

    config()->set('chat.models', [
        chatProviderCatalogueEntry('anthropic', 'claude-opus-4-7', minPlan: 'pro'),
        chatProviderCatalogueEntry('anthropic', 'claude-sonnet-4-6'),
    ]);
    config()->set('ai.providers.anthropic.key', 'test-key');

    $result = ChatProviderCheck::forConfiguredProviders()[0]->run();

    expect($result->meta['model'])->toBe('claude-sonnet-4-6');
});
