<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Relaticle\Chat\Services\ModelProbe;

mutates(ModelProbe::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    resolve(ModelProbe::class)->forget('anthropic', 'claude-sonnet-5');
});

it('reports tool support and the api write guard when the provider accepts everything', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg_1', 'content' => [], 'usage' => []])]);

    expect(resolve(ModelProbe::class)('anthropic', 'claude-sonnet-5'))
        ->toBe(['ok' => true, 'error' => null, 'supports_tools' => true, 'write_guard' => 'api']);
});

/**
 * The safe direction. Claiming `api` without proof tells the write path the provider
 * refuses parallel tool calls, which is what the sequential approval flow leans on.
 */
it('claims nothing and surfaces the provider message when rejected', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'type' => 'error',
        'error' => ['message' => '`temperature` is deprecated for this model.'],
    ], 400)]);

    $result = resolve(ModelProbe::class)('anthropic', 'claude-sonnet-5');

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('temperature')
        ->and($result['supports_tools'])->toBeFalse()
        ->and($result['write_guard'])->toBe('prompt');
});

it('does not re-send a request for a pairing that already passed', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg_1', 'content' => [], 'usage' => []])]);

    $probe = resolve(ModelProbe::class);
    $probe('anthropic', 'claude-sonnet-5');
    $probe('anthropic', 'claude-sonnet-5');

    Http::assertSentCount(1);
});

it('retries a failure rather than caching it', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

    $probe = resolve(ModelProbe::class);
    $probe('anthropic', 'claude-sonnet-5');
    $probe('anthropic', 'claude-sonnet-5');

    expect(Http::recorded())->toHaveCount(2);
});
