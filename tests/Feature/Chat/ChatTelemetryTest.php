<?php

declare(strict_types=1);

use Relaticle\Chat\Support\ChatTelemetry;

it('does not throw when adding breadcrumb', function (): void {
    expect(fn () => ChatTelemetry::breadcrumb('test'))->not->toThrow(Throwable::class);
});

it('does not throw when adding breadcrumb with metadata', function (): void {
    expect(fn () => ChatTelemetry::breadcrumb('job.started', ['message_length' => 42]))
        ->not->toThrow(Throwable::class);
});

it('does not throw when tagging scope', function (): void {
    expect(fn () => ChatTelemetry::tagCurrentScope('c', 't', 'm'))->not->toThrow(Throwable::class);
});
