<?php

declare(strict_types=1);

use Relaticle\Chat\Support\LikePattern;

mutates(LikePattern::class);

it('escapes percent, underscore, and backslash for ilike use', function (): void {
    expect(LikePattern::escape('100%_a\\b'))->toBe('100\\%\\_a\\\\b');
});

it('leaves clean input untouched', function (): void {
    expect(LikePattern::escape('acme inc'))->toBe('acme inc');
});
