<?php

declare(strict_types=1);

use App\Health\AnthropicModelCheck;
use Illuminate\Support\Facades\Http;
use Spatie\Health\Enums\Status;

mutates(AnthropicModelCheck::class);

it('only warns when anthropic is overloaded', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
        ], 529),
    ]);

    expect(AnthropicModelCheck::new()->run()->status)->toBe(Status::warning());
});

it('only warns when anthropic rate limits us', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limited'],
        ], 429),
    ]);

    expect(AnthropicModelCheck::new()->run()->status)->toBe(Status::warning());
});

it('fails when the model is no longer available', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'not_found_error', 'message' => 'model: claude-sonnet-4-6'],
        ], 404),
    ]);

    expect(AnthropicModelCheck::new()->run()->status)->toBe(Status::failed());
});

it('fails when the api key is rejected', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
        ], 401),
    ]);

    expect(AnthropicModelCheck::new()->run()->status)->toBe(Status::failed());
});

it('passes when anthropic responds', function (): void {
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

    expect(AnthropicModelCheck::new()->run()->status)->toBe(Status::ok());
});
