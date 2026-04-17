<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Relaticle\ActivityLog\Timeline\Window;

it('constructs with nullable bounds and required cap', function (): void {
    $window = new Window(from: null, to: null, cap: 50);

    expect($window->from)->toBeNull()
        ->and($window->to)->toBeNull()
        ->and($window->cap)->toBe(50)
        ->and($window->typeAllow)->toBeNull()
        ->and($window->typeDeny)->toBeNull()
        ->and($window->eventAllow)->toBeNull()
        ->and($window->eventDeny)->toBeNull();
});

it('preserves filter arrays', function (): void {
    $window = new Window(
        from: CarbonImmutable::parse('2026-01-01'),
        to: CarbonImmutable::parse('2026-02-01'),
        cap: 10,
        typeAllow: ['activity_log'],
        eventDeny: ['email_received'],
    );

    expect($window->typeAllow)->toBe(['activity_log'])
        ->and($window->eventDeny)->toBe(['email_received']);
});
