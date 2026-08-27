<?php

declare(strict_types=1);

use App\Health\ChatProviderCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
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

function configuredCheck(string $provider, string $model): ChatProviderCheck
{
    config()->set('chat.models', [chatProviderCatalogueEntry($provider, $model)]);
    config()->set("ai.providers.{$provider}.key", 'test-key');

    return ChatProviderCheck::forConfiguredProviders()[0];
}

function configuredAnthropicCheck(): ChatProviderCheck
{
    return configuredCheck('anthropic', 'claude-sonnet-4-6');
}

it('retrieves the model from anthropic with the credentials a chat turn uses', function (): void {
    Http::fake();

    configuredAnthropicCheck()->run();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.anthropic.com/v1/models/claude-sonnet-4-6'
        && $request->hasHeader('x-api-key', 'test-key')
        && $request->hasHeader('anthropic-version', '2023-06-01'));
});

it('retrieves the model from openai with the credentials a chat turn uses', function (): void {
    Http::fake();

    configuredCheck('openai', 'gpt-5.5')->run();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.openai.com/v1/models/gpt-5.5'
        && $request->hasHeader('Authorization', 'Bearer test-key'));
});

it('never sends a generation request', function (): void {
    Http::fake();

    configuredCheck('openai', 'gpt-5.5')->run();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        || array_key_exists('max_output_tokens', $request->data())
        || array_key_exists('max_tokens', $request->data()));
});

it('honours a provider base url override', function (): void {
    Http::fake();

    config()->set('ai.providers.openai.url', 'https://gateway.internal/openai/v1/');

    configuredCheck('openai', 'gpt-5.5')->run();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gateway.internal/openai/v1/models/gpt-5.5');
});

it('passes when the provider serves the model', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'gpt-5.5', 'object' => 'model']),
    ]);

    expect(configuredCheck('openai', 'gpt-5.5')->run()->status)->toBe(Status::ok());
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

it('only warns when a provider is overloaded', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
        ], 529),
    ]);

    expect(configuredAnthropicCheck()->run()->status)->toBe(Status::warning());
});

it('only warns when a provider cannot be reached', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(configuredAnthropicCheck()->run()->status)->toBe(Status::warning());
});

it('fails when the model is no longer available', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'message' => "The model 'gpt-5.5' does not exist"],
        ], 404),
    ]);

    $result = configuredCheck('openai', 'gpt-5.5')->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->notificationMessage)->toContain("The model 'gpt-5.5' does not exist");
});

it('fails when the api key is rejected', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'message' => 'Incorrect API key provided'],
        ], 401),
    ]);

    expect(configuredCheck('openai', 'gpt-5.5')->run()->status)->toBe(Status::failed());
});

it('fails loudly rather than silently passing a provider it cannot probe', function (): void {
    Http::fake();

    $result = configuredCheck('groq', 'llama-4-scout')->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->notificationMessage)->toContain('no health probe is defined');

    Http::assertNothingSent();
});

it('can probe every provider the real catalogue is able to register', function (): void {
    Http::fake();

    /** @var array<int, array<string, mixed>> $catalogue */
    $catalogue = config('chat.models', []);

    foreach ($catalogue as $entry) {
        config()->set("ai.providers.{$entry['provider']}.key", 'test-key');
    }

    $checks = ChatProviderCheck::forConfiguredProviders();

    expect($checks)->not->toBeEmpty();

    foreach ($checks as $check) {
        expect($check->run()->status)->toBe(Status::ok());
    }
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
