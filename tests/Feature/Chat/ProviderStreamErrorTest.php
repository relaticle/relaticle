<?php

declare(strict_types=1);

use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Streaming\Events\Error;
use Relaticle\Chat\Support\ProviderStreamError;

it('maps overload and rate-limit stream errors to a retryable exception', function (string $type): void {
    $event = new Error('evt-1', $type, 'provider says no', false, time());

    expect(ProviderStreamError::toException($event))
        ->toBeInstanceOf(ProviderOverloadedException::class);
})->with(['overloaded_error', 'rate_limit_error']);

it('maps unknown stream errors to a non-retryable runtime exception', function (): void {
    $event = new Error('evt-1', 'invalid_request_error', 'bad request', false, time());

    $exception = ProviderStreamError::toException($event);

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toContain('invalid_request_error')
        ->and($exception->getMessage())->toContain('bad request');
});
